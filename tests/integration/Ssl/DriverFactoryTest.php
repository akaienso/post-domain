<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Ssl\NullDriver;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Tests\Unit\Ssl\Fixtures\IdentityDriver;
use WP_UnitTestCase;

final class DriverFactoryTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		$this->repo = new DbRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function select( string $id ): void {
		update_option( 'pd_settings', array( 'ssl_driver' => $id ), false );
		DriverFactory::reset();
	}

	private function add_recording_driver(): void {
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ): array {
				$drivers[] = RecordingDriver::succeeding( 'ref-1' );

				return $drivers;
			}
		);
		DriverFactory::reset();
	}

	private function mapping( ?string $provider ): Mapping {
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
				'_post-domain-challenge',
				// The durable binding moves as one, so a bound row carries all
				// five columns; the factory reads only ssl_provider from it.
				null === $provider ? null : OwnershipOrigin::CREATED,
				null === $provider ? null : 'install-a',
				$provider,
				null === $provider ? null : 'env-1',
				null === $provider ? null : 'ref-1'
			)
		);
	}

	public function test_the_null_driver_is_always_registered(): void {
		$this->assertInstanceOf( NullDriver::class, DriverFactory::registry()->default() );
		$this->assertContains( DriverFactory::NULL_DRIVER, DriverFactory::registry()->ids() );
	}

	public function test_the_default_registry_rejects_nothing(): void {
		// The fallback appears in the documented filter default, so a naive loop
		// would report the healthy default configuration as having a duplicate.
		$this->assertSame(
			array(),
			DriverFactory::registry()->rejected(),
			'a healthy site must not report a rejected driver'
		);
	}

	public function test_a_different_driver_claiming_the_null_id_is_refused(): void {
		add_filter(
			'pd_ssl_drivers',
			static fn( array $drivers ): array => array_merge( $drivers, array( new IdentityDriver( 'null', 'impostor' ) ) )
		);
		DriverFactory::reset();

		$this->assertInstanceOf( NullDriver::class, DriverFactory::registry()->get( 'null' ) );
		$this->assertSame( 'driver_id_duplicate', DriverFactory::registry()->rejected()[0]->reason );
	}

	public function test_the_registry_is_the_same_object_for_every_caller(): void {
		$this->assertSame(
			DriverFactory::registry(),
			DriverFactory::registry(),
			'REST and cron must not be able to build different registries'
		);
	}

	public function test_no_selection_means_ssl_is_not_configured(): void {
		$result = DriverFactory::for_new_mapping();

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'ssl_not_configured', $result->reason );
	}

	public function test_an_unknown_selected_driver_is_a_named_refusal(): void {
		$this->select( 'not-a-driver' );

		$result = DriverFactory::for_new_mapping();

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'driver_not_registered', $result->reason );
		$this->assertSame( 'not-a-driver', $result->driver_id );
	}

	public function test_a_filter_added_driver_becomes_selectable(): void {
		$this->add_recording_driver();
		$this->select( 'recording' );

		$this->assertInstanceOf( RecordingDriver::class, DriverFactory::for_new_mapping() );
	}

	public function test_a_mapping_with_no_provider_resolves_through_the_selection(): void {
		$this->add_recording_driver();
		$this->select( 'recording' );

		$this->assertInstanceOf( RecordingDriver::class, DriverFactory::for_mapping( $this->mapping( null ) ) );
	}

	public function test_a_mapping_with_no_provider_is_never_silently_answered_by_the_null_driver(): void {
		$result = DriverFactory::for_mapping( $this->mapping( null ) );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'ssl_not_configured', $result->reason );
	}

	public function test_an_existing_mapping_keeps_its_stored_provider(): void {
		$this->add_recording_driver();
		$this->select( DriverFactory::NULL_DRIVER );

		$this->assertInstanceOf(
			RecordingDriver::class,
			DriverFactory::for_mapping( $this->mapping( 'recording' ) ),
			'a bound row is never reinterpreted by the current selection'
		);
	}

	public function test_a_stored_provider_with_no_registered_driver_is_a_named_refusal(): void {
		$result = DriverFactory::for_mapping( $this->mapping( 'retired-driver' ) );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'driver_not_registered', $result->reason );
	}

	public function test_a_deliberate_null_selection_still_reads_existing_null_rows(): void {
		$this->select( DriverFactory::NULL_DRIVER );

		$this->assertInstanceOf(
			NullDriver::class,
			DriverFactory::for_mapping( $this->mapping( DriverFactory::NULL_DRIVER ) )
		);
	}

	public function test_a_driver_with_a_malformed_id_is_refused_not_registered(): void {
		add_filter(
			'pd_ssl_drivers',
			static fn( array $drivers ): array => array_merge( $drivers, array( new IdentityDriver( 'Bad Id', 'ok' ) ) )
		);
		DriverFactory::reset();

		$this->assertNotContains( 'Bad Id', DriverFactory::registry()->ids() );
		$this->assertNotSame( array(), DriverFactory::registry()->rejected() );
		$this->assertSame( 'driver_id_syntax', DriverFactory::registry()->rejected()[0]->reason );
	}

	public function test_a_driver_with_an_unrenderable_environment_is_refused(): void {
		add_filter(
			'pd_ssl_drivers',
			static fn( array $drivers ): array => array_merge( $drivers, array( new IdentityDriver( 'weird', "zone\n1" ) ) )
		);
		DriverFactory::reset();

		$this->assertNotContains( 'weird', DriverFactory::registry()->ids() );
		$this->assertSame( 'environment_id_syntax', DriverFactory::registry()->rejected()[0]->reason );
	}

	public function test_a_duplicate_driver_id_does_not_replace_the_registered_one(): void {
		$first = RecordingDriver::succeeding( 'ref-1' );

		add_filter(
			'pd_ssl_drivers',
			static fn( array $drivers ): array => array_merge(
				$drivers,
				array( $first, new IdentityDriver( 'recording', 'impostor' ) )
			)
		);
		DriverFactory::reset();
		$this->select( 'recording' );

		$this->assertSame( $first, DriverFactory::for_new_mapping(), 'first registration wins' );
		$this->assertSame( 'driver_id_duplicate', DriverFactory::registry()->rejected()[0]->reason );
	}

	public function test_a_refused_driver_never_yields_a_lease(): void {
		add_filter(
			'pd_ssl_drivers',
			static fn( array $drivers ): array => array_merge( $drivers, array( new IdentityDriver( 'Bad Id', 'ok' ) ) )
		);
		DriverFactory::reset();
		$this->select( 'Bad Id' );

		$result = DriverFactory::for_new_mapping();

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'driver_not_registered', $result->reason );
	}

	public function test_only_bound_resource_resolves_a_mapping_in_production(): void {
		// The environment check lives in BoundResource. A production caller that
		// went to DriverFactory directly would skip it, take a lease bound to the
		// wrong environment, and then pass every later check self-consistently.
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() || 'BoundResource.php' === $file->getFilename() ) {
				continue;
			}

			if ( str_contains( (string) file_get_contents( $file->getPathname() ), 'DriverFactory::for_mapping' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'DriverFactory::for_mapping() has exactly one production caller: BoundResource::driver_for()'
		);
	}

	public function test_a_filter_returning_junk_is_ignored_rather_than_fatal(): void {
		add_filter( 'pd_ssl_drivers', static fn(): array => array( 'not a driver', 42 ) );
		DriverFactory::reset();

		$this->assertInstanceOf( NullDriver::class, DriverFactory::registry()->default() );
	}
}
