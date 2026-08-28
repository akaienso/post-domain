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
}
