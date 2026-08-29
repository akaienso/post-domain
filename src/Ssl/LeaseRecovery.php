<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;

/**
 * The only claimant of expired leases, and the only work selector in the plugin
 * that finds rows by lease expiry or by a recovery re-read schedule.
 *
 * No event is written here except as part of a CAS this worker won.
 */
final class LeaseRecovery {

	public function __construct(
		private readonly MutationLease $lease,
		private readonly MappingRepository $repo,
		private readonly Clock $clock
	) {}

	/**
	 * Two kinds of due work, and no other component may select either:
	 *
	 * 1. an expired lease of any phase — a takeover, which replaces the token;
	 * 2. a still-owned RECOVERING lease whose scheduled bounded re-read has come
	 *    due — a continuation, which keeps the existing token.
	 *
	 * Without (2) an inconclusive recovery would have no durable way back: it
	 * would have to let its own lease expire and be taken over, which loses the
	 * attempt count and defeats the backoff.
	 *
	 * @return Mapping[]
	 */
	public function due( int $batch ): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE ssl_mutation_token IS NOT NULL
				    AND (
				          ssl_mutation_expires_at <= %s
				          OR ( ssl_mutation_phase = %s
				               AND ssl_next_attempt_at IS NOT NULL
				               AND ssl_next_attempt_at <= %s )
				        )
				  ORDER BY ssl_mutation_expires_at ASC
				  LIMIT %d",
				$this->clock->mysql(),
				MutationPhase::RECOVERING->value,
				$this->clock->mysql(),
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	/**
	 * @return string One of: cleared, resolved, deleted, still_recovering,
	 *                blocked, fenced, deferred, skipped.
	 *
	 * `blocked` means the bound provider environment is unavailable, so nothing
	 * was asked of any provider. `fenced` means another owner took the row.
	 * `deferred` means the database refused the write — not a fencing event, and
	 * never reported as one.
	 */
	public function recover( Mapping $mapping, RecoveryResolver $resolver ): string {
		$token = $mapping->ssl_mutation_token;
		$phase = $mapping->ssl_mutation_phase;
		$kind  = $mapping->ssl_mutation_kind;

		if ( null === $token || null === $phase || null === $kind ) {
			return 'skipped';
		}

		$expired = $this->is_expired( $mapping );

		$snapshot = $this->owner_from( $mapping );

		if ( null === $snapshot ) {
			// A lease without its binding is not a lease the repository would have
			// accepted; leave it for an operator rather than guessing.
			return 'skipped';
		}

		if ( MutationPhase::RESERVED === $phase ) {
			if ( ! $expired ) {
				return 'skipped';
			}

			// Nothing was sent: clear without contacting the provider, and record
			// the clearance only if the owner-pinned CAS actually won.
			$cleared = AtomicTransition::commit(
				fn (): bool => $this->lease->clear_expired_reserved( $snapshot ),
				fn (): bool => EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$phase->value,
					'lease_cleared',
					'cron',
					array(
						'kind' => $kind->value,
						'note' => 'expired reservation; nothing was sent',
					)
				)
			);

			if ( $cleared->committed() ) {
				return 'cleared';
			}

			// A lost CAS means someone else cleared it; anything else means the
			// write is not known to have landed. The next pass re-reads rather
			// than assuming either way.
			return $cleared->cas_lost() ? 'skipped' : 'deferred';
		}

		if ( $expired ) {
			// Takeover: fence the previous owner before any provider read, pinning
			// the kind, the exact phase, and the durable binding this row was
			// selected with. A snapshot that has moved matches nothing.
			$owner = $this->lease->claim_recovery( $snapshot );

			if ( null === $owner ) {
				return 'skipped';
			}

			$attempt = MutationPhase::RECOVERING === $phase ? $mapping->ssl_transient_count : 0;
		} else {
			// Continuation: this worker still owns the recovery lease and its
			// scheduled re-read has come due. No token replacement, no fencing.
			if ( MutationPhase::RECOVERING !== $phase || ! $this->reread_due( $mapping ) ) {
				return 'skipped';
			}

			$owner   = $snapshot;
			$attempt = $mapping->ssl_transient_count;
		}

		$current = $this->repo->by_id( $mapping->id );

		if ( null === $current ) {
			return 'skipped';
		}

		// The driver comes from the lease, never from current configuration. A
		// mutation that began against one account must not be interrogated in
		// another: an "absent" from the wrong place is the answer that would clear
		// the lease and permit a duplicate (spec §12.6).
		$bound = $this->bound_driver( $current );

		if ( is_string( $bound ) ) {
			return $this->stay_fenced( $current, $phase, $owner, $attempt, $bound );
		}

		// Read only. The resolver never issues a provider mutation.
		$outcome = $resolver->resolve( $current, $kind, $owner->token, $bound );

		if ( ! $outcome->conclusive ) {
			$next = $attempt + 1;

			$extended = AtomicTransition::commit(
				fn (): bool => $this->lease->extend_recovery( $owner, $next ),
				fn (): bool => EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$phase->value,
					'recovering',
					'cron',
					array(
						'kind'            => $kind->value,
						'note'            => $outcome->note,
						'attempt'         => $next,
						'next_read_after' => TimingPolicy::recovery_backoff( $next ),
					)
				)
			);

			// A lost extension means another worker owns the row now. Anything
			// else means the database refused the write, which is not a fencing
			// event and must not be reported as one.
			if ( $extended->committed() ) {
				return 'still_recovering';
			}

			return $extended->cas_lost() ? 'fenced' : 'deferred';
		}

		if ( $outcome->delete_row ) {
			$deleted = AtomicTransition::commit(
				fn (): bool => $this->lease->delete_row( $owner ),
				fn (): bool => EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$phase->value,
					'recovered_deleted',
					'cron',
					array(
						'kind' => $kind->value,
						'note' => $outcome->note,
					)
				)
			);

			if ( $deleted->committed() ) {
				return 'deleted';
			}

			return $deleted->cas_lost() ? 'fenced' : 'deferred';
		}

		// Finalization also clears ssl_next_attempt_at and ssl_transient_count,
		// so a resolved row carries no leftover recovery schedule (spec §12.6).
		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize( $owner, $outcome->apply ?? LeaseOutcome::checked() ),
			fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'ssl',
				$phase->value,
				'recovered',
				'cron',
				array(
					'kind' => $kind->value,
					'note' => $outcome->note,
				)
			)
		);

		if ( $applied->committed() ) {
			return 'resolved';
		}

		return $applied->cas_lost() ? 'fenced' : 'deferred';
	}

	/** The lease exactly as the selector saw it, or null if it is incomplete. */
	private function owner_from( Mapping $mapping ): ?LeaseOwner {
		if ( null === $mapping->ssl_mutation_token
			|| null === $mapping->ssl_mutation_kind
			|| null === $mapping->ssl_mutation_phase
			|| null === $mapping->ssl_mutation_driver
			|| null === $mapping->ssl_mutation_environment ) {
			return null;
		}

		return new LeaseOwner(
			$mapping->id,
			$mapping->revision,
			$mapping->ssl_mutation_token,
			$mapping->ssl_mutation_kind,
			$mapping->ssl_mutation_phase,
			$mapping->ssl_mutation_driver,
			$mapping->ssl_mutation_environment
		);
	}

	/**
	 * @return SslDriver|string The bound driver, or a human-readable reason it
	 *                          cannot be used right now.
	 */
	private function bound_driver( Mapping $mapping ) {
		$id          = (string) $mapping->ssl_mutation_driver;
		$environment = (string) $mapping->ssl_mutation_environment;
		$driver      = DriverFactory::registry()->get( $id );

		if ( null === $driver ) {
			return sprintf(
				'the driver "%s" this mutation began against is not registered; register it to learn the outcome',
				$id
			);
		}

		if ( $driver->environment_id() !== $environment ) {
			return sprintf(
				'driver "%s" is now configured for "%s" but this mutation began against "%s"; restore that configuration to learn the outcome',
				$id,
				$driver->environment_id(),
				$environment
			);
		}

		return $driver;
	}

	/**
	 * Keeps the row in RECOVERING with its bounded schedule without asking any
	 * provider anything. Identical bookkeeping to an inconclusive read, because
	 * that is exactly what an unreachable environment is.
	 */
	private function stay_fenced(
		Mapping $mapping,
		MutationPhase $phase,
		LeaseOwner $owner,
		int $attempt,
		string $reason
	): string {
		$next = $attempt + 1;

		$extended = AtomicTransition::commit(
			fn (): bool => $this->lease->extend_recovery( $owner, $next ),
			fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'ssl',
				$phase->value,
				'recovery_blocked',
				'cron',
				array(
					'kind'        => $owner->kind->value,
					'reason'      => $reason,
					'driver'      => $mapping->ssl_mutation_driver,
					'environment' => $mapping->ssl_mutation_environment,
					'attempt'     => $next,
				)
			)
		);

		// The same three-way reading as every other branch. Only a lost CAS proves
		// another worker owns this row; a transaction, event, or commit failure is
		// this database being unable to write, which is not fencing.
		if ( $extended->committed() ) {
			return 'blocked';
		}

		return $extended->cas_lost() ? 'fenced' : 'deferred';
	}

	private function is_expired( Mapping $mapping ): bool {
		return null !== $mapping->ssl_mutation_expires_at
			&& strtotime( $mapping->ssl_mutation_expires_at . ' UTC' ) <= $this->clock->now()->getTimestamp();
	}

	private function reread_due( Mapping $mapping ): bool {
		return null !== $mapping->ssl_next_attempt_at
			&& strtotime( $mapping->ssl_next_attempt_at . ' UTC' ) <= $this->clock->now()->getTimestamp();
	}
}
