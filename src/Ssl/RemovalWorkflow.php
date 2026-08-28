<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;

/**
 * The authorize -> gate -> provider-call sequence shared by the two removals,
 * together with the durable retry schedule every unconfirmed outcome must leave
 * behind.
 *
 * Deleting a mapping and removing only its provider resource differ in exactly
 * one place: what happens once the provider answers `REMOVED`. One hard-deletes
 * the row, the other keeps it and clears the binding. Everything that must never
 * diverge between them — the authorization, the lease, the gate, the single
 * finalize CAS, and the rule that no attempt may leave `deletion_next_attempt_at`
 * still due — lives here rather than being written twice.
 */
final class RemovalWorkflow {

	public function __construct(
		private readonly DeletionAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	/**
	 * Authorizes, consumes the authorization through the gate, and lets the gate
	 * — the only component permitted to — call the driver.
	 *
	 * @return GateResult|MutationRefusal
	 */
	public function attempt( Mapping $mapping ) {
		$authorized = $this->authorizer->authorize( $mapping );

		if ( $authorized instanceof MutationRefusal ) {
			// A transient refusal proves nothing about the resource, so it must
			// not consume one of the attempts that lead to the force ceiling.
			if ( ! $authorized->transient ) {
				$this->bump_attempts( $mapping );
			}

			return $authorized;
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return $gated;
		}

		do_action( 'pd_test_after_provider_call' );

		return $gated;
	}

	/**
	 * Applies an outcome and clears the lease in one CAS, with the event written
	 * inside that same transaction.
	 *
	 * @return string One of: committed, fenced, deferred.
	 */
	public function finalize(
		Mapping $mapping,
		GateResult $gated,
		LeaseOutcome $outcome,
		string $to_state,
		string $detail
	): string {
		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize( $gated->owner, $outcome ),
			fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'ssl',
				$mapping->ssl_state->value,
				$to_state,
				'cron',
				array( 'cleanup' => $detail )
			)
		);

		if ( $applied->committed() ) {
			return 'committed';
		}

		// A lost CAS is a fencing event; a database refusal is not, and reporting
		// one as the other would tell the caller the row moved when it did not.
		return $applied->cas_lost() ? 'fenced' : 'deferred';
	}

	/**
	 * The durable schedule an unconfirmed removal leaves behind.
	 *
	 * Every branch writes a *future* `deletion_next_attempt_at`. Leaving the
	 * column at its previous value would leave the row permanently due, and the
	 * sweep would re-issue the same provider call on every run.
	 *
	 * `PENDING` and `TRANSIENT` do not increment `deletion_attempts`: neither is
	 * evidence that the removal cannot succeed, and only evidence of that should
	 * move a row towards the force-delete ceiling (§14.15 step 5).
	 */
	public function retry_schedule( Mapping $mapping, RemovalResult $result ): LeaseOutcome {
		$attempts = $this->attempts( $mapping );

		/** @var array<string, string|int|null> $columns */
		$columns = array( 'ssl_checked_at' => $this->clock->mysql() );

		if ( RemovalOutcome::FAILED === $result->outcome ) {
			$columns['deletion_attempts'] = $attempts + 1;
			$delay                        = TimingPolicy::attempt_backoff( $attempts );
		} elseif ( RemovalOutcome::TRANSIENT === $result->outcome && null !== $result->retry_after ) {
			// The driver knows when the provider will answer again. It is still
			// bounded: a hostile or buggy retry_after must not park a row for
			// longer than the plugin's own ceiling, nor make it due immediately.
			$delay = max( 1, min( TimingPolicy::MAX_BACKOFF, $result->retry_after ) );
		} else {
			$delay = TimingPolicy::attempt_backoff( $attempts );
		}

		$columns['deletion_next_attempt_at'] = gmdate(
			'Y-m-d H:i:s',
			$this->clock->now()->getTimestamp() + $delay
		);

		return LeaseOutcome::raw( $columns );
	}

	public function attempts( Mapping $mapping ): int {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT deletion_attempts FROM {$table} WHERE id = %d", $mapping->id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return bool True only when exactly one row was counted. */
	public function bump_attempts( Mapping $mapping ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		// Unleased and at the revision we read: a refusal that races a real
		// mutation must not inflate that mutation's attempt counter.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
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
