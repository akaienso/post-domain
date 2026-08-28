<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Ssl\MutationKind;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class VerificationRoutesTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private int $mapping_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A fresh server per test, so no test inherits another's routes.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		( new ManagementController( $this->repo, SslServices::production() ) )->register();

		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params(
			array(
				'host'    => 'example.test',
				'post_id' => self::factory()->post->create( array( 'post_status' => 'publish' ) ),
			)
		);

		$this->mapping_id = (int) rest_do_request( $request )->get_data()['id'];
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_transient( 'pd_verify_rate_' . $this->mapping_id );

		parent::tear_down();
	}

	/** @param array<string, string> $headers */
	private function post( string $suffix, array $headers = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains/' . $this->mapping_id . $suffix );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	private function etag(): string {
		return Guard::etag( $this->repo->by_id( $this->mapping_id ) );
	}

	public function test_verify_does_not_require_if_match(): void {
		$this->assertNotSame( 428, $this->post( '/verify' )->get_status(), 'an idempotent probe needs no precondition' );
	}

	public function test_verify_is_rate_limited(): void {
		$this->post( '/verify' );
		$second = $this->post( '/verify' );

		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( Errors::RATE_LIMITED, $second->get_data()['code'] );
	}

	public function test_verify_reports_the_current_state_without_asserting_success(): void {
		$data = $this->post( '/verify' )->get_data();

		$this->assertSame( 'unverified', $data['verification']['state'] );
	}

	public function test_rotating_the_challenge_requires_if_match(): void {
		$this->assertSame( 428, $this->post( '/challenge' )->get_status() );
	}

	public function test_rotating_the_challenge_resets_verification_and_says_so(): void {
		$before   = $this->repo->by_id( $this->mapping_id );
		$response = $this->post( '/challenge', array( 'if_match' => $this->etag() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( $before->challenge, $this->repo->by_id( $this->mapping_id )?->challenge );
		$this->assertStringContainsString( 'unverified', (string) $response->get_data()['note'] );
	}

	public function test_rotating_the_challenge_is_refused_while_a_mutation_is_in_progress(): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '3', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			),
			array( 'id' => $this->mapping_id )
		);

		$response = $this->post( '/challenge', array( 'if_match' => $this->etag() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::MUTATION_IN_PROGRESS, $response->get_data()['code'] );
	}
}
