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

	/**
	 * The custom intervals behind HOOKS.
	 *
	 * One schedule per distinct declared interval, all plugin-owned. Core has no
	 * 15-minute schedule, and reusing core's `hourly`/`daily` would leave the
	 * plugin's cadence at the mercy of anything else filtering `cron_schedules`.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_schedules( array $schedules ): array {
		foreach ( array_unique( array_values( self::HOOKS ) ) as $interval ) {
			$schedules[ self::schedule_name( $interval ) ] = array(
				'interval' => $interval,
				/* translators: %d: number of seconds between runs. */
				'display'  => sprintf( 'post-domain: every %d seconds', $interval ),
			);
		}

		return $schedules;
	}

	public static function schedule_name( int $interval ): string {
		return 'pd_every_' . $interval . 's';
	}

	/**
	 * Registers the four hooks as WordPress *recurring* events.
	 *
	 * Recurring events rather than explicit self-rescheduling: WP-Cron already
	 * re-arms a recurring event before the callback runs, so a hook survives a
	 * callback that fatals — whereas a handler that reschedules itself at the end
	 * of its own run stops forever the first time it dies, which is exactly when
	 * the sweep is most needed. The bounded continuation in run_sweep() stays a
	 * single event because it is a one-off catch-up, not a cadence.
	 *
	 * The first occurrence is one minute out rather than a full interval, so a
	 * fresh install does not wait a day for maintenance; the recurrence after
	 * that is the declared interval.
	 */
	public static function register_cron(): void {
		// wp_schedule_event() validates the recurrence name against the filtered
		// list, so the filter has to be in place before the first call, not merely
		// by the time cron fires.
		if ( false === has_filter( 'cron_schedules', array( self::class, 'register_schedules' ) ) ) {
			add_filter( 'cron_schedules', array( self::class, 'register_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		}

		foreach ( self::HOOKS as $hook => $interval ) {
			$recurrence = self::schedule_name( $interval );

			if ( false !== wp_next_scheduled( $hook ) ) {
				if ( wp_get_schedule( $hook ) === $recurrence ) {
					// Already scheduled at the right cadence: leave it alone. This
					// is what keeps a second registration from producing a second
					// event.
					continue;
				}

				// A single event, or a stale cadence from an older version. Clear
				// it rather than leave a duplicate beside the correct one.
				wp_clear_scheduled_hook( $hook );
			}

			wp_schedule_event( time() + 60, $recurrence, $hook );
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
