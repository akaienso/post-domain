<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Ssl\BoundResource;
use PostDomain\Ssl\CloudflareSaasDriver;
use PostDomain\Ssl\Credentials;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class CloudflareRegistrationTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_settings' );
		delete_option( 'pd_ssl_credentials' );
		DriverFactory::reset();
		$this->repo = new DbRepository();
	}

	public function tear_down(): void {
		delete_option( 'pd_ssl_credentials' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/** @param array<string, string> $overrides */
	private function configure( array $overrides = array() ): void {
		update_option(
			'pd_ssl_credentials',
			array_merge(
				array(
					'api_token'    => 'cf-token-value',
					'zone_id'      => 'zone-1',
					'cname_target' => 'saas.example.net',
				),
				$overrides
			),
			false
		);
		update_option( 'pd_settings', array( 'ssl_driver' => 'cloudflare-saas' ), false );
		DriverFactory::reset();
	}

	private function unprovisioned(): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_complete_credentials_register_the_driver(): void {
		$this->configure();

		$this->assertTrue( Credentials::cloudflare_is_configured() );
		$this->assertContains( 'cloudflare-saas', DriverFactory::registry()->ids() );
	}

	public function test_a_new_mapping_provisions_through_cloudflare(): void {
		$this->configure();

		$this->assertInstanceOf(
			CloudflareSaasDriver::class,
			DriverFactory::for_mapping( $this->unprovisioned() ),
			'a stored provider of null must resolve through the configured selection'
		);
	}

	/**
	 * @dataProvider missing_credentials
	 */
	public function test_incomplete_credentials_register_nothing( string $missing ): void {
		$this->configure( array( $missing => '' ) );

		$this->assertFalse( Credentials::cloudflare_is_configured() );
		$this->assertNotContains( 'cloudflare-saas', DriverFactory::registry()->ids() );
	}

	/** @return array<string, array{0: string}> */
	public static function missing_credentials(): array {
		return array(
			'no api token'    => array( 'api_token' ),
			'no zone id'      => array( 'zone_id' ),
			'no cname target' => array( 'cname_target' ),
		);
	}

	public function test_missing_credentials_produce_a_named_configuration_refusal(): void {
		$this->configure( array( 'api_token' => '' ) );

		$result = DriverFactory::for_mapping( $this->unprovisioned() );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'driver_not_registered', $result->reason );
		$this->assertSame( 'cloudflare-saas', $result->driver_id );
	}

	public function test_no_credential_value_is_exposed_by_the_registry(): void {
		$this->configure();

		$encoded = (string) wp_json_encode( DriverFactory::registry()->ids() );

		$this->assertStringNotContainsString( 'cf-token-value', $encoded );
	}

	public function test_the_environment_identity_names_the_zone_and_holds_no_credential(): void {
		$this->configure();

		$driver = DriverFactory::registry()->get( 'cloudflare-saas' );

		$this->assertSame( 'cf-zone:zone-1', $driver?->environment_id() );
		$this->assertStringNotContainsString( 'cf-token-value', (string) $driver?->environment_id() );
	}

	public function test_a_different_zone_is_a_different_environment(): void {
		$this->configure();
		$first = DriverFactory::registry()->get( 'cloudflare-saas' )?->environment_id();

		$this->configure( array( 'zone_id' => 'zone-2' ) );
		$second = DriverFactory::registry()->get( 'cloudflare-saas' )?->environment_id();

		$this->assertNotSame( $first, $second, 'recovery has to be able to tell these apart' );
	}

	public function test_a_provisioned_mapping_records_the_zone_it_lives_in(): void {
		$this->configure();

		// A resource bound in zone-1 stays readable only while zone-1 is configured.
		$bound = $this->repo->save(
			new Mapping(
				0,
				'bound.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'cloudflare-saas',
				'cf-zone:zone-1',
				'ref-1'
			)
		);

		$this->assertInstanceOf( CloudflareSaasDriver::class, BoundResource::driver_for( $bound ) );

		$this->configure( array( 'zone_id' => 'zone-2' ) );

		$refusal = BoundResource::driver_for( $this->repo->by_id( $bound->id ) );

		$this->assertInstanceOf( DriverUnavailable::class, $refusal );
		// Each identifier in its own field: overloading driver_id with an
		// environment is precisely what the typed refusal exists to prevent.
		$this->assertSame( 'provider_environment_changed', $refusal->reason );
		$this->assertSame( 'cloudflare-saas', $refusal->driver_id );
		$this->assertSame( 'cf-zone:zone-1', $refusal->expected_environment, 'what to restore' );
		$this->assertSame( 'cf-zone:zone-2', $refusal->configured_environment, 'what is configured now' );
	}

	public function test_rest_and_cron_resolve_the_same_driver_instance(): void {
		$this->configure();

		$mapping = $this->unprovisioned();

		$this->assertSame(
			DriverFactory::for_mapping( $mapping ),
			DriverFactory::for_mapping( $mapping ),
			'one registry, one instance, whichever entry point asked'
		);
	}
}
