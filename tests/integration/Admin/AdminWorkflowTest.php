<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Actions;
use PostDomain\Admin\RedirectedAway;
use PostDomain\Admin\Screen;
use PostDomain\Admin\SettingsPage;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * The workflow an administrator completes without touching REST, WP-CLI or the
 * database.
 *
 * These drive the rendered page and the real POST handler. v1.0.0's admin
 * screen passed every helper-level test it had and could not add a domain at
 * all, because nothing asserted on what the page as a whole actually contained
 * or on what a posted form actually did.
 */
final class AdminWorkflowTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_POST                     = array();
		$_REQUEST                  = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		remove_all_filters( 'pd_admin_redirect_should_exit' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function page( int $mapping_id = 0 ): string {
		$_GET = 0 === $mapping_id ? array() : array( 'mapping' => (string) $mapping_id );

		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Posts an admin action the way a browser would, and returns where it was
	 * redirected. A missing key is simply omitted, so a test can post without one.
	 *
	 * @param array<string, string|int> $fields
	 */
	private function post( string $action, array $fields = array(), bool $with_nonce = true ): ?string {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$mapping_id = (int) ( $fields['pd_mapping'] ?? 0 );

		$_POST = array_merge( array( 'pd_action' => $action ), $fields );

		if ( $with_nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( Actions::nonce_action( $action, $mapping_id ) );
		}

		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- assembling the request the handler verifies.

		add_filter( 'pd_admin_redirect_should_exit', '__return_false' );

		try {
			Actions::handle();

			return null;
		} catch ( RedirectedAway $e ) {
			return $e->url;
		} finally {
			remove_filter( 'pd_admin_redirect_should_exit', '__return_false' );
			$_POST                     = array();
			$_REQUEST                  = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}
	}

	private function target(): int {
		return self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Club home',
				'post_type'   => 'page',
			)
		);
	}

	private function seed(
		VerificationState $verification = VerificationState::UNVERIFIED,
		ActivationState $activation = ActivationState::INACTIVE,
		SslState $ssl = SslState::NONE,
		bool $bound = false
	): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"admin-{$this->seq}.test",
				null,
				$this->target(),
				1,
				$verification,
				$activation,
				$ssl,
				null,
				str_pad( (string) $this->seq, 32, 'a', STR_PAD_LEFT ),
				'_post-domain-challenge',
				$bound ? OwnershipOrigin::CREATED : null,
				$bound ? Environment::installation_id() : null,
				$bound ? 'recording' : null,
				$bound ? 'recording:default' : null,
				$bound ? 'ref-1' : null
			)
		);
	}

	// -- rendering -----------------------------------------------------------

	public function test_an_empty_installation_offers_a_way_to_add_a_domain(): void {
		$html = $this->page();

		$this->assertMatchesRegularExpression( '/<input[^>]*name=["\']pd_host["\']/', $html );
		$this->assertMatchesRegularExpression( '/<select[^>]*name=["\']pd_post_id["\']/', $html );
		$this->assertStringContainsString( 'No domains are mapped yet', $html );
		$this->assertStringNotContainsString( '<thead>', $html, 'a bare header row is not an empty state' );
	}

	public function test_the_target_selector_lists_real_content(): void {
		$id = $this->target();

		$this->assertMatchesRegularExpression(
			'/<option value="' . $id . '">\s*Club home\s*<\/option>/',
			$this->page()
		);
	}

	public function test_the_add_form_carries_a_nonce_and_its_action(): void {
		$html = $this->page();

		$this->assertStringContainsString( 'name="pd_action" value="pd_add_mapping"', $html );
		$this->assertMatchesRegularExpression( '/name=["\']_wpnonce["\']/', $html );
	}

	public function test_a_created_mapping_appears_with_a_link_to_its_controls(): void {
		$mapping = $this->seed();

		$html = $this->page();

		$this->assertStringContainsString( $mapping->host, $html );
		$this->assertStringContainsString( 'mapping=' . $mapping->id, $html );
	}

	public function test_the_detail_view_shows_plain_language_before_enum_names(): void {
		$mapping = $this->seed();

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'Not verified yet', $html );
		$this->assertStringContainsString( 'Not serving', $html );
		$this->assertStringContainsString( 'No certificate', $html );
		$this->assertStringContainsString( 'Publish the TXT record below', $html );

		// The technical name stays available, but not as the explanation.
		$this->assertLessThan(
			strpos( $html, 'unverified' ),
			strpos( $html, 'Not verified yet' ),
			'the readable label must come before the enum value'
		);
	}

	public function test_the_detail_view_shows_the_dns_record_to_publish(): void {
		$mapping = $this->seed();

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'DNS records to publish', $html );
		$this->assertStringContainsString( '_post-domain-challenge.' . $mapping->host, $html );
		$this->assertStringContainsString( 'post-domain-verify=', $html );
	}

	// -- lifecycle gating ----------------------------------------------------

	public function test_serving_controls_appear_only_once_verified(): void {
		$unverified = $this->page( $this->seed()->id );

		$this->assertStringNotContainsString( 'pd_activate', $unverified );

		$verified = $this->page( $this->seed( VerificationState::VERIFIED )->id );

		$this->assertStringContainsString( 'pd_activate', $verified );
		$this->assertStringContainsString( 'Start serving', $verified );
	}

	public function test_an_active_mapping_offers_to_stop_serving(): void {
		$html = $this->page( $this->seed( VerificationState::VERIFIED, ActivationState::ACTIVE )->id );

		$this->assertStringContainsString( 'pd_deactivate', $html );
		$this->assertStringNotContainsString( 'value="pd_activate"', $html );
	}

	public function test_a_certificate_can_be_requested_only_when_verified_and_serving(): void {
		$this->assertStringNotContainsString( 'pd_provision_ssl', $this->page( $this->seed()->id ) );

		$this->assertStringNotContainsString(
			'pd_provision_ssl',
			$this->page( $this->seed( VerificationState::VERIFIED )->id ),
			'a domain that is not serving has nothing to certify'
		);

		$this->assertStringContainsString(
			'pd_provision_ssl',
			$this->page( $this->seed( VerificationState::VERIFIED, ActivationState::ACTIVE )->id )
		);
	}

	public function test_removing_a_certificate_is_offered_only_when_one_is_held(): void {
		$this->assertStringNotContainsString( 'pd_remove_ssl', $this->page( $this->seed()->id ) );

		$bound = $this->seed( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		$this->assertStringContainsString( 'pd_remove_ssl', $this->page( $bound->id ) );
	}

	public function test_a_leased_mapping_offers_no_controls_at_all(): void {
		global $wpdb;

		$mapping = $this->seed( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => str_repeat( '5', 32 ),
				'ssl_mutation_kind'        => 'create',
				'ssl_mutation_phase'       => 'in_flight',
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'ssl_mutation_driver'      => 'recording',
				'ssl_mutation_environment' => 'recording:default',
			),
			array( 'id' => $mapping->id )
		);

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'A certificate operation is running', $html );
		$this->assertStringNotContainsString( 'value="pd_provision_ssl"', $html );
		$this->assertStringNotContainsString( 'value="pd_delete_mapping"', $html );
	}

	// -- mutations -----------------------------------------------------------

	public function test_an_administrator_can_add_a_domain(): void {
		$target = $this->target();

		$url = $this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'added.example',
				'pd_post_id' => $target,
			)
		);

		$mapping = $this->repo->by_host( 'added.example' );

		$this->assertNotNull( $mapping, 'the domain must exist after the form is submitted' );
		$this->assertSame( $target, $mapping->post_id );
		$this->assertNotNull( $url, 'a mutation must redirect so a refresh does not repeat it' );
		$this->assertStringContainsString( 'mapping=' . $mapping->id, (string) $url );
	}

	public function test_adding_a_domain_without_a_nonce_changes_nothing(): void {
		add_filter(
			'wp_die_handler',
			static fn(): callable => static function (): void {
			throw new \RuntimeException( 'nonce' );
			}
		);

		try {
			$this->post(
				'pd_add_mapping',
				array(
					'pd_host'    => 'nonce.example',
					'pd_post_id' => $this->target(),
				),
				false
			);
			$this->fail( 'a missing nonce must stop the request' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_all_filters( 'wp_die_handler' );
		}

		$this->assertNull( $this->repo->by_host( 'nonce.example' ) );
	}

	public function test_a_subscriber_cannot_add_a_domain(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		add_filter(
			'wp_die_handler',
			static fn(): callable => static function (): void {
			throw new \RuntimeException( 'capability' );
			}
		);

		try {
			$this->post(
				'pd_add_mapping',
				array(
					'pd_host'    => 'sub.example',
					'pd_post_id' => $this->target(),
				)
			);
			$this->fail( 'a subscriber must not reach the command' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_all_filters( 'wp_die_handler' );
		}

		$this->assertNull( $this->repo->by_host( 'sub.example' ) );
	}

	/** @dataProvider bad_input */
	public function test_invalid_input_is_refused_without_creating_anything( string $host, int|string $post ): void {
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => $host,
				'pd_post_id' => $post,
			)
		);

		$this->assertSame( array(), $this->repo->all(), 'nothing may be created from invalid input' );
	}

	/** @return array<string, array{0: string, 1: int|string}> */
	public static function bad_input(): array {
		return array(
			'a wildcard'     => array( '*.example', 1 ),
			'not a host'     => array( 'not a host at all', 1 ),
			'empty host'     => array( '', 1 ),
			'missing target' => array( 'valid.example', 0 ),
			'unknown target' => array( 'valid.example', 999999 ),
		);
	}

	public function test_a_duplicate_domain_is_refused(): void {
		$existing = $this->seed();

		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => $existing->host,
				'pd_post_id' => $this->target(),
			)
		);

		$this->assertCount( 1, $this->repo->all() );
	}

	public function test_activation_moves_the_mapping(): void {
		$mapping = $this->seed( VerificationState::VERIFIED );

		$this->post(
			'pd_activate',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision,
			)
		);

		$this->assertSame( ActivationState::ACTIVE, $this->repo->by_id( $mapping->id )?->activation_state );
	}

	public function test_a_stale_revision_is_refused_and_changes_nothing(): void {
		$mapping = $this->seed( VerificationState::VERIFIED );

		$this->post(
			'pd_activate',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision - 1,
			)
		);

		$this->assertSame(
			ActivationState::INACTIVE,
			$this->repo->by_id( $mapping->id )?->activation_state,
			'a page drawn before someone else changed the row must not overwrite them'
		);
	}

	public function test_a_missing_revision_is_refused(): void {
		$mapping = $this->seed( VerificationState::VERIFIED );

		$this->post( 'pd_activate', array( 'pd_mapping' => $mapping->id ) );

		$this->assertSame( ActivationState::INACTIVE, $this->repo->by_id( $mapping->id )?->activation_state );
	}

	public function test_a_nonce_for_one_action_does_not_authorize_another(): void {
		$mapping = $this->seed( VerificationState::VERIFIED );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'pd_action'   => 'pd_delete_mapping',
			'pd_mapping'  => $mapping->id,
			'pd_revision' => $mapping->revision,
			// A nonce minted for a different button on the same row.
			'_wpnonce'    => wp_create_nonce( Actions::nonce_action( 'pd_activate', $mapping->id ) ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- deliberately mismatched, and verified by the handler.

		add_filter(
			'wp_die_handler',
			static fn(): callable => static function (): void {
			throw new \RuntimeException( 'nonce' );
			}
		);
		add_filter( 'pd_admin_redirect_should_exit', '__return_false' );

		try {
			Actions::handle();
			$this->fail( 'a nonce bound to another action must not be accepted' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_all_filters( 'wp_die_handler' );
			remove_filter( 'pd_admin_redirect_should_exit', '__return_false' );
			$_POST                     = array();
			$_REQUEST                  = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		$this->assertNotNull( $this->repo->by_id( $mapping->id ), 'and nothing may be deleted' );
	}

	public function test_verification_is_requested_and_rate_limited(): void {
		$mapping = $this->seed();

		$this->post(
			'pd_verify',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision,
			)
		);

		$this->assertNotFalse(
			wp_next_scheduled( 'pd_verify_now', array( $mapping->id ) ),
			'the probe is scheduled rather than run in the request'
		);
		$this->assertNotFalse( get_transient( 'pd_verify_rate_' . $mapping->id ) );
	}

	public function test_provisioning_without_a_driver_is_refused_in_plain_language(): void {
		$mapping = $this->seed( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$this->post(
			'pd_provision_ssl',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision,
			)
		);

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( SslState::NONE, $after?->ssl_state, 'a refusal must not move the state' );
		$this->assertNull( $after?->ssl_ref );
	}

	public function test_deleting_a_mapping_with_no_provider_resource_removes_it(): void {
		$mapping = $this->seed( VerificationState::VERIFIED );

		$url = $this->post(
			'pd_delete_mapping',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision,
			)
		);

		$this->assertNull( $this->repo->by_id( $mapping->id ) );
		$this->assertStringNotContainsString( 'mapping=', (string) $url, 'the detail page for it is gone too' );
	}

	public function test_a_get_request_performs_no_mutation(): void {
		$mapping = $this->seed( VerificationState::VERIFIED );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array(
			'pd_action'   => 'pd_delete_mapping',
			'pd_mapping'  => $mapping->id,
			'pd_revision' => $mapping->revision,
			'_wpnonce'    => wp_create_nonce( Actions::nonce_action( 'pd_delete_mapping', $mapping->id ) ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a GET, which the handler refuses outright.

		$this->assertFalse( Actions::handle(), 'mutations are POST-only' );
		$this->assertNotNull( $this->repo->by_id( $mapping->id ) );

		$_POST    = array();
		$_REQUEST = array();
	}

	public function test_the_target_post_types_come_from_the_architecture_not_a_hard_coded_list(): void {
		$this->assertContains( 'page', Screen::target_post_types() );
		$this->assertContains( 'post', Screen::target_post_types() );

		add_filter( 'pd_admin_target_post_types', static fn(): array => array( 'page' ) );

		$this->assertSame( array( 'page' ), Screen::target_post_types() );

		remove_all_filters( 'pd_admin_target_post_types' );
	}
}
