<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Rest\Errors;
use PostDomain\Plugin;
use PostDomain\Rest\ManagementController;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class CollectionTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// A fresh server per test. Routes registered by one test would otherwise
		// still be on the shared one, which is exactly what the discovery test
		// has to be able to observe the absence of.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		remove_all_filters( 'pd_rest_capability' );
		parent::tear_down();
	}

	private function register(): void {
		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** @param array<string, mixed> $body */
	private function post( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	public function test_the_collection_routes_exist_when_registered(): void {
		$this->register();

		$this->assertArrayHasKey( '/post-domain/v1/domains', rest_get_server()->get_routes() );
	}

	public function test_the_namespace_is_absent_from_discovery_when_not_registered(): void {
		// The premise has to be established, not assumed. Under the test harness
		// the site's own host *is* the primary host, so without this the plugin
		// registers its routes legitimately and the test asserts nothing about a
		// mapped host at all.
		Plugin::instance()->context()->set_host(
			new HostContext( 'mapped.test', null, 'mapped.test', HostKind::MAPPED, null, EndpointClass::ROUTED, true, 'GET' )
		);

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/' ) )->get_data();

		$this->assertNotContains(
			'post-domain/v1',
			$data['namespaces'] ?? array(),
			'on a mapped host the namespace must not be enumerable'
		);
	}

	public function test_an_unauthenticated_request_is_forbidden(): void {
		$this->register();
		wp_set_current_user( 0 );

		$this->assertSame(
			403,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_a_subscriber_is_forbidden(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame(
			403,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_an_administrator_is_allowed(): void {
		$this->register();
		$this->admin();

		$this->assertSame(
			200,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_the_capability_is_filterable(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pd_rest_capability', static fn(): string => 'edit_posts' );

		$this->assertSame(
			200,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_the_collection_returns_rows_without_serving(): void {
		$this->register();
		$this->admin();
		$this->post(
			array(
				'host'    => 'example.test',
				'post_id' => $this->post_id,
			)
		);

		$rows = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_data();

		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'serving', $rows[0] );
	}

	public function test_creating_a_mapping_returns_201_with_a_pending_challenge(): void {
		$this->register();
		$this->admin();

		$response = $this->post(
			array(
				'host'    => 'example.test',
				'post_id' => $this->post_id,
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'unverified', $response->get_data()['verification']['state'] );
		$this->assertMatchesRegularExpression(
			'/^post-domain-verify=[0-9a-f]{32}$/',
			$response->get_data()['dns_challenge']['value']
		);
	}

	public function test_a_unicode_host_is_stored_as_punycode(): void {
		$this->register();
		$this->admin();

		$this->assertSame(
			'xn--mnchen-3ya.example',
			$this->post(
				array(
					'host'    => 'münchen.example',
					'post_id' => $this->post_id,
				)
			)->get_data()['host']
		);
	}

	public function test_a_malformed_authority_is_rejected(): void {
		$this->register();
		$this->admin();

		$response = $this->post(
			array(
				'host'    => 'bad host:',
				'post_id' => $this->post_id,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::HOST_MALFORMED_AUTHORITY, $response->get_data()['code'] );
	}

	public function test_a_wildcard_host_is_rejected(): void {
		$this->register();
		$this->admin();

		$response = $this->post(
			array(
				'host'    => '*.example.test',
				'post_id' => $this->post_id,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::HOST_WILDCARD, $response->get_data()['code'] );
	}

	public function test_a_duplicate_host_is_rejected(): void {
		$this->register();
		$this->admin();
		$this->post(
			array(
				'host'    => 'example.test',
				'post_id' => $this->post_id,
			)
		);

		$response = $this->post(
			array(
				'host'    => 'example.test',
				'post_id' => $this->post_id,
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::HOST_EXISTS, $response->get_data()['code'] );
	}

	public function test_a_host_too_long_for_the_composed_txt_name_is_rejected(): void {
		$this->register();
		$this->admin();

		$host = str_repeat( 'a', 60 ) . '.' . str_repeat( 'b', 60 ) . '.'
			. str_repeat( 'c', 60 ) . '.' . str_repeat( 'd', 55 ) . '.test';

		$response = $this->post(
			array(
				'host'    => $host,
				'post_id' => $this->post_id,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertContains( $response->get_data()['code'], array( Errors::HOST_TOO_LONG, Errors::HOST_INVALID ) );
	}

	public function test_an_invalid_post_is_rejected(): void {
		$this->register();
		$this->admin();

		$response = $this->post(
			array(
				'host'    => 'example.test',
				'post_id' => 999999,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::POST_INVALID, $response->get_data()['code'] );
	}
}
