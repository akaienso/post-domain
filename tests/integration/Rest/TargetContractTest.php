<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Plugin;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use WP_REST_Request;

/**
 * What a mapping may target, over REST.
 *
 * v1.0.0 accepted any existing post, and the specification supports mapping a
 * domain to a private, non-REST custom post type — such a target simply has no
 * REST link. When the admin selector's `pd_admin_target_post_types` filter was
 * briefly consulted by the shared command, REST began refusing exactly those
 * targets: a published contract narrowed silently, and existing sites would
 * have had to opt back in to behaviour they already had.
 *
 * The filter is presentation. Readability is the rule.
 */
final class TargetContractTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Plugin::boot();
		Plugin::instance()->context()->set_host(
			new HostContext( 'primary.test', null, 'primary.test', HostKind::PRIMARY, null, EndpointClass::ROUTED, true, 'GET' )
		);

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		unregister_post_type( 'pd_ledger' );
		remove_all_filters( 'pd_admin_target_post_types' );

		Plugin::instance()->context()->set_host(
			new HostContext( 'mapped.test', null, 'mapped.test', HostKind::MAPPED, null, EndpointClass::ROUTED, true, 'GET' )
		);

		parent::tear_down();
	}

	/** @param array<string, mixed> $body */
	private function create( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	private function private_non_rest_type(): int {
		register_post_type(
			'pd_ledger',
			array(
				'public'       => false,
				'show_in_rest' => false,
				'label'        => 'Ledger',
			)
		);

		return self::factory()->post->create(
			array(
				'post_type'   => 'pd_ledger',
				'post_status' => 'publish',
				'post_title'  => 'Ledger entry',
			)
		);
	}

	public function test_a_private_non_rest_custom_post_type_is_still_a_valid_target(): void {
		$target = $this->private_non_rest_type();

		$response = $this->create(
			array(
				'host'    => 'ledger.example',
				'post_id' => $target,
			)
		);

		$this->assertSame(
			201,
			$response->get_status(),
			'v1.0.0 accepted this and the specification supports it; the admin filter must not have narrowed REST'
		);

		$data = $response->get_data();

		$this->assertSame( $target, $data['target']['id'] ?? null );
		$this->assertSame( 'pd_ledger', $data['target']['post_type'] ?? null );
	}

	public function test_such_a_target_reports_no_rest_link_rather_than_a_fabricated_one(): void {
		$target = $this->private_non_rest_type();

		$data = $this->create(
			array(
				'host'    => 'ledger-link.example',
				'post_id' => $target,
			)
		)->get_data();

		// `??` cannot distinguish a null value from a missing key, so the key is
		// asserted present and its value asserted null separately.
		$this->assertArrayHasKey( 'rest_link', $data['target'] );
		$this->assertArrayHasKey( 'rest_base', $data['target'] );
		$this->assertNull( $data['target']['rest_link'], 'a type outside REST has no REST link' );
		$this->assertNull( $data['target']['rest_base'] );
	}

	public function test_the_admin_selector_filter_does_not_reach_rest(): void {
		$target = $this->private_non_rest_type();

		// Narrowed as far as it goes: the admin would offer pages only.
		add_filter( 'pd_admin_target_post_types', static fn(): array => array( 'page' ) );

		$response = $this->create(
			array(
				'host'    => 'unaffected.example',
				'post_id' => $target,
			)
		);

		$this->assertSame(
			201,
			$response->get_status(),
			'a filter named for the admin selector must not decide the REST contract'
		);
	}

	public function test_a_caller_still_cannot_map_content_they_cannot_read(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$private = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_title'  => 'Board minutes',
				'post_author' => $author,
			)
		);

		// An author may manage mappings here but cannot read another's private page.
		add_filter( 'pd_rest_capability', static fn(): string => 'edit_posts' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$response = $this->create(
			array(
				'host'    => 'unreadable.example',
				'post_id' => $private,
			)
		);

		remove_all_filters( 'pd_rest_capability' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pd_post_invalid', $response->get_data()['code'] ?? null );
		$this->assertNull( $this->repo->by_host( 'unreadable.example' ) );
	}

	public function test_a_target_that_does_not_exist_is_still_refused(): void {
		$response = $this->create(
			array(
				'host'    => 'missing.example',
				'post_id' => 987654,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pd_post_invalid', $response->get_data()['code'] ?? null );
	}
}
