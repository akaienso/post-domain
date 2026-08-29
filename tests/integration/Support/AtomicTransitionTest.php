<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Mapping\EventLog;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;
use PostDomain\Support\TransitionOutcome;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class AtomicTransitionTest extends OwnedSessionTestCase {

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		// Several tests below break a query on purpose. Their failure is the
		// assertion, not a problem, so keep it out of the run's output.
		$wpdb->suppress_errors( true );
		$wpdb->hide_errors();
	}

	public function tear_down(): void {
		// Re-probe and restore the real engine after the overrides below, and
		// drop any query filter a failed assertion left installed.
		remove_all_filters( 'query' );
		delete_option( 'pd_schema_engine' );
		Schema::install();
		parent::tear_down();
	}

	private function as_engine( string $engine ): void {
		update_option( 'pd_schema_engine', $engine, false );
	}

	private function event( int $id ): callable {
		return static fn(): bool => EventLog::record( $id, 'example.test', 'ssl', null, 'active', 'cron' );
	}

	public function test_innodb_is_detected_as_transactional(): void {
		$this->as_engine( 'InnoDB' );

		$this->assertTrue( AtomicTransition::is_transactional() );
	}

	public function test_myisam_is_not_transactional(): void {
		$this->as_engine( 'MyISAM' );

		$this->assertFalse( AtomicTransition::is_transactional() );
	}

	public function test_on_innodb_a_zero_row_cas_records_no_event(): void {
		$this->as_engine( 'InnoDB' );

		$result = AtomicTransition::commit( static fn(): bool => false, $this->event( 101 ) );

		$this->assertSame( TransitionOutcome::CAS_LOST, $result->outcome );
		$this->assertTrue( $result->cas_lost() );
		$this->assertFalse( $result->committed() );
		$this->assertCount( 0, EventLog::for_domain( 101 ) );
	}

	public function test_on_innodb_a_successful_pair_commits_together(): void {
		$this->as_engine( 'InnoDB' );

		$result = AtomicTransition::commit( static fn(): bool => true, $this->event( 102 ) );

		$this->assertTrue( $result->committed() );
		$this->assertCount( 1, EventLog::for_domain( 102 ) );
	}

	public function test_on_innodb_a_failed_event_rolls_the_transition_back(): void {
		global $wpdb;

		$this->as_engine( 'InnoDB' );

		$result = AtomicTransition::commit(
			static function () use ( $wpdb ): bool {
				$wpdb->insert( // phpcs:ignore WordPress.DB
					Schema::events_table(),
					array(
						'domain_id'  => 103,
						'host'       => 'rolled-back.test',
						'type'       => 'ssl',
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				return true;
			},
			static fn(): bool => false
		);

		$this->assertSame( TransitionOutcome::EVENT_FAILED, $result->outcome );
		$this->assertFalse( $result->cas_lost(), 'the CAS succeeded; the event did not' );
		$this->assertCount( 0, EventLog::for_domain( 103 ), 'the transition rolled back with its event' );
	}

	public function test_a_transaction_that_cannot_start_never_runs_the_transition(): void {
		$this->as_engine( 'InnoDB' );

		$ran = false;

		add_filter( 'query', $fail = static fn( string $q ): string => 'START TRANSACTION' === $q ? 'SELECT bad_syntax FROM' : $q );

		$result = AtomicTransition::commit(
			static function () use ( &$ran ): bool {
				$ran = true;

				return true;
			},
			$this->event( 106 )
		);

		remove_filter( 'query', $fail );

		$this->assertSame( TransitionOutcome::TRANSACTION_UNAVAILABLE, $result->outcome );
		$this->assertFalse( $ran, 'nothing may be written without the transaction that keeps it whole' );
		$this->assertCount( 0, EventLog::for_domain( 106 ) );
	}

	public function test_a_failed_commit_is_never_reported_as_committed(): void {
		$this->as_engine( 'InnoDB' );

		add_filter( 'query', $fail = static fn( string $q ): string => 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q );

		$result = AtomicTransition::commit( static fn(): bool => true, $this->event( 107 ) );

		remove_filter( 'query', $fail );

		$this->assertSame( TransitionOutcome::COMMIT_UNCERTAIN, $result->outcome );
		$this->assertFalse( $result->committed() );
		$this->assertFalse( $result->cas_lost(), 'an uncertain commit is not a lost CAS' );
		$this->assertStringContainsString( 'unknown', $result->detail, 'the wording must not claim a rollback' );
	}

	public function test_an_uncertain_commit_attempts_no_rollback(): void {
		$this->as_engine( 'InnoDB' );

		$issued = array();

		add_filter(
			'query',
			$spy = static function ( string $q ) use ( &$issued ): string {
				$issued[] = $q;

				return 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q;
			}
		);

		AtomicTransition::commit( static fn(): bool => true, $this->event( 115 ) );

		remove_filter( 'query', $spy );

		$this->assertNotContains(
			'ROLLBACK',
			$issued,
			'rolling back a transaction that may already have committed decides nothing'
		);
	}

	public function test_a_failed_rollback_is_reported_as_uncertain_not_as_a_lost_cas(): void {
		$this->as_engine( 'InnoDB' );

		add_filter( 'query', $fail = static fn( string $q ): string => 'ROLLBACK' === $q ? 'SELECT bad_syntax FROM' : $q );

		$result = AtomicTransition::commit( static fn(): bool => false, $this->event( 108 ) );

		remove_filter( 'query', $fail );

		$this->assertSame( TransitionOutcome::COMMIT_UNCERTAIN, $result->outcome );
		$this->assertStringContainsString( 'ROLLBACK', $result->detail );
	}

	public function test_on_innodb_a_throwing_transition_rolls_back_and_rethrows(): void {
		$this->as_engine( 'InnoDB' );

		$this->expectException( \RuntimeException::class );

		try {
			AtomicTransition::commit(
				static function (): bool {
					throw new \RuntimeException( 'boom' );
				},
				$this->event( 104 )
			);
		} finally {
			$this->assertCount( 0, EventLog::for_domain( 104 ) );
		}
	}

	public function test_a_throwing_event_also_rolls_back_and_rethrows(): void {
		$this->as_engine( 'InnoDB' );

		$this->expectException( \RuntimeException::class );

		AtomicTransition::commit(
			static fn(): bool => true,
			static function (): bool {
				throw new \RuntimeException( 'boom' );
			}
		);
	}

	public function test_nesting_is_refused_rather_than_silently_flattened(): void {
		$this->as_engine( 'InnoDB' );

		$this->expectException( \LogicException::class );

		AtomicTransition::commit(
			static function (): bool {
				AtomicTransition::commit( static fn(): bool => true, static fn(): bool => true );

				return true;
			},
			static fn(): bool => true
		);
	}

	public function test_a_refused_nesting_leaves_the_outer_transaction_usable(): void {
		$this->as_engine( 'InnoDB' );

		try {
			AtomicTransition::commit(
				static function (): bool {
					AtomicTransition::commit( static fn(): bool => true, static fn(): bool => true );

					return true;
				},
				static fn(): bool => true
			);
		} catch ( \LogicException $e ) {
			unset( $e );
		}

		$this->assertTrue(
			AtomicTransition::commit( static fn(): bool => true, $this->event( 109 ) )->committed(),
			'the guard must reset itself'
		);
	}

	public function test_a_top_level_transition_owns_its_own_transaction(): void {
		$this->as_engine( 'InnoDB' );

		$this->assertFalse( AtomicTransition::in_ambient_transaction(), 'the probe sees a clean session' );
		$this->assertTrue( AtomicTransition::commit( static fn(): bool => true, $this->event( 110 ) )->committed() );
		$this->assertFalse( AtomicTransition::in_ambient_transaction(), 'and leaves it clean' );
	}

	public function test_an_ambient_transaction_is_detected(): void {
		global $wpdb;

		$this->as_engine( 'InnoDB' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		$this->assertTrue( AtomicTransition::in_ambient_transaction() );

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	}

	public function test_an_ambient_transaction_refuses_before_the_transition_runs(): void {
		global $wpdb;

		$this->as_engine( 'InnoDB' );
		$ran = false;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		$result = AtomicTransition::commit(
			static function () use ( &$ran ): bool {
				$ran = true;

				return true;
			},
			$this->event( 111 )
		);

		$still_open = AtomicTransition::in_ambient_transaction();

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

		$this->assertSame( TransitionOutcome::TRANSACTION_UNAVAILABLE, $result->outcome );
		$this->assertFalse( $ran, 'nothing is written into a transaction this plugin does not own' );
		$this->assertTrue( $still_open, 'and the ambient transaction is neither committed nor rolled back' );
		$this->assertCount( 0, EventLog::for_domain( 111 ) );
	}

	public function test_an_ambient_transaction_keeps_its_own_unrelated_writes(): void {
		global $wpdb;

		$this->as_engine( 'InnoDB' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
		EventLog::record( 112, 'callers-own.test', 'admin', null, null, 'someone-else' );

		AtomicTransition::commit( static fn(): bool => true, $this->event( 113 ) );

		// Still the caller's to decide, and still uncommitted.
		$this->assertCount( 1, EventLog::for_domain( 112 ) );

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

		$this->assertCount( 0, EventLog::for_domain( 112 ), 'the caller rolled back its own work' );
		$this->assertCount( 0, EventLog::for_domain( 113 ) );
	}

	public function test_a_probe_that_cannot_be_trusted_stops_the_transition(): void {
		$this->as_engine( 'InnoDB' );
		$ran = false;

		add_filter( 'query', $fail = static fn( string $q ): string => str_starts_with( $q, 'SAVEPOINT' ) ? 'SELECT bad_syntax FROM' : $q );

		$result = AtomicTransition::commit(
			static function () use ( &$ran ): bool {
				$ran = true;

				return true;
			},
			$this->event( 114 )
		);

		remove_filter( 'query', $fail );

		$this->assertSame( TransitionOutcome::TRANSACTION_UNAVAILABLE, $result->outcome );
		$this->assertFalse( $ran );
	}

	public function test_the_probe_leaves_no_error_behind(): void {
		global $wpdb;

		$this->as_engine( 'InnoDB' );
		$wpdb->last_error = '';

		AtomicTransition::in_ambient_transaction();

		$this->assertSame( '', $wpdb->last_error, 'a probe must not look like a failure to the next caller' );
	}

	public function test_on_a_nontransactional_engine_a_zero_row_cas_still_records_no_event(): void {
		$this->as_engine( 'MyISAM' );

		$result = AtomicTransition::commit( static fn(): bool => false, $this->event( 105 ) );

		$this->assertSame( TransitionOutcome::CAS_LOST, $result->outcome );
		$this->assertCount( 0, EventLog::for_domain( 105 ), 'the event is best-effort, never speculative' );
	}

	public function test_on_a_nontransactional_engine_a_failed_event_does_not_undo_the_transition(): void {
		$this->as_engine( 'MyISAM' );

		$result = AtomicTransition::commit( static fn(): bool => true, static fn(): bool => false );

		$this->assertTrue( $result->committed(), 'the log may lag or miss rows; the state change still stands' );
		$this->assertStringContainsString( 'not recorded', $result->detail );
	}
}
