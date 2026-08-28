<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationKind;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class SweepTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Environment::remember_primary_host();
		delete_option( 'pd_settings' );
		DriverFactory::reset();

		$this->repo   = new DbRepository();
		$this->driver = RecordingDriver::ambiguous_then_absent();

		add_filter(
			'pd_ssl_drivers',
			fn( array $drivers ): array => array_merge( $drivers, array( $this->driver ) )
		);
		update_option( 'pd_settings', array( 'ssl_driver' => 'recording' ), false );
		DriverFactory::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function expired_lease(): Mapping {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0,
				'sweep.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::REQUESTED,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => str_repeat( '1', 32 ),
				'ssl_mutation_kind'        => MutationKind::CREATE->value,
				'ssl_mutation_phase'       => 'in_flight',
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() - 600 ),
				'ssl_mutation_driver'      => 'recording',
				'ssl_mutation_environment' => 'recording:default',
			),
			array( 'id' => $m->id )
		);

		return $this->repo->by_id( $m->id );
	}

	public function test_the_sweep_hook_is_registered(): void {
		Plugin::boot();

		$this->assertNotFalse( has_action( 'pd_ssl_sweep' ) );
	}

	public function test_the_sweep_recovers_an_expired_lease(): void {
		$m = $this->expired_lease();

		Plugin::instance()->sweep_ssl();

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_mutation_token, 'a conclusive absence releases the lease' );
		$this->assertSame( 0, $this->driver->create_calls, 'recovery reads; it never re-issues' );
	}

	public function test_the_sweep_builds_no_registry_of_its_own(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );

		$this->assertStringNotContainsString(
			'new \\PostDomain\\Ssl\\SslDriverRegistry',
			$source,
			'cron and REST must resolve drivers through the same factory'
		);
	}

	public function test_the_sweep_leaves_an_unleased_row_to_reconciliation(): void {
		$m = $this->repo->save(
			new Mapping(
				0,
				'plain.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
			)
		);

		Plugin::instance()->sweep_ssl();

		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}
}
