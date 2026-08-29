<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\Schema;

/**
 * The production selector for rows awaiting provider-side cleanup.
 *
 * `DELETE /domains/{id}` only *requests* deletion (§14.15 step 1): it stops
 * serving, moves the row to `pending_removal`, and marks it due. Without a
 * selector nothing ever picks those rows up again, so the row sits in
 * `pending_removal` forever with a permanently-due next attempt.
 *
 * The selector keys on `ssl_removal_scope`, not on `ssl_state`. Both removals
 * pass through the same states, so the state cannot say which workflow owns a
 * row; the scope column says it outright, and the sweep dispatches on it. A row
 * carrying an SSL-only removal is therefore never handed to mapping deletion,
 * which would hard-delete a domain nobody asked to delete.
 *
 * A leased row is never selected — any phase, expired or unexpired. An expired
 * lease belongs to `LeaseRecovery`, and an unexpired one to the worker that holds
 * it; §14.17 states the rule for reconciliation and §14.15 step 5 for
 * force-delete, and ordinary deletion work is no different.
 */
final class DeletionSchedule {

	/** @return Mapping[] */
	public static function due( int $batch, Clock $clock ): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE ssl_removal_scope IS NOT NULL
				    AND deletion_next_attempt_at IS NOT NULL
				    AND deletion_next_attempt_at <= %s
				    AND ssl_mutation_token IS NULL
				  ORDER BY deletion_next_attempt_at ASC
				  LIMIT %d",
				$clock->mysql(),
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}
}
