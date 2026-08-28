<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class DeletionService {

	public function __construct(
		private readonly DeletionAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$repo  = new DbRepository();

		// Production resolves drivers through DriverFactory, so tests install
		// theirs the same way a site would rather than injecting a registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new self(
			new DeletionAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock ),
			$clock
		);
	}

	/** Stops serving under a CAS, then either schedules removal or deletes locally. */
	public function request( Mapping $mapping ): bool {
		global $wpdb;

		$holds_resource = null !== $mapping->ssl_ref && 'null' !== ( $mapping->ssl_provider ?? 'null' );

		if ( ! $holds_resource ) {
			// Nothing external exists, but the delete must still not race a
			// mutation someone else is preparing.
			return $this->delete_locally( $mapping );
		}

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET deletion_requested_at = %s, activation_state = %s, ssl_state = %s,
				        deletion_next_attempt_at = %s, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
				$this->clock->mysql(),
				ActivationState::INACTIVE->value,
				SslState::PENDING_REMOVAL->value,
				$this->clock->mysql(),
				$this->clock->mysql(),
				$mapping->id,
				$mapping->revision
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === $affected;
	}

	public function process( Mapping $mapping ): string {
		$authorized = $this->authorizer->authorize( $mapping );

		if ( $authorized instanceof MutationRefusal ) {
			if ( ! $authorized->transient ) {
				$this->bump_attempts( $mapping );
			}

			return 'refused';
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return 'refused';
		}

		do_action( 'pd_test_after_provider_call' );

		/** @var RemovalResult $result */
		$result = $gated->result;

		if ( RemovalOutcome::REMOVED === $result->outcome ) {
			// The host is snapshotted now because the row is about to vanish, but
			// the event is written inside the delete's own transaction — never
			// before it. A fenced worker must leave no record of a deletion it
			// did not perform, and must never delete a row recovery now owns.
			$host  = $mapping->host;
			$id    = $mapping->id;
			$from  = $mapping->ssl_state->value;
			$lease = $this->lease;

			$deleted = AtomicTransition::commit(
				static fn (): bool => $lease->delete_row( $gated->owner ),
				static fn (): bool => EventLog::record(
					$id,
					$host,
					'ssl',
					$from,
					'deleted',
					'cron',
					array( 'cleanup' => 'confirmed' )
				)
			);

			if ( $deleted->committed() ) {
				return 'removed';
			}

			if ( $deleted->cas_lost() ) {
				return 'fenced';
			}

			// An unconfirmed COMMIT cannot be settled from this connection. If the
			// transaction is still open, this connection sees its OWN uncommitted
			// delete: the row looks gone and may still roll back. A re-read here is
			// therefore diagnostic at most, never proof of durability. Report
			// nothing, re-issue nothing — the provider already confirmed removal —
			// and let a later pass, whose connection has its own committed view,
			// decide whether the row survived.
			return 'deferred';
		}

		$outcome = RemovalOutcome::FAILED === $result->outcome
			? LeaseOutcome::attempted(
				$this->attempts( $mapping ) + 1,
				TimingPolicy::attempt_backoff( $this->attempts( $mapping ) )
			)
			: LeaseOutcome::checked();

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize( $gated->owner, $outcome ),
			fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'ssl',
				$mapping->ssl_state->value,
				'removal_' . $result->outcome->value,
				'cron',
				array( 'cleanup' => $result->outcome->value )
			)
		);

		if ( ! $applied->committed() ) {
			return $applied->cas_lost() ? 'fenced' : 'deferred';
		}

		return $result->outcome->value;
	}

	/** Deletes a mapping with no provider resource, under its own RESERVED lease. */
	private function delete_locally( Mapping $mapping ): bool {
		// NullDriver is the honest binding here: there is nothing external, and
		// the lease still records which environment the row believed it was in.
		$held = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE, new NullDriver() );

		if ( null === $held ) {
			return false;
		}

		$id    = $mapping->id;
		$host  = $mapping->host;
		$from  = $mapping->ssl_state->value;
		$actor = 'admin:' . get_current_user_id();
		$lease = $this->lease;

		$deleted = AtomicTransition::commit(
			static fn (): bool => $lease->delete_row( $held ),
			static fn (): bool => EventLog::record(
				$id,
				$host,
				'ssl',
				$from,
				'deleted',
				$actor,
				array( 'cleanup' => 'no_provider_resource' )
			)
		);

		// committed() alone is enough here, and only here, because every
		// non-committed outcome has the same externally safe behaviour: no row was
		// deleted that this caller can rely on, so it reports failure and releases
		// its own reservation. Nothing distinguishes a lost CAS from a database
		// failure in what the caller must then do.
		if ( ! $deleted->committed() ) {
			// The release is itself owner-pinned, so it is a no-op if the lease is
			// no longer ours.
			$this->lease->release_reserved( $held );
		}

		return $deleted->committed();
	}

	private function attempts( Mapping $mapping ): int {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT deletion_attempts FROM {$table} WHERE id = %d", $mapping->id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return bool True only when exactly one row was counted. */
	private function bump_attempts( Mapping $mapping ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		// Unleased and at the revision we read: a refusal that races a real
		// mutation must not inflate that mutation's attempt counter.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table().
		return 1 === $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET deletion_attempts = deletion_attempts + 1, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
				$this->clock->mysql(),
				$mapping->id,
				$mapping->revision
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
