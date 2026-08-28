<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;

final class Schedule {

	public const HOOKS = array(
		'pd_verify_pending'     => 900,
		'pd_verify_established' => 3600,
		'pd_ssl_sweep'          => 900,
		'pd_maintenance'        => 86400,
	);

	/** @return Mapping[] */
	public static function due_pending( int $batch ): array {
		return self::due( array( VerificationState::PENDING->value ), $batch );
	}

	/** @return Mapping[] */
	public static function due_established( int $batch ): array {
		return self::due( array( VerificationState::VERIFIED->value ), $batch );
	}

	/**
	 * @param string[] $states
	 * @return Mapping[]
	 */
	private static function due( array $states, int $batch ): array {
		global $wpdb;

		$table        = Schema::domains_table();
		$placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$now          = gmdate( 'Y-m-d H:i:s' );

		$sql = "SELECT * FROM {$table}
		         WHERE verification_state IN ({$placeholders})
		           AND integrity_error IS NULL
		           AND verify_next_attempt_at IS NOT NULL
		           AND verify_next_attempt_at <= %s
		           AND ssl_mutation_token IS NULL
		         ORDER BY verify_next_attempt_at ASC
		         LIMIT %d";

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( $sql, array_merge( $states, array( $now, $batch ) ) ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	public static function register_cron(): void {
		foreach ( array_keys( self::HOOKS ) as $hook ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_single_event( time() + 60, $hook );
			}
		}
	}

	/**
	 * @param Mapping[]               $rows
	 * @param callable(Mapping): void $work
	 * @return int Rows processed.
	 */
	public static function run_sweep( array $rows, int $budget_seconds, callable $work, string $continuation ): int {
		$started   = time();
		$processed = 0;

		foreach ( $rows as $row ) {
			$work( $row );
			++$processed;

			if ( ( time() - $started ) >= $budget_seconds ) {
				break;
			}
		}

		if ( $processed < count( $rows ) && false === wp_next_scheduled( $continuation ) ) {
			// One continuation, not a loop.
			wp_schedule_single_event( time() + 60, $continuation );
		}

		return $processed;
	}
}
