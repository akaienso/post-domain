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
use PostDomain\Ssl\CronWiring;
use PostDomain\Ssl\DeletionSchedule;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\LeaseRecovery;
use PostDomain\Ssl\MutationLease;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;

/**
 * Clone resolution has to leave a row that is not merely unowned but *inert*.
 *
 * The defect these tests exist for is that it cleared four of the six lease
 * columns and none of the removal or retry state, so a copied database could
 * hold a row the repository considers invalid (§12.6) while still being due for
 * a removal that the source installation requested against the source
 * installation's provider resource (§14.8, §14.15).
 */
final class CloneResetTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		delete_option( 'pd_settings' );
		DriverFactory::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_filters( 'pd_dns_resolver' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/** Installs the driver the way a site would, then boots the real cron topology. */
	private function boot(): void {
		$this->driver = RecordingDriver::succeeding( 'ref-1' );
		$driver       = $this->driver;

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
			static fn(): DnsResolver => new class() implements DnsResolver {
				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( DnsOutcome::MATCH );
				}
			}
		);

		Plugin::boot();
		CronWiring::register();
	}

	/**
	 * A row with a non-null value in every lease, ownership, adoption, retry,
	 * error and removal column.
	 *
	 * `host` and `challenge` are both UNIQUE, so both vary per call. The write is
	 * a direct `$wpdb->update()` on purpose: `save()` deliberately refuses to mint
	 * an adoption or to touch the CAS-owned retry columns, and this fixture needs
	 * every one of them populated.
	 */
	private function seeded( string $host, string $challenge ): Mapping {
		global $wpdb;

		$mapping = $this->repo->save(
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
				'_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_state'                 => SslState::PENDING_REMOVAL->value,
				'ssl_provider'              => 'recording',
				'ssl_provider_environment'  => 'recording:default',
				'ssl_ref'                   => 'ref-1',
				'ssl_ownership_origin'      => OwnershipOrigin::ADOPTED->value,
				'ssl_owner_installation_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
				'ssl_adopted_at'            => '2026-01-01 00:00:00',
				'ssl_adopted_by'            => 7,
				'ssl_method'                => 'txt',
				'ssl_method_requested_at'   => '2026-01-01 00:00:00',
				'ssl_marker_support'        => 'supported',
				'ssl_checked_at'            => '2026-01-02 00:00:00',
				'ssl_next_attempt_at'       => '2020-01-01 00:00:00',
				'ssl_transient_count'       => 3,
				'ssl_provider_state'        => '{"ssl":{"status":"active"}}',
				'ssl_error'                 => '{"code":"boom","message":"boom","at":"2026-01-02T00:00:00Z"}',
				'ssl_mutation_token'        => str_repeat( 'f', 32 ),
				'ssl_mutation_kind'         => 'remove',
				'ssl_mutation_phase'        => 'in_flight',
				'ssl_mutation_expires_at'   => '2020-01-01 00:00:00',
				'ssl_mutation_driver'       => 'recording',
				'ssl_mutation_environment'  => 'recording:default',
				'ssl_removal_scope'         => 'mapping',
				'deletion_requested_at'     => '2026-01-02 00:00:00',
				'deletion_attempts'         => 2,
				'deletion_next_attempt_at'  => '2020-01-01 00:00:00',
			),
			array( 'id' => $mapping->id )
		);

		return $mapping;
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

	/** Puts the installation into the blocked state a real clone arrives in. */
	private function detect_clone(): void {
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		$this->assertTrue( Environment::is_blocked() );
	}

	public function test_every_lease_column_is_cleared_not_merely_four_of_six(): void {
		$m = $this->seeded( 'clone-lease.test', str_repeat( 'a', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$row = $this->row( $m->id );

		$this->assertNull( $row['ssl_mutation_token'] );
		$this->assertNull( $row['ssl_mutation_kind'] );
		$this->assertNull( $row['ssl_mutation_phase'] );
		$this->assertNull( $row['ssl_mutation_expires_at'] );
		$this->assertNull( $row['ssl_mutation_driver'], 'the driver is a lease column too' );
		$this->assertNull( $row['ssl_mutation_environment'], 'so is the environment' );
	}

	public function test_the_durable_binding_and_adoption_provenance_are_cleared(): void {
		$m = $this->seeded( 'clone-binding.test', str_repeat( 'b', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$row = $this->row( $m->id );

		$this->assertNull( $row['ssl_provider'] );
		$this->assertNull( $row['ssl_provider_environment'] );
		$this->assertNull( $row['ssl_ref'] );
		$this->assertNull( $row['ssl_ownership_origin'] );
		$this->assertNull( $row['ssl_owner_installation_id'] );
		$this->assertNull( $row['ssl_adopted_at'] );
		$this->assertNull( $row['ssl_adopted_by'] );
		$this->assertNull( $row['ssl_provider_state'] );
	}

	public function test_every_removal_intent_and_schedule_column_is_cleared(): void {
		$m = $this->seeded( 'clone-removal.test', str_repeat( 'c', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$row = $this->row( $m->id );

		$this->assertNull( $row['ssl_removal_scope'] );
		$this->assertNull( $row['deletion_requested_at'] );
		$this->assertSame( 0, (int) $row['deletion_attempts'] );
		$this->assertNull( $row['deletion_next_attempt_at'] );
	}

	public function test_stale_provider_retry_and_observation_state_is_cleared(): void {
		$m = $this->seeded( 'clone-retry.test', str_repeat( 'd', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$row = $this->row( $m->id );

		$this->assertNull( $row['ssl_next_attempt_at'] );
		$this->assertSame( 0, (int) $row['ssl_transient_count'] );
		$this->assertNull( $row['ssl_error'] );
		$this->assertNull( $row['ssl_checked_at'] );
		$this->assertNull( $row['ssl_marker_support'], 'marker support described the old provider environment' );
		$this->assertNull( $row['ssl_method_requested_at'] );
	}

	public function test_the_mapping_survives_unverified_unbound_and_under_a_new_identity(): void {
		$before = Environment::installation_id();
		$m      = $this->seeded( 'clone-survives.test', str_repeat( 'e', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$after = $this->repo->by_id( $m->id );
		$row   = $this->row( $m->id );

		$this->assertNotNull( $after );
		$this->assertSame( 'clone-survives.test', $after->host );
		$this->assertSame( VerificationState::UNVERIFIED, $after->verification_state );
		$this->assertSame( SslState::NONE, $after->ssl_state );
		$this->assertNull( $row['verified_at'] );
		$this->assertNotSame( str_repeat( 'e', 32 ), $after->challenge, 'challenges rotate' );
		$this->assertNotSame( $before, Environment::installation_id() );
		$this->assertFalse( Environment::is_blocked() );

		// The one thing a clone legitimately keeps: the operator's own DCV choice
		// (§14.12). It is configuration, not a fact about somebody else's
		// resource, and §14.8's clone row does not name it.
		$this->assertSame( 'txt', $row['ssl_method'] );
	}

	public function test_no_removal_and_no_lease_remains_selectable(): void {
		$this->seeded( 'clone-selectors.test', str_repeat( '1', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$clock = new SystemClock();

		$this->assertSame( array(), DeletionSchedule::due( 50, $clock ) );
		$this->assertSame(
			array(),
			( new LeaseRecovery( new MutationLease( $clock ), $this->repo, $clock ) )->due( 50 )
		);
	}

	public function test_the_real_sweep_issues_no_provider_mutation_after_a_clone_reset(): void {
		$this->boot();
		$this->seeded( 'clone-sweep.test', str_repeat( '2', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		do_action( 'pd_ssl_sweep' );

		$this->assertSame( 0, $this->driver->remove_calls );
		$this->assertSame( 0, $this->driver->create_calls );
		$this->assertSame( 0, $this->driver->adopt_calls );
		$this->assertSame( 0, $this->driver->method_calls );
	}

	public function test_the_maintenance_pass_issues_no_provider_mutation_after_a_clone_reset(): void {
		$this->boot();
		$this->seeded( 'clone-maintenance.test', str_repeat( '3', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		do_action( 'pd_maintenance' );

		$this->assertSame( 0, $this->driver->remove_calls );
		$this->assertSame( 0, $this->driver->create_calls );
		$this->assertSame( 0, $this->driver->adopt_calls );
		$this->assertSame( 0, $this->driver->method_calls );
	}

	public function test_the_reset_row_still_satisfies_the_repository_invariants(): void {
		$m = $this->seeded( 'clone-invariants.test', str_repeat( '4', 32 ) );
		$this->detect_clone();

		Environment::resolve_as_clone();

		$after = $this->repo->by_id( $m->id );

		$this->assertNotNull( $after );

		// save() runs assert_valid() first, so a round trip is the invariant check.
		$saved = $this->repo->save( $after );

		$this->assertSame( $m->id, $saved->id );
	}
}
