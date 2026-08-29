<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\CronWiring;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use WP_REST_Request;

/**
 * `DELETE /domains/{id}` only *requests* deletion. Everything asserted here goes
 * through the real `pd_ssl_sweep` hook rather than calling `DeletionService`
 * directly, because the defect these tests exist for was that nothing production
 * ever selected the requested rows: the service worked perfectly and was never
 * called.
 */
final class DeletionSweepTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( 'pd_settings' );
		DriverFactory::reset();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		// The plugin instance is a singleton, so the primary host this class
		// pinned must not outlive it and decide another class's routes.
		Plugin::instance()->context()->set_host(
			new HostContext( 'mapped.test', null, 'mapped.test', HostKind::MAPPED, null, EndpointClass::ROUTED, true, 'GET' )
		);

		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_filters( 'pd_dns_resolver' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/**
	 * Installs the driver and the ownership proof the way a site would, then
	 * boots the plugin's own cron topology. Nothing here injects a service into
	 * the sweep: the sweep builds its own out of the same configuration.
	 */
	private function boot( RecordingDriver $driver, DnsOutcome $outcome = DnsOutcome::MATCH ): void {
		$this->driver = $driver;

		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		add_filter(
			'pd_dns_resolver',
			static fn(): DnsResolver => new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);

		Plugin::boot();

		// The one line `Plugin::boot()` gains. `add_action` is keyed on the
		// callback, so calling this after boot has already registered it is a
		// no-op rather than a second registration.
		CronWiring::register();

		// The management routes exist only on the primary host, by design. The
		// plugin instance is a singleton, so a host context left behind by an
		// earlier test would otherwise decide whether these routes exist at all.
		Plugin::instance()->context()->set_host(
			new HostContext( 'primary.test', null, 'primary.test', HostKind::PRIMARY, null, EndpointClass::ROUTED, true, 'GET' )
		);

		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	private function owned( string $host, string $challenge ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				$challenge,
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	private function request_deletion( Mapping $mapping ): \WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $mapping->id );
		$request->set_header( 'if_match', Guard::etag( $this->repo->by_id( $mapping->id ) ) );

		return rest_do_request( $request );
	}

	/** @return array<string, string|null> */
	private function row( int $id ): array {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		/** @var array<string, string|null> $row */
		$row = (array) $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row;
	}

	private function fire_sweep(): void {
		do_action( 'pd_ssl_sweep' );
	}

	public function test_the_sweep_selector_is_registered_after_recovery(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::REMOVED ) );

		$this->assertSame(
			CronWiring::SWEEP_PRIORITY,
			has_action( 'pd_ssl_sweep', array( CronWiring::class, 'sweep_deletions' ) )
		);
		$this->assertLessThan(
			CronWiring::SWEEP_PRIORITY,
			has_action( 'pd_ssl_sweep', array( Plugin::instance(), 'sweep_ssl' ) ),
			'recovery runs first: a fenced mutation is not yet a fact to act on'
		);
	}

	public function test_a_requested_deletion_is_carried_out_by_the_real_sweep(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::REMOVED ) );
		$m = $this->owned( 'sweep-removed.test', str_repeat( 'a', 32 ) );

		$this->assertSame( 202, $this->request_deletion( $m )->get_status() );
		$this->assertSame( SslState::PENDING_REMOVAL, $this->repo->by_id( $m->id )?->ssl_state );
		$this->assertSame( 0, $this->driver->remove_calls, 'the request itself speaks to no provider' );

		$this->fire_sweep();

		$this->assertSame( 1, $this->driver->remove_calls, 'the sweep reaches the provider workflow' );
		$this->assertNull( $this->repo->by_id( $m->id ), 'a confirmed removal deletes the mapping' );
	}

	public function test_the_sweep_skips_leased_undue_and_unrequested_rows(): void {
		global $wpdb;

		$this->boot( RecordingDriver::removing( RemovalOutcome::REMOVED ) );

		$leased = $this->owned( 'leased.test', str_repeat( 'b', 32 ) );
		$undue  = $this->owned( 'undue.test', str_repeat( 'c', 32 ) );
		$active = $this->owned( 'active.test', str_repeat( 'd', 32 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_state'                => SslState::PENDING_REMOVAL->value,
				'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
				'ssl_mutation_token'       => str_repeat( '1', 32 ),
				'ssl_mutation_kind'        => MutationKind::REMOVE->value,
				'ssl_mutation_phase'       => 'reserved',
				// Unexpired, so LeaseRecovery leaves it alone too and the only
				// component that could have touched it is the one under test.
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'ssl_mutation_driver'      => 'recording',
				'ssl_mutation_environment' => 'recording:default',
			),
			array( 'id' => $leased->id )
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_state'                => SslState::PENDING_REMOVAL->value,
				'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			),
			array( 'id' => $undue->id )
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ),
			array( 'id' => $active->id )
		);

		$this->fire_sweep();

		$this->assertSame( 0, $this->driver->remove_calls, 'none of these three are due work' );
		$this->assertNotNull( $this->repo->by_id( $leased->id ) );
		$this->assertNotNull( $this->repo->by_id( $undue->id ) );
		$this->assertNotNull( $this->repo->by_id( $active->id ) );
	}

	/**
	 * The defect this covers is subtler than "the row survived": an outcome that
	 * left `deletion_next_attempt_at` alone left it permanently due, so the sweep
	 * re-issued the same provider call on every single run.
	 *
	 * @dataProvider unconfirmed_outcomes
	 */
	public function test_an_unconfirmed_removal_schedules_a_future_retry(
		RemovalOutcome $outcome,
		?int $retry_after,
		int $expected_attempts,
		int $expected_delay
	): void {
		$driver = null === $retry_after
			? RecordingDriver::removing( $outcome )
			: RecordingDriver::removing_with_retry_after( $outcome, $retry_after );

		$this->boot( $driver );
		$m = $this->owned( 'retry.test', str_repeat( 'e', 32 ) );

		$this->request_deletion( $m );

		$before = time();
		$this->fire_sweep();

		$row = $this->row( $m->id );

		$this->assertSame( 1, $this->driver->remove_calls );
		$this->assertSame( SslState::PENDING_REMOVAL->value, $row['ssl_state'] );
		$this->assertSame( $expected_attempts, (int) $row['deletion_attempts'] );

		$next = strtotime( (string) $row['deletion_next_attempt_at'] . ' UTC' );

		$this->assertNotFalse( $next );
		$this->assertGreaterThan(
			time(),
			$next,
			'a next-attempt time at or before now leaves the row permanently due'
		);
		$this->assertGreaterThanOrEqual( $before + $expected_delay, $next );
		$this->assertLessThanOrEqual( time() + $expected_delay, $next );
	}

	/**
	 * A second sweep in the same run must find nothing: the row is no longer due.
	 */
	public function test_a_scheduled_retry_is_not_immediately_due_again(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m = $this->owned( 'loop.test', str_repeat( 'f', 32 ) );

		$this->request_deletion( $m );

		$this->fire_sweep();
		$this->fire_sweep();

		$this->assertSame( 1, $this->driver->remove_calls, 'no hot loop on a permanently-due row' );
	}

	/** @return array<string, array{0: RemovalOutcome, 1: int|null, 2: int, 3: int}> */
	public static function unconfirmed_outcomes(): array {
		return array(
			// attempt_backoff( 0 ) — the row has no failed attempts behind it.
			'pending'                => array( RemovalOutcome::PENDING, null, 0, 300 ),
			'transient without hint' => array( RemovalOutcome::TRANSIENT, null, 0, 300 ),
			'transient with hint'    => array( RemovalOutcome::TRANSIENT, 45, 0, 45 ),
			'failed'                 => array( RemovalOutcome::FAILED, null, 1, 300 ),
		);
	}
}
