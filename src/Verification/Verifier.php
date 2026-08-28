<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\DnsResolver;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;

final class Verifier {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly DnsResolver $resolver,
		private readonly Clock $clock
	) {}

	public function verify( Mapping $mapping ): DnsOutcome {
		// Persisted challenge data is re-validated at read time. The filter ran
		// once, at create or rotate; whatever is in the row now has to stand on
		// its own, and a row that cannot is corrupt rather than merely unlucky
		// (spec §13.1).
		if (
			! Challenge::is_valid_label( $mapping->challenge_label )
			|| ! Challenge::is_valid_token( $mapping->challenge )
		) {
			$this->mark_corrupt( $mapping, 'challenge_name_invalid' );

			return DnsOutcome::TRANSIENT;
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			$this->mark_corrupt( $mapping, 'challenge_name_invalid' );

			return DnsOutcome::TRANSIENT;
		}

		$token = $this->take_lease( $mapping );

		if ( null === $token ) {
			return DnsOutcome::TRANSIENT;
		}

		$result = $this->resolver->txt( $name, Challenge::expected_value( $mapping->challenge ) );

		$limit = (int) apply_filters( 'pd_verification_grace', 3 );
		$limit = max( 1, $limit );

		$after = GracePolicy::apply(
			$mapping->verification_state,
			$result->outcome,
			0,
			0,
			$limit,
			false
		);

		$this->apply_under_cas( $mapping, $token, $result, $after, $limit );

		return $result->outcome;
	}

	private function take_lease( Mapping $mapping ): ?string {
		global $wpdb;

		$token   = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + 120 );
		$now     = $this->clock->mysql();
		$table   = Schema::domains_table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( // phpcs:ignore WordPress.DB
				"UPDATE {$table}
				    SET verify_lease_token = %s, verify_lease_expires_at = %s, revision = revision + 1
				  WHERE id = %d AND revision = %d
				    AND ( verify_lease_expires_at IS NULL OR verify_lease_expires_at <= %s )",
				$token,
				$expires,
				$mapping->id,
				$mapping->revision,
				$now
			)
		);

		return 1 === $affected ? $token : null;
	}

	/**
	 * @param array{state: VerificationState, hard: int, transient: int} $after
	 */
	private function apply_under_cas(
		Mapping $mapping,
		string $token,
		DnsResult $result,
		array $after,
		int $limit
	): void {
		global $wpdb;

		$table = Schema::domains_table();
		$now   = $this->clock->mysql();

		$next = DnsOutcome::TRANSIENT === $result->outcome
			? $this->clock->now()->getTimestamp() + 1800
			: $this->clock->now()->getTimestamp() + ( VerificationState::VERIFIED === $after['state'] ? 86400 : 900 );

		$sql = "UPDATE {$table}
		           SET verification_state = %s,
		               hard_failure_count = CASE WHEN %s = 'transient' THEN hard_failure_count
		                                          WHEN %s = 'match' THEN 0
		                                          ELSE hard_failure_count + 1 END,
		               transient_failure_count = CASE WHEN %s = 'transient'
		                                              THEN transient_failure_count + 1 ELSE 0 END,
		               last_outcome = %s,
		               last_checked_at = %s,
		               verified_at = CASE WHEN %s = 'match' THEN %s ELSE verified_at END,
		               verify_next_attempt_at = %s,
		               resolver_class = %s,
		               verify_lease_token = NULL,
		               verify_lease_expires_at = NULL,
		               revision = revision + 1,
		               updated_at = %s
		         WHERE id = %d AND verify_lease_token = %s AND challenge = %s";

		$resolved = $this->resolved_state( $mapping, $result, $limit )->value;

		// The transition and its event are one write on InnoDB, and on any other
		// engine the event is attempted only after the CAS has already won: a row
		// that changed underneath this attempt is discarded, never replayed and
		// never logged.
		AtomicTransition::commit(
			fn (): bool => 1 === $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( // phpcs:ignore WordPress.DB
					$sql,
					$resolved,
					$result->outcome->value,
					$result->outcome->value,
					$result->outcome->value,
					$result->outcome->value,
					$now,
					$result->outcome->value,
					$now,
					gmdate( 'Y-m-d H:i:s', $next ),
					$this->resolver::class,
					$now,
					$mapping->id,
					$token,
					$mapping->challenge
				)
			),
			fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'verification',
				$mapping->verification_state->value,
				$resolved,
				'cron',
				array(
					'outcome'        => $result->outcome->value,
					'resolver_class' => $this->resolver::class,
					'attempt_id'     => $token,
				)
			)
		);
	}

	private function resolved_state( Mapping $mapping, DnsResult $result, int $limit ): VerificationState {
		global $wpdb;

		$table = Schema::domains_table();
		$hard  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT hard_failure_count FROM {$table} WHERE id = %d", $mapping->id ) // phpcs:ignore WordPress.DB
		);

		$deadline_passed = false;

		return GracePolicy::apply(
			$mapping->verification_state,
			$result->outcome,
			$hard,
			0,
			$limit,
			$deadline_passed
		)['state'];
	}

	private function mark_corrupt( Mapping $mapping, string $reason ): void {
		global $wpdb;

		// The event follows the write, and only if the write happened.
		AtomicTransition::commit(
			static fn (): bool => 1 === $wpdb->update( // phpcs:ignore WordPress.DB
				Schema::domains_table(),
				array(
					'integrity_error' => $reason,
					'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array(
					'id'              => $mapping->id,
					'integrity_error' => null,
				)
			),
			static fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'verification',
				null,
				'integrity_error',
				'cron',
				array( 'integrity' => $reason )
			)
		);
	}
}
