<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Verification\Schedule;
use WP_UnitTestCase;

final class ScheduleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();

		foreach ( array_keys( Schedule::HOOKS ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public function tear_down(): void {
		foreach ( array_keys( Schedule::HOOKS ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		parent::tear_down();
	}

	private function seed( string $host, VerificationState $state, ?string $next, array $lease = array() ): int {
		global $wpdb;

		$id = ( new DbRepository() )->save(
			new Mapping(
				0,
				$host,
				null,
				self::factory()->post->create(),
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				substr( md5( $host ), 0, 32 ),
				'_post-domain-challenge'
			)
		)->id;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array_merge(
				array(
					'verification_state'     => $state->value,
					'verify_next_attempt_at' => $next,
				),
				$lease
			),
			array( 'id' => $id )
		);

		return $id;
	}

	public function test_only_due_pending_rows_are_selected(): void {
		$due    = $this->seed( 'due.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$future = $this->seed( 'future.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$null   = $this->seed( 'null.test', VerificationState::PENDING, null );

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertContains( $due, $ids );
		$this->assertNotContains( $future, $ids );
		$this->assertNotContains( $null, $ids, 'a null next-attempt is not due' );
	}

	public function test_a_leased_row_is_skipped_even_when_due(): void {
		$leased = $this->seed(
			'leased.test',
			VerificationState::PENDING,
			gmdate( 'Y-m-d H:i:s', time() - 60 ),
			array(
				'ssl_mutation_token'      => str_repeat( '9', 32 ),
				'ssl_mutation_kind'       => 'create',
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			)
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertNotContains( $leased, $ids );
	}

	public function test_an_expired_lease_still_blocks_ordinary_work(): void {
		$expired = $this->seed(
			'expired.test',
			VerificationState::PENDING,
			gmdate( 'Y-m-d H:i:s', time() - 60 ),
			array(
				'ssl_mutation_token'      => str_repeat( '8', 32 ),
				'ssl_mutation_kind'       => 'remove',
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
			)
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertNotContains(
			$expired,
			$ids,
			'expiry transfers the row to LeaseRecovery, it does not free it'
		);
	}

	public function test_a_row_with_an_integrity_error_is_skipped(): void {
		global $wpdb;

		$corrupt = $this->seed( 'corrupt.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'integrity_error' => 'challenge_name_invalid' ),
			array( 'id' => $corrupt )
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertNotContains( $corrupt, $ids );
	}

	public function test_rows_are_ordered_oldest_due_first(): void {
		$newer = $this->seed( 'newer.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$older = $this->seed( 'older.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 600 ) );

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertSame( $older, $ids[0] );
		$this->assertSame( $newer, $ids[1] );
	}

	public function test_the_batch_cap_is_honoured(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed( "batch-{$i}.test", VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		}

		$this->assertCount( 2, Schedule::due_pending( 2 ) );
	}

	public function test_the_four_cron_hooks_are_registered(): void {
		Schedule::register_cron();

		foreach ( array( 'pd_verify_pending', 'pd_verify_established', 'pd_ssl_sweep', 'pd_maintenance' ) as $hook ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ), "{$hook} must be scheduled" );
		}
	}

	public function test_the_declared_hooks_and_intervals_match_the_spec(): void {
		$this->assertSame(
			array(
				'pd_verify_pending'     => 900,
				'pd_verify_established' => 3600,
				'pd_ssl_sweep'          => 900,
				'pd_maintenance'        => 86400,
			),
			Schedule::HOOKS
		);
	}

	public function test_each_hook_recurs_at_its_declared_interval(): void {
		$before = time();
		Schedule::register_cron();
		$after = time();

		$schedules = wp_get_schedules();

		foreach ( Schedule::HOOKS as $hook => $interval ) {
			$next = wp_next_scheduled( $hook );

			$this->assertIsInt( $next, "{$hook} must be scheduled" );

			// The actual next run, not merely "an event exists": the first
			// occurrence is one minute out.
			$this->assertGreaterThanOrEqual( $before + 60, $next, "{$hook} first run" );
			$this->assertLessThanOrEqual( $after + 60, $next, "{$hook} first run" );

			$recurrence = wp_get_schedule( $hook );

			$this->assertSame(
				Schedule::schedule_name( $interval ),
				$recurrence,
				"{$hook} must be recurring, not a single event"
			);
			$this->assertArrayHasKey( (string) $recurrence, $schedules );
			$this->assertSame(
				$interval,
				$schedules[ (string) $recurrence ]['interval'],
				"{$hook} must recur every {$interval} seconds"
			);
		}
	}

	public function test_a_run_rearms_each_hook_one_interval_later(): void {
		Schedule::register_cron();

		foreach ( Schedule::HOOKS as $hook => $interval ) {
			$first = (int) wp_next_scheduled( $hook );
			$event = wp_get_scheduled_event( $hook, array(), $first );

			$this->assertNotFalse( $event, "{$hook} must have an event" );
			$this->assertSame( $interval, $event->interval, "{$hook} interval" );

			// Exactly what wp_cron() does with a due recurring event: re-arm, then
			// drop the occurrence that just ran. A single event leaves nothing.
			// Core re-arms from *now* plus the interval, not from the consumed
			// timestamp, so that is what the next run has to be.
			$before = time();
			wp_reschedule_event( $first, (string) $event->schedule, $hook );
			wp_unschedule_event( $first, $hook );
			$after = time();

			$next = wp_next_scheduled( $hook );

			$this->assertIsInt( $next, "{$hook} must come back" );
			$this->assertGreaterThanOrEqual( $before + $interval, $next, "{$hook} next run" );
			$this->assertLessThanOrEqual( $after + $interval, $next, "{$hook} next run" );
		}
	}

	public function test_registering_twice_leaves_one_event_per_hook(): void {
		Schedule::register_cron();
		$first = wp_next_scheduled( 'pd_verify_pending' );

		Schedule::register_cron();

		$this->assertSame( $first, wp_next_scheduled( 'pd_verify_pending' ), 'the event was not replaced' );

		$cron = _get_cron_array();
		$this->assertIsArray( $cron );

		foreach ( array_keys( Schedule::HOOKS ) as $hook ) {
			$count = 0;

			foreach ( $cron as $events ) {
				$count += count( $events[ $hook ] ?? array() );
			}

			$this->assertSame( 1, $count, "{$hook} must be scheduled exactly once" );
		}
	}

	public function test_a_legacy_single_event_is_replaced_by_a_recurring_one(): void {
		wp_schedule_single_event( time() + 60, 'pd_maintenance' );

		Schedule::register_cron();

		$this->assertSame( Schedule::schedule_name( 86400 ), wp_get_schedule( 'pd_maintenance' ) );

		$cron  = _get_cron_array();
		$count = 0;

		foreach ( (array) $cron as $events ) {
			$count += count( $events['pd_maintenance'] ?? array() );
		}

		$this->assertSame( 1, $count );
	}

	public function test_an_unfinished_batch_schedules_one_bounded_continuation(): void {
		wp_clear_scheduled_hook( 'pd_verify_pending' );

		$rows = array_fill( 0, 3, $this->mapping() );

		$processed = Schedule::run_sweep(
			$rows,
			0,
			static function ( Mapping $mapping ): void {
				unset( $mapping );
			},
			'pd_verify_pending'
		);

		$this->assertSame( 1, $processed, 'the budget stops the batch after the first row' );
		$this->assertNotFalse( wp_next_scheduled( 'pd_verify_pending' ), 'one continuation is scheduled' );

		// Running again must not stack a second continuation on top of the first.
		Schedule::run_sweep(
			$rows,
			0,
			static function ( Mapping $mapping ): void {
				unset( $mapping );
			},
			'pd_verify_pending'
		);

		$cron  = _get_cron_array();
		$count = 0;

		foreach ( (array) $cron as $events ) {
			$count += count( $events['pd_verify_pending'] ?? array() );
		}

		$this->assertSame( 1, $count, 'one continuation, not a loop' );
	}

	private function mapping(): Mapping {
		static $n = 0;
		++$n;

		$id = $this->seed( "sweep-{$n}.test", VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		return ( new DbRepository() )->by_id( $id ) ?? self::fail( 'seed failed' );
	}
}
