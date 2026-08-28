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
use PostDomain\Ssl\Cooldown;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MutationAuthorization;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class DeletionAuthorizerTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_provider_cooldowns' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		$this->repo = new DbRepository();
		Environment::remember_primary_host();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function proof( DnsOutcome $outcome ): FreshProof {
		return new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);
	}

	private function authorizer( RecordingDriver $driver, FreshProof $proof ): DeletionAuthorizer {
		// Drivers reach production through one factory, so tests supply them the
		// same way a site would rather than injecting a private registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new DeletionAuthorizer(
			$this->repo,
			$proof,
			new MutationLease( new SystemClock() ),
			new SystemClock()
		);
	}

	private function deletable( string $host = 'mapped.test' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				substr( md5( $host ), 0, 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	private function assert_released( int $mapping_id ): void {
		$this->assertNull(
			$this->repo->by_id( $mapping_id )?->ssl_mutation_token,
			'a refusal before consumption must release the reservation'
		);
	}

	public function test_all_preconditions_met_yields_an_authorization(): void {
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertIsArray( $result );
		$this->assertInstanceOf( MutationAuthorization::class, $result['auth'] );
	}

	public function test_environment_unresolved(): void {
		update_option(
			'pd_environment_mismatch',
			array(
				'stored'  => 'a',
				'current' => 'b',
			),
			false
		);

		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'environment_unresolved', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_driver_not_registered(): void {
		global $wpdb;

		// A row bound to a provider nobody registers: unreadable, so unmutable.
		// The binding stays complete — only which driver it names changes.
		$m = $this->deletable();
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_provider' => 'absent-driver' ),
			array( 'id' => $m->id )
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->repo->by_id( $m->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'driver_not_registered', $result->precondition );
	}

	public function test_no_configured_driver_refuses_by_name(): void {
		// A genuinely unbound mapping, not a half-cleared one: nulling only
		// ssl_provider would leave a partial binding the repository forbids.
		$m = $this->repo->save(
			new Mapping(
				0,
				'unbound.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'u', 32 ),
				'_post-domain-challenge'
			)
		);

		delete_option( 'pd_settings' );
		DriverFactory::reset();

		$result = ( new DeletionAuthorizer(
			$this->repo,
			$this->proof( DnsOutcome::MATCH ),
			new MutationLease( new SystemClock() ),
			new SystemClock()
		) )->authorize( $this->repo->by_id( $m->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame(
			'ssl_not_configured',
			$result->precondition,
			'never a silent NullDriver no-op'
		);
	}

	public function test_provider_cooldown(): void {
		Cooldown::set( 'recording', 300, '429' );

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'provider_cooldown', $result->precondition );
	}

	public function test_lease_unavailable(): void {
		$m = $this->deletable();
		( new MutationLease( new SystemClock() ) )->acquire(
			$m->id,
			$m->revision,
			MutationKind::CREATE,
			RecordingDriver::succeeding( 'ref-1' )
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->repo->by_id( $m->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'lease_unavailable', $result->precondition );
	}

	public function test_identity_not_confirmed(): void {
		$m      = $this->deletable();
		$result = $this->authorizer(
			RecordingDriver::with_identity( IdentityVerdict::MISMATCH ),
			$this->proof( DnsOutcome::MATCH )
		)->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'identity_not_confirmed', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_identity_incomplete_is_transient(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::with_incomplete_identity(), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertTrue( $result->transient );
		$this->assert_released( $m->id );
	}

	public function test_conflicting_marker(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::with_foreign_marker(), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'conflicting_marker', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_no_ownership_authority(): void {
		$m = $this->repo->save(
			new Mapping(
				0,
				'unowned.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				// A resource that exists and whose identity confirms, owned by a
				// different installation. The plan seeded ssl_provider alone,
				// but the durable binding moves as one and an unbound row is
				// refused earlier, at the identity check, so it could never
				// reach the ownership precondition this test names.
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				'another-installation',
				'recording',
				'recording:default',
				'ref-1'
			)
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_a_foreign_owner_has_no_authority(): void {
		$m = $this->repo->save(
			new Mapping(
				0,
				'foreign.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				'someone-elses-installation',
				'recording',
				'recording:default',
				'ref-1'
			)
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
	}

	public function test_fresh_proof_failed(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MISMATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'fresh_proof_failed', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_fresh_proof_transient(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::TRANSIENT ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertTrue( $result->transient );
		$this->assert_released( $m->id );
	}

	public function test_cached_verification_is_not_a_fresh_proof(): void {
		$m = $this->deletable();

		$this->assertSame( VerificationState::VERIFIED, $m->verification_state );

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::NO_RECORD ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
	}

	public function test_authorization_survives_pruning_every_event(): void {
		global $wpdb;

		$m = $this->deletable();
		$wpdb->query( 'DELETE FROM ' . Schema::events_table() ); // phpcs:ignore WordPress.DB

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertIsArray(
			$result,
			'ownership provenance is column state, so pruning history changes nothing'
		);
	}
}
