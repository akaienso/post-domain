<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Contracts\SslDriver;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\LeaseOutcome;
use PostDomain\Ssl\LeaseOwner;
use PostDomain\Ssl\LeaseRecovery;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Ssl\RecoveryOutcome;
use PostDomain\Ssl\RecoveryResolver;
use PostDomain\Ssl\TimingPolicy;
use PostDomain\Support\Schema;
use PostDomain\Tests\Fixtures\FrozenClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use WP_UnitTestCase;

final class LeaseRecoveryTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationLease $lease;

	private LeaseRecovery $recovery;

	private FrozenClock $clock;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		$this->repo     = new DbRepository();
		$this->clock    = new FrozenClock();
		$this->lease    = new MutationLease( $this->clock );
		$this->recovery = new LeaseRecovery( $this->lease, $this->repo, $this->clock );
		$this->driver   = RecordingDriver::succeeding( 'ref-1' );
		$this->install( $this->driver );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function resolver( RecoveryOutcome $outcome, ?callable $spy = null ): RecoveryResolver {
		return new class( $outcome, $spy ) implements RecoveryResolver {
			/** @param callable|null $spy */
			public function __construct(
				private readonly RecoveryOutcome $outcome,
				private $spy
			) {}

			public function resolve(
				Mapping $mapping,
				MutationKind $kind,
				string $recovery_token,
				SslDriver $driver
			): RecoveryOutcome {
				if ( null !== $this->spy ) {
					( $this->spy )( $mapping, $kind, $recovery_token, $driver );
				}

				return $this->outcome;
			}
		};
	}

	/** Installs a driver the way a site would, through the one factory. */
	private function install( SslDriver $driver ): void {
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();
	}

	private function seed( string $host, ?MutationPhase $phase, int $offset, MutationKind $kind = MutationKind::CREATE ): Mapping {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::REQUESTED,
				null,
				substr( md5( $host ), 0, 32 ),
				'_post-domain-challenge'
			)
		);

		if ( null !== $phase ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				Schema::domains_table(),
				array(
					'ssl_mutation_token'       => bin2hex( random_bytes( 16 ) ),
					'ssl_mutation_kind'        => $kind->value,
					'ssl_mutation_phase'       => $phase->value,
					'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $offset ),
					'ssl_mutation_driver'      => $this->driver->id(),
					'ssl_mutation_environment' => $this->driver->environment_id(),
				),
				array( 'id' => $m->id )
			);
		}

		return $this->repo->by_id( $m->id );
	}

	public function test_only_expired_leases_and_due_rereads_are_selected(): void {
		global $wpdb;

		$expired  = $this->seed( 'expired.test', MutationPhase::IN_FLIGHT, -600 );
		$live     = $this->seed( 'live.test', MutationPhase::IN_FLIGHT, 600 );
		$unleased = $this->seed( 'free.test', null, 0 );

		$due_reread = $this->seed( 'reread.test', MutationPhase::RECOVERING, 600 );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $due_reread->id )
		);

		$later_reread = $this->seed( 'later.test', MutationPhase::RECOVERING, 600 );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ),
			array( 'id' => $later_reread->id )
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, $this->recovery->due( 50 ) );

		$this->assertContains( $expired->id, $ids );
		$this->assertContains( $due_reread->id, $ids, 'a scheduled re-read is due work' );
		$this->assertNotContains( $live->id, $ids );
		$this->assertNotContains( $unleased->id, $ids );
		$this->assertNotContains( $later_reread->id, $ids, 'the backoff has not elapsed' );
	}

	public function test_an_expired_reserved_lease_clears_without_calling_the_resolver(): void {
		$m     = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );
		$calls = 0;

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::inconclusive( 'unused' ),
				static function () use ( &$calls ): void {
					++$calls;
				}
			)
		);

		$this->assertSame( 'cleared', $outcome );
		$this->assertSame( 0, $calls, 'nothing was sent, so nothing needs reading' );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_a_cleared_row_becomes_ordinarily_acquirable(): void {
		$m = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );
		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertNotNull(
			$this->lease->acquire( $after->id, $after->revision, MutationKind::CREATE, RecordingDriver::succeeding( 'ref-1' ) )
		);
	}

	public function test_the_fence_precedes_the_resolver_and_hands_it_the_new_token(): void {
		$m        = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$observed = array();

		$this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::inconclusive( 'still reading' ),
				function ( Mapping $mapping, MutationKind $kind, string $token ) use ( &$observed ): void {
					$row        = ( new DbRepository() )->by_id( $mapping->id );
					$observed[] = array(
						'phase' => $row?->ssl_mutation_phase?->value,
						'token' => $row?->ssl_mutation_token,
						'kind'  => $kind->value,
						'given' => $token,
					);
				}
			)
		);

		$this->assertSame( 'recovering', $observed[0]['phase'] );
		$this->assertSame( $observed[0]['token'], $observed[0]['given'] );
		$this->assertSame( 'create', $observed[0]['kind'], 'the preserved kind drives the dispatch' );
	}

	public function test_the_fenced_original_worker_cannot_finalize(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$this->assertFalse(
			$this->lease->finalize( $this->owner_of( $m, MutationPhase::IN_FLIGHT ), LeaseOutcome::state( SslState::ACTIVE ) )
		);
	}

	public function test_a_conclusive_outcome_is_applied_under_the_recovery_token(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver( RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'confirmed active' ) )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'resolved', $outcome );
		$this->assertSame( SslState::ACTIVE, $after?->ssl_state );
		$this->assertNull( $after?->ssl_mutation_token, 'the lease is cleared with the result' );
	}

	public function test_a_conclusive_removal_deletes_the_row(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600, MutationKind::REMOVE );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver( RecoveryOutcome::delete( 'provider confirms absent' ) )
		);

		$this->assertSame( 'deleted', $outcome );
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}

	public function test_an_inconclusive_outcome_stays_recovering_and_renews_the_lease(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'provider silent' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'still_recovering', $outcome );
		$this->assertSame( MutationPhase::RECOVERING, $after?->ssl_mutation_phase );
		$this->assertGreaterThan(
			gmdate( 'Y-m-d H:i:s' ),
			(string) $after?->ssl_mutation_expires_at,
			'the recovery TTL is renewed under the owning token'
		);
	}

	public function test_an_inconclusive_outcome_persists_a_growing_backoff(): void {
		$m     = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$quiet = $this->resolver( RecoveryOutcome::inconclusive( 'provider silent' ) );

		$this->recovery->recover( $m, $quiet );
		$first         = $this->repo->by_id( $m->id );
		$first_checked = $this->checked_at( $m->id );

		$this->assertSame( 1, $first?->ssl_transient_count );
		$this->assertNotNull( $first?->ssl_next_attempt_at );

		// The scheduled re-read comes due; the same worker continues.
		$this->at_time( strtotime( (string) $first?->ssl_next_attempt_at . ' UTC' ) + 1 );

		$this->assertSame( 'still_recovering', $this->recovery->recover( $first, $quiet ) );

		$second         = $this->repo->by_id( $m->id );
		$second_checked = $this->checked_at( $m->id );

		$this->assertSame( 2, $second?->ssl_transient_count );
		$this->assertSame(
			$first?->ssl_mutation_token,
			$second?->ssl_mutation_token,
			'a continuation keeps its token; only a takeover replaces one'
		);
		// ssl_checked_at is not carried on Mapping, so it is read from the row.
		$this->assertGreaterThan(
			strtotime( (string) $first?->ssl_next_attempt_at . ' UTC' ) - strtotime( $first_checked . ' UTC' ),
			strtotime( (string) $second?->ssl_next_attempt_at . ' UTC' ) - strtotime( $second_checked . ' UTC' ),
			'each interval is longer than the last'
		);
	}

	public function test_a_scheduled_reread_is_ignored_before_it_comes_due(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame(
			'skipped',
			$this->recovery->recover( $after, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) ),
			'the backoff means nothing to do yet'
		);
		$this->assertSame( 1, $this->repo->by_id( $m->id )?->ssl_transient_count );
	}

	public function test_the_backoff_is_capped(): void {
		global $wpdb;

		$m = $this->seed( 'inflight.test', MutationPhase::RECOVERING, -600 );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_transient_count' => 40 ),
			array( 'id' => $m->id )
		);

		$this->recovery->recover(
			$this->repo->by_id( $m->id ),
			$this->resolver( RecoveryOutcome::inconclusive( 'x' ) )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertLessThanOrEqual(
			TimingPolicy::MAX_RECOVERY_BACKOFF + 5,
			strtotime( (string) $after?->ssl_next_attempt_at . ' UTC' ) - time()
		);
	}

	public function test_a_scheduled_reread_stays_inside_its_own_fencing_window(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertLessThan(
			strtotime( (string) $after?->ssl_mutation_expires_at . ' UTC' ),
			strtotime( (string) $after?->ssl_next_attempt_at . ' UTC' ),
			'otherwise the re-read hands the row to a takeover instead'
		);
	}

	public function test_an_extension_that_loses_its_cas_is_reported_as_fenced(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::inconclusive( 'provider silent' ),
				function ( Mapping $mapping ): void {
					// Another recovery worker takes the row while the read runs.
					$this->steal( $mapping->id );
				}
			)
		);

		$this->assertSame( 'fenced', $outcome );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'recovering' === $e['to_state']
			),
			'a fenced worker leaves no history'
		);
	}

	public function test_a_conclusive_result_that_loses_its_cas_writes_no_event(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'confirmed active' ),
				function ( Mapping $mapping ): void {
					$this->steal( $mapping->id );
				}
			)
		);

		$this->assertSame( 'fenced', $outcome );
		$this->assertNotSame( SslState::ACTIVE, $this->repo->by_id( $m->id )?->ssl_state );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'recovered' === $e['to_state']
			)
		);
	}

	public function test_a_recovered_deletion_that_loses_its_cas_deletes_nothing(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600, MutationKind::REMOVE );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::delete( 'provider confirms absent' ),
				function ( Mapping $mapping ): void {
					$this->steal( $mapping->id );
				}
			)
		);

		$this->assertSame( 'fenced', $outcome );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'the row belongs to the new owner now' );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'recovered_deleted' === $e['to_state']
			)
		);
	}

	public function test_an_expired_reserved_lease_that_loses_its_cas_records_nothing(): void {
		$m = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );

		$this->steal( $m->id );

		$this->assertSame(
			'skipped',
			$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) )
		);
		$this->assertSame( array(), EventLog::for_domain( $m->id ) );
	}

	public function test_an_expired_recovering_lease_can_be_taken_over(): void {
		$m   = $this->seed( 'recovering.test', MutationPhase::RECOVERING, -600 );
		$old = (string) $m->ssl_mutation_token;

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$this->assertNotSame( $old, $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_a_superseded_recovery_worker_cannot_apply_a_result(): void {
		$m = $this->seed( 'recovering.test', MutationPhase::RECOVERING, -600 );

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$this->assertFalse(
			$this->lease->finalize( $this->owner_of( $m, MutationPhase::RECOVERING ), LeaseOutcome::state( SslState::ACTIVE ) )
		);
	}

	public function test_a_partial_binding_fails_closed_rather_than_falling_through(): void {
		global $wpdb;

		// Raw SQL, because the repository invariant forbids writing this state.
		// A future mistake or a legacy row must still not resolve from current
		// configuration and read somebody else's account.
		$m = $this->seed( 'partial.test', null, 0 );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_provider' => 'recording' ),
			array( 'id' => $m->id )
		);

		$result = \PostDomain\Ssl\BoundResource::driver_for( $this->repo->by_id( $m->id ) );

		$this->assertInstanceOf( \PostDomain\Ssl\DriverUnavailable::class, $result );
		$this->assertSame( 'provider_binding_incomplete', $result->reason );
	}

	public function test_a_deregistered_bound_driver_blocks_recovery_without_asking_anyone(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		// The operator switched providers while the mutation was outstanding.
		remove_all_filters( 'pd_ssl_drivers' );
		DriverFactory::reset();

		$asked   = 0;
		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'unused' ),
				static function () use ( &$asked ): void {
					++$asked;
				}
			)
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'blocked', $outcome );
		$this->assertSame( 0, $asked, 'no resolver runs without the driver the mutation began against' );
		$this->assertSame( MutationPhase::RECOVERING, $after?->ssl_mutation_phase );
		$this->assertSame( 'recording', $after?->ssl_mutation_driver, 'the binding is preserved, not rewritten' );
	}

	public function test_a_changed_provider_environment_blocks_recovery(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		// Same driver id, different account or zone: a conclusive answer from
		// there would be a lie about this mutation.
		remove_all_filters( 'pd_ssl_drivers' );
		$this->install( RecordingDriver::succeeding( 'ref-1' )->in_environment( 'zone:somewhere-else' ) );

		$asked   = 0;
		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'unused' ),
				static function () use ( &$asked ): void {
					++$asked;
				}
			)
		);

		$this->assertSame( 'blocked', $outcome );
		$this->assertSame( 0, $asked );
		$this->assertNotSame( SslState::ACTIVE, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_a_blocked_recovery_names_the_configuration_to_restore(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		remove_all_filters( 'pd_ssl_drivers' );
		$this->install( RecordingDriver::succeeding( 'ref-1' )->in_environment( 'zone:somewhere-else' ) );

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'unused' ) ) );

		$blocked = array_values(
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'recovery_blocked' === $e['to_state']
			)
		);

		$this->assertCount( 1, $blocked );

		$detail = json_decode( (string) $blocked[0]['detail'], true );

		$this->assertSame( 'recording', $detail['driver'] );
		$this->assertSame( $this->driver->environment_id(), $detail['environment'] );
		$this->assertStringContainsString( 'restore', (string) $detail['reason'] );
	}

	public function test_a_blocked_recovery_backs_off_rather_than_spinning(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		remove_all_filters( 'pd_ssl_drivers' );
		DriverFactory::reset();

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'unused' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 1, $after?->ssl_transient_count );
		$this->assertNotNull( $after?->ssl_next_attempt_at );
	}

	public function test_recovery_resumes_once_the_original_configuration_returns(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		remove_all_filters( 'pd_ssl_drivers' );
		DriverFactory::reset();

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'unused' ) ) );

		$blocked = $this->repo->by_id( $m->id );
		$this->at_time( strtotime( (string) $blocked?->ssl_next_attempt_at . ' UTC' ) + 1 );

		// The operator restores the account.
		$this->install( $this->driver );

		$outcome = $this->recovery->recover(
			$blocked,
			$this->resolver( RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'confirmed active' ) )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'resolved', $outcome );
		$this->assertSame( SslState::ACTIVE, $after?->ssl_state );
	}

	public function test_a_conclusive_recovery_leaves_no_recovery_schedule_behind(): void {
		$m     = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$quiet = $this->resolver( RecoveryOutcome::inconclusive( 'provider silent' ) );

		$this->recovery->recover( $m, $quiet );
		$this->recovery->recover( $this->repo->by_id( $m->id ), $quiet );

		$mid = $this->repo->by_id( $m->id );
		$this->at_time( strtotime( (string) $mid?->ssl_next_attempt_at . ' UTC' ) + 1 );

		$this->recovery->recover(
			$this->repo->by_id( $m->id ),
			$this->resolver( RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'confirmed active' ) )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_next_attempt_at, 'ordinary polling must not inherit a recovery timestamp' );
		$this->assertSame( 0, $after?->ssl_transient_count );
		$this->assertNull( $after?->ssl_mutation_driver );
		$this->assertNull( $after?->ssl_mutation_environment );
	}

	public function test_a_cleared_reservation_leaves_no_recovery_schedule_behind(): void {
		global $wpdb;

		$m = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 900 ),
				'ssl_transient_count' => 3,
			),
			array( 'id' => $m->id )
		);

		$this->recovery->recover( $this->repo->by_id( $m->id ), $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_next_attempt_at );
		$this->assertSame( 0, $after?->ssl_transient_count );
	}

	public function test_the_resolver_receives_the_bound_driver_not_the_configured_one(): void {
		$m        = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$observed = null;

		$this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::inconclusive( 'x' ),
				static function ( Mapping $mapping, MutationKind $kind, string $token, SslDriver $driver ) use ( &$observed ): void {
					$observed = $driver->environment_id();
				}
			)
		);

		$this->assertSame( $this->driver->environment_id(), $observed );
	}

	public function test_recovery_never_issues_a_provider_mutation(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Ssl/LeaseRecovery.php' );

		foreach ( array( 'MutationGate', 'MutationAuthorization', 'ExecutionPermit' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $source );
		}
	}

	/** The lease this row carried when the test seeded it. */
	private function owner_of( Mapping $m, MutationPhase $phase ): LeaseOwner {
		return new LeaseOwner(
			$m->id,
			$m->revision,
			(string) $m->ssl_mutation_token,
			$m->ssl_mutation_kind ?? MutationKind::CREATE,
			$phase,
			(string) $m->ssl_mutation_driver,
			(string) $m->ssl_mutation_environment
		);
	}

	/** ssl_checked_at is a row column the Mapping value object does not carry. */
	private function checked_at( int $mapping_id ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT ssl_checked_at FROM ' . Schema::domains_table() . ' WHERE id = %d',
				$mapping_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Simulates another recovery worker claiming the row mid-read. */
	private function steal( int $mapping_id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token' => bin2hex( random_bytes( 16 ) ),
				'ssl_mutation_phase' => MutationPhase::RECOVERING->value,
				'revision'           => 999,
			),
			array( 'id' => $mapping_id )
		);
	}

	/** Moves the injected clock so a scheduled re-read comes due. */
	private function at_time( int $timestamp ): void {
		$this->clock->set( ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) ) );
	}
}
