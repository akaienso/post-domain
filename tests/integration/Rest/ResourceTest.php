<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use WP_REST_Request;

/**
 * Deleting a mapping with no provider resource commits through AtomicTransition,
 * which correctly refuses inside the harness's ambient transaction.
 */
final class ResourceTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A fresh server per test, so no test inherits another's routes.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/** @param array<string, mixed> $body */
	private function create_mapping( string $host = 'example.test', array $body = array() ): array {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params(
			array_merge(
				array(
					'host'    => $host,
					'post_id' => $this->post_id,
				),
				$body
			)
		);

		return rest_do_request( $request )->get_data();
	}

	/** @param array<string, mixed> $body */
	private function patch( int $id, array $body, ?string $etag ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $id );
		$request->set_body_params( $body );

		if ( null !== $etag ) {
			$request->set_header( 'if_match', $etag );
		}

		return rest_do_request( $request );
	}

	private function etag( int $id ): string {
		return Guard::etag( $this->repo->by_id( $id ) );
	}

	public function test_show_returns_the_resource_with_an_etag(): void {
		$created  = $this->create_mapping();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/' . $created['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->etag( $created['id'] ), $response->get_headers()['ETag'] );
		$this->assertArrayHasKey( 'serving', $response->get_data() );
	}

	public function test_show_returns_404_for_an_unknown_id(): void {
		$this->assertSame(
			404,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/424242' ) )->get_status()
		);
	}

	public function test_patch_without_if_match_is_428(): void {
		$created = $this->create_mapping();

		$this->assertSame( 428, $this->patch( $created['id'], array( 'activation_state' => 'active' ), null )->get_status() );
	}

	public function test_patch_with_a_stale_if_match_is_412(): void {
		$created = $this->create_mapping();

		$this->assertSame(
			412,
			$this->patch( $created['id'], array( 'activation_state' => 'active' ), '"' . $created['id'] . '-99"' )->get_status()
		);
	}

	public function test_patch_with_a_current_if_match_succeeds(): void {
		$created  = $this->create_mapping();
		$response = $this->patch( $created['id'], array( 'activation_state' => 'active' ), $this->etag( $created['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['activation']['state'] );
	}

	public function test_patch_cannot_set_verification_state(): void {
		$created = $this->create_mapping();

		$this->patch( $created['id'], array( 'verification_state' => 'verified' ), $this->etag( $created['id'] ) );

		$this->assertSame(
			VerificationState::UNVERIFIED,
			$this->repo->by_id( $created['id'] )?->verification_state,
			'no request makes a mapping verified'
		);
	}

	public function test_patch_cannot_set_ssl_state(): void {
		$created = $this->create_mapping();

		$this->patch( $created['id'], array( 'ssl_state' => 'active' ), $this->etag( $created['id'] ) );

		$this->assertSame( 'none', $this->repo->by_id( $created['id'] )?->ssl_state->value );
	}

	public function test_patch_rejects_a_post_id_on_an_alias(): void {
		$canonical = $this->create_mapping( 'canonical.test' );
		$alias     = $this->create_mapping(
			'alias.test',
			array(
				'alias_of' => $canonical['id'],
				'post_id'  => null,
			)
		);

		$response = $this->patch( $alias['id'], array( 'post_id' => $this->post_id ), $this->etag( $alias['id'] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::ALIAS_NO_TARGET, $response->get_data()['code'] );
	}

	public function test_deleting_a_canonical_with_aliases_is_409(): void {
		$canonical = $this->create_mapping( 'canonical.test' );
		$this->create_mapping(
			'alias.test',
			array(
				'alias_of' => $canonical['id'],
				'post_id'  => null,
			)
		);

		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $canonical['id'] );
		$request->set_header( 'if_match', $this->etag( $canonical['id'] ) );

		$this->assertSame( 409, rest_do_request( $request )->get_status() );
	}

	public function test_delete_without_if_match_is_428(): void {
		$created = $this->create_mapping();

		$this->assertSame(
			428,
			rest_do_request( new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $created['id'] ) )->get_status()
		);
	}

	public function test_deleting_a_mapping_with_no_provider_resource_removes_it(): void {
		$created = $this->create_mapping();

		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $created['id'] );
		$request->set_header( 'if_match', $this->etag( $created['id'] ) );

		$this->assertSame( 204, rest_do_request( $request )->get_status() );
		$this->assertNull( $this->repo->by_id( $created['id'] ) );
	}
}
