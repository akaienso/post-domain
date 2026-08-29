<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Plugin;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Support\Schema;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The namespace is registered, not guarded. On any host but the primary it does
 * not exist at all, so it is absent from dispatch and from /wp-json/ discovery.
 */
final class RouteRegistrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Plugin::boot();
	}

	private function host( HostKind $kind ): void {
		Plugin::instance()->context()->set_host(
			new HostContext( 'example.test', null, 'example.test', $kind, null, EndpointClass::ROUTED, true, 'GET' )
		);
	}

	/** @return string[] */
	private function routes(): array {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();

		// Through the action, not the method: core refuses a route registered
		// anywhere else, and the hook is half of what is under test.
		do_action( 'rest_api_init', $wp_rest_server );

		return array_keys( $wp_rest_server->get_routes() );
	}

	public function test_the_routes_exist_on_the_primary_host(): void {
		$this->host( HostKind::PRIMARY );

		$this->assertContains( '/post-domain/v1/domains', $this->routes() );
	}

	public function test_the_routes_do_not_exist_on_a_mapped_host(): void {
		$this->host( HostKind::MAPPED );

		$this->assertNotContains( '/post-domain/v1/domains', $this->routes() );
	}

	public function test_the_routes_do_not_exist_without_a_host_context(): void {
		$this->assertNotContains( '/post-domain/v1/domains', $this->routes() );
	}

	public function test_the_hook_is_registered(): void {
		$this->assertNotFalse( has_action( 'rest_api_init', array( Plugin::instance(), 'register_rest_routes' ) ) );
	}
}
