<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class EnvironmentRoutesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A fresh server per test, so no test inherits another's routes.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		( new ManagementController( new DbRepository(), SslServices::production() ) )->register();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( 'pd_environment_mismatch' );

		parent::tear_down();
	}

	private function mismatch(): void {
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();
	}

	/** @param array<string, mixed> $body */
	private function resolve( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/environment/resolve' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	public function test_a_healthy_environment_reports_not_blocked(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/environment' ) )->get_data();

		$this->assertFalse( $data['blocked'] );
		$this->assertNull( $data['mismatch'] );
	}

	public function test_the_installation_id_is_not_exposed(): void {
		$encoded = (string) wp_json_encode(
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/environment' ) )->get_data()
		);

		$this->assertStringNotContainsString( Environment::installation_id(), $encoded );
	}

	public function test_a_mismatch_is_reported_with_both_hosts(): void {
		$this->mismatch();

		$data = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/environment' ) )->get_data();

		$this->assertTrue( $data['blocked'] );
		$this->assertSame( 'old-host.test', $data['mismatch']['stored'] );
	}

	public function test_resolving_as_a_restore_unblocks_and_keeps_identity(): void {
		$this->mismatch();
		$id = Environment::installation_id();

		$this->assertSame( 200, $this->resolve( array( 'resolution' => 'restore' ) )->get_status() );
		$this->assertFalse( Environment::is_blocked() );
		$this->assertSame( $id, Environment::installation_id() );
	}

	public function test_resolving_as_a_clone_replaces_identity(): void {
		$this->mismatch();
		$id = Environment::installation_id();

		$this->assertSame( 200, $this->resolve( array( 'resolution' => 'clone' ) )->get_status() );
		$this->assertNotSame( $id, Environment::installation_id() );
	}

	public function test_an_unknown_resolution_is_rejected(): void {
		$this->mismatch();

		$this->assertSame( 400, $this->resolve( array( 'resolution' => 'whatever' ) )->get_status() );
		$this->assertTrue( Environment::is_blocked(), 'an unrecognized answer resolves nothing' );
	}

	public function test_resolving_a_healthy_environment_is_rejected(): void {
		$this->assertSame( 409, $this->resolve( array( 'resolution' => 'clone' ) )->get_status() );
	}
}
