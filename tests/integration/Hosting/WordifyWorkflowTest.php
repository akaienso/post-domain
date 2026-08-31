<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Admin\Actions;
use PostDomain\Admin\RedirectedAway;
use PostDomain\Admin\SettingsPage;
use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\CredentialSecret;
use PostDomain\Hosting\HostingBinding;
use PostDomain\Hosting\HostingDetection;
use PostDomain\Hosting\HostingProviderFactory;
use PostDomain\Hosting\HostingRecoveryService;
use PostDomain\Hosting\HostingState;
use PostDomain\Hosting\HostingTransitions;
use PostDomain\Hosting\HostingWiring;
use PostDomain\Mapping\DbRepository;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * The administrator workflow, end to end, through production wiring.
 *
 * Only the outbound HTTP transport is substituted. The hook registration, the
 * encrypted credential store, the connection service, the POST handlers, the
 * rendered screen, the shared application command and every CAS are the real
 * ones — because a suite that constructs `WordifyHostingProvider` by hand
 * proves the class works and says nothing about whether the feature does.
 */
final class WordifyWorkflowTest extends OwnedSessionTestCase {

	private FakeWordifyTransport $http;

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();

		delete_option( 'pd_settings' );
		HostingBinding::forget();
		CredentialOptionStore::for_wordpress()->forget();
		DriverFactory::reset();
		HostingProviderFactory::reset();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();
		$this->http = ( new FakeWordifyTransport() )->with_sites( 3 );

		add_filter( 'pd_wordify_http', fn (): FakeWordifyTransport => $this->http );

		// The production hook topology, exactly as Plugin::boot() installs it.
		HostingWiring::register();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A fresh REST server per test, so no test inherits another's routes.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		( new \PostDomain\Rest\ManagementController( $this->repo, \PostDomain\Rest\SslServices::production() ) )->register();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		remove_all_filters( 'pd_wordify_http' );
		remove_all_filters( 'pd_hosting_test_connection' );
		remove_all_filters( 'pd_hosting_has_credential' );
		remove_all_filters( 'pd_hosting_credential_is_external' );
		remove_all_filters( 'pd_hosting_store_credential' );
		remove_all_actions( 'pd_hosting_forget_credential' );
		remove_all_actions( 'pd_ssl_sweep' );

		HostingBinding::forget();
		delete_option( 'pd_settings' );
		HostingProviderFactory::reset();
		parent::tear_down();
	}

	/**
	 * Posts an action the way a browser would.
	 *
	 * @param array<string, string|int> $fields
	 */
	private function post( string $action, array $fields = array() ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->notice              = '';

		$mapping_id = (int) ( $fields['pd_mapping'] ?? 0 );

		$_POST             = array_merge( array( 'pd_action' => $action ), $fields );
		$_POST['_wpnonce'] = wp_create_nonce( Actions::nonce_action( $action, $mapping_id ) );
		$_REQUEST          = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- assembling the request the handler verifies.

		add_filter( 'pd_admin_redirect_should_exit', '__return_false' );

		try {
			Actions::handle();
		} catch ( RedirectedAway $e ) {
			unset( $e );
		} finally {
			remove_filter( 'pd_admin_redirect_should_exit', '__return_false' );
			$_POST                     = array();
			$_REQUEST                  = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			HostingProviderFactory::reset();
		}
	}

	private function page(): string {
		$_GET = array();

		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	private string $notice = '';

	/**
	 * The notice the last action left for the next page load.
	 *
	 * Taking a notice consumes it, exactly as a page render would, so it is held
	 * here and a test may assert on it more than once.
	 */
	private function notices(): string {
		$taken = \PostDomain\Admin\Notices::take();

		if ( null !== $taken ) {
			$this->notice = $taken['message'];
		}

		return $this->notice;
	}

	/** Provider = Wordify, and a token saved through the real POST handler. */
	private function choose_wordify(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::WORDIFY ) );
		$this->post( 'pd_set_wordify_token', array( 'pd_wordify_token' => 'wpk_test-token-not-a-credential' ) );
	}

	/** The full real setup: provider, token, test, and an explicit site choice. */
	private function complete_setup( int $site = 1 ): void {
		$this->choose_wordify();
		$this->post( 'pd_test_wordify' );
		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM,
				'pd_wordify_site'    => $this->http->site_id( $site ),
				'pd_wordify_confirm' => '1',
			)
		);
	}

	private function target(): int {
		return self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
	}

	// ---------------------------------------------------------------- setup --

	public function test_test_connection_has_a_production_listener_and_reads_the_real_endpoints(): void {
		$this->choose_wordify();

		$this->post( 'pd_test_wordify' );

		$this->assertStringNotContainsString( 'could not be tested', $this->notices() );
		$this->assertStringContainsString( 'Test Team', $this->notices() );

		$paths = array_map(
			static fn ( array $call ): string => (string) wp_parse_url( $call['url'], PHP_URL_PATH ),
			$this->http->calls
		);

		$this->assertContains( '/api/v1/me', $paths, 'The identity read is part of the test.' );
		$this->assertContains( '/api/v1/sites', $paths, 'So is the site list.' );

		$this->assertSame(
			array(),
			array_filter( $this->http->calls, static fn ( array $c ): bool => 'GET' !== $c['method'] ),
			'A connection test performs no mutation of any kind.'
		);
	}

	public function test_the_site_list_read_is_paginated_rather_than_unbounded(): void {
		$this->choose_wordify();
		$this->post( 'pd_test_wordify' );

		$list = array_values(
			array_filter(
				$this->http->calls,
				static fn ( array $c ): bool => str_contains( (string) $c['url'], '/sites?' )
			)
		);

		$this->assertNotEmpty( $list );
		$this->assertStringContainsString( 'per_page=', (string) $list[0]['url'] );
		$this->assertStringContainsString( 'page=1', (string) $list[0]['url'] );
	}

	public function test_hundreds_of_sites_stay_reachable_through_search_and_pages(): void {
		$this->http = ( new FakeWordifyTransport() )->with_sites( 400 );
		$this->choose_wordify();

		$page_one = $this->page();

		$this->assertStringContainsString( 'pd_wordify_site', $page_one, 'A selector is rendered.' );
		$this->assertStringContainsString( 'site-1.example', $page_one );
		$this->assertStringNotContainsString( 'site-300.example', $page_one, 'One page, not four hundred rows.' );
		$this->assertStringContainsString( 'More sites', $page_one, 'And a way to reach the rest.' );

		$_GET = array( 'pd_sites_search' => 'site-317.example' );

		ob_start();
		SettingsPage::render();
		$searched = (string) ob_get_clean();

		$this->assertStringContainsString( 'site-317.example', $searched, 'Search reaches a site no page shows first.' );
	}

	public function test_selecting_a_site_re_reads_it_and_stores_a_valid_binding(): void {
		$this->choose_wordify();
		$this->post( 'pd_test_wordify' );

		$this->assertFalse( HostingBinding::current()->is_bound(), 'A passing test is not a binding.' );

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM,
				'pd_wordify_site'    => $this->http->site_id( 2 ),
				'pd_wordify_confirm' => '1',
			)
		);

		$binding = HostingBinding::current();

		$this->assertTrue( $binding->is_bound() );
		$this->assertSame( $this->http->site_id( 2 ), $binding->site_id );
		$this->assertSame( FakeWordifyTransport::TEAM, $binding->team_id );

		$paths = array_map(
			static fn ( array $c ): string => (string) wp_parse_url( $c['url'], PHP_URL_PATH ),
			$this->http->calls
		);

		$this->assertContains(
			'/api/v1/sites/' . $this->http->site_id( 2 ),
			$paths,
			'The exact site is read back before it is trusted.'
		);
	}

	public function test_a_site_cannot_be_bound_without_an_explicit_confirmation(): void {
		$this->choose_wordify();
		$this->post( 'pd_test_wordify' );

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team' => FakeWordifyTransport::TEAM,
				'pd_wordify_site' => $this->http->site_id( 1 ),
			)
		);

		$this->assertFalse( HostingBinding::current()->is_bound() );
		$this->assertStringContainsString( 'Confirm', $this->notices() );
	}

	public function test_several_sites_are_never_chosen_on_the_operators_behalf(): void {
		$this->choose_wordify();
		$this->post( 'pd_test_wordify' );

		$this->assertFalse(
			HostingBinding::current()->is_bound(),
			'Three sites and no choice made is not something to guess at.'
		);
	}

	// --------------------------------------------------------- the real gate --

	public function test_add_a_domain_appears_only_after_the_whole_real_setup(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::WORDIFY ) );
		$this->assertStringNotContainsString( 'pd_add_mapping', $this->page(), 'Wordify chosen, no token.' );

		$this->post( 'pd_set_wordify_token', array( 'pd_wordify_token' => 'wpk_test-token-not-a-credential' ) );
		$this->assertStringNotContainsString( 'pd_add_mapping', $this->page(), 'Token saved, untested.' );

		$this->post( 'pd_test_wordify' );
		$this->assertStringNotContainsString( 'pd_add_mapping', $this->page(), 'Tested, but no site chosen.' );

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM,
				'pd_wordify_site'    => 'not-a-site-this-token-can-read',
				'pd_wordify_confirm' => '1',
			)
		);
		$this->assertStringNotContainsString( 'pd_add_mapping', $this->page(), 'A site that will not read back binds nothing.' );

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM,
				'pd_wordify_site'    => $this->http->site_id( 1 ),
				'pd_wordify_confirm' => '1',
			)
		);

		$this->assertStringContainsString( 'pd_add_mapping', $this->page(), 'Bound: the form appears.' );
	}

	public function test_replacing_the_token_closes_the_form_again(): void {
		$this->complete_setup();
		$this->assertStringContainsString( 'pd_add_mapping', $this->page() );

		$this->post( 'pd_set_wordify_token', array( 'pd_wordify_token' => 'wpk_a-different-token' ) );

		$this->assertStringNotContainsString( 'pd_add_mapping', $this->page() );
	}

	public function test_manual_hosting_keeps_its_existing_add_a_domain_workflow(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::MANUAL ) );

		$this->assertStringContainsString( 'pd_add_mapping', $this->page() );
		$this->assertSame( array(), $this->http->calls, 'Manual hosting contacts no hosting API.' );
	}

	// -------------------------------------------------------- registration --

	public function test_adding_a_domain_makes_exactly_one_attach_and_persists_the_state(): void {
		$this->complete_setup();

		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'club.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$this->assertCount( 1, $this->http->attach_calls(), 'One hostname, one attachment call.' );
		$this->assertSame( array( 'club.example.org' ), $this->http->attached );

		$mapping = $this->repo->by_host( 'club.example.org' );

		$this->assertNotNull( $mapping );
		$this->assertSame( 'wordify', $mapping->hosting_provider );
		$this->assertSame(
			'wordify:' . FakeWordifyTransport::TEAM . ':' . $this->http->site_id( 1 ),
			$mapping->hosting_environment
		);
		$this->assertSame( HostingState::ATTACHED->value, $mapping->hosting_state );
		$this->assertNotNull( $mapping->hosting_ref );
		$this->assertNotNull( $mapping->hosting_registered_at );
	}

	public function test_a_manual_mapping_records_that_no_registration_was_needed(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::MANUAL ) );
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'manual.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'manual.example.org' );

		$this->assertNotNull( $mapping );
		$this->assertSame( HostingState::NOT_REQUIRED->value, $mapping->hosting_state );
		$this->assertSame( array(), $this->http->attach_calls() );
	}

	public function test_a_missing_ability_refuses_once_and_records_no_false_success(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 403,
				'body'   => '{"error":{"code":"forbidden"}}',
			)
		);

		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'refused.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$this->assertCount( 1, $this->http->attach_calls(), 'No retry, and no second write.' );

		$mapping = $this->repo->by_host( 'refused.example.org' );

		$this->assertNotNull( $mapping );
		$this->assertSame( HostingState::REFUSED->value, $mapping->hosting_state );
		$this->assertNull( $mapping->hosting_registered_at, 'Nothing was registered, so nothing is timestamped.' );
		$this->assertStringContainsString( 'Manage Sites', $this->notices() );
		$this->assertStringNotContainsString( 'hosting has been told to accept it', $this->notices() );
	}

	public function test_a_rejected_token_refuses_without_a_follow_up_write(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 401,
				'body'   => '{"error":{"code":"unauthenticated"}}',
			)
		);

		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'rejected.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$this->assertCount( 1, $this->http->attach_calls() );
		$this->assertSame( HostingState::REFUSED->value, $this->repo->by_host( 'rejected.example.org' )?->hosting_state );
	}

	public function test_an_ambiguous_write_persists_recoverable_state(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);

		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'ambiguous.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'ambiguous.example.org' );

		$this->assertNotNull( $mapping );
		$this->assertSame( HostingState::AMBIGUOUS->value, $mapping->hosting_state );
		$this->assertNotNull( $mapping->hosting_ref, 'The attempt is kept, so recovery has something to settle.' );
		$this->assertStringContainsString( 'did not confirm', $this->notices() );
	}

	public function test_production_recovery_settles_an_ambiguous_write_by_reading(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'settle.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		// The write did land at the provider; only the answer was lost.
		$this->http->attached[] = 'settle.example.org';

		$before = count( $this->http->attach_calls() );

		do_action( HostingRecoveryService::HOOK );

		$this->assertCount( $before, $this->http->attach_calls(), 'Recovery reads; it never attaches again.' );
		$this->assertSame( HostingState::ATTACHED->value, $this->repo->by_host( 'settle.example.org' )?->hosting_state );
	}

	public function test_recovery_stops_after_the_bounded_attempts_and_asks_for_a_person(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'stuck.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'stuck.example.org' );
		$this->assertNotNull( $mapping );

		$attaches = count( $this->http->attach_calls() );

		// The provider stops answering reads, so nothing ever settles.
		$this->http->reads_fail_with = 503;

		foreach ( range( 1, \PostDomain\Hosting\HostingReconciler::MAX_ATTEMPTS + 1 ) as $ignored ) {
			do_action( HostingRecoveryService::HOOK );
		}

		$this->assertSame(
			HostingState::MANUAL_REVIEW->value,
			$this->repo->by_id( $mapping->id )?->hosting_state,
			'A read that never settles becomes an actionable state, not an endless job.'
		);
		$this->assertCount( $attaches, $this->http->attach_calls(), 'Recovery never attaches, however long it runs.' );
	}

	public function test_a_hostname_on_another_site_is_refused_rather_than_adopted(): void {
		$this->complete_setup();

		// The write is rejected, and the read that follows finds the hostname on
		// a different site of the same account.
		$this->http->attach_answers(
			array(
				'status' => 422,
				'body'   => '{"error":{"code":"taken"}}',
			)
		);
		$this->http->owned_elsewhere['taken.example.org'] = $this->http->site_id( 3 );

		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'taken.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'taken.example.org' );

		$this->assertNotNull( $mapping );
		$this->assertSame( HostingState::FOREIGN->value, $mapping->hosting_state );
		$this->assertNull( $mapping->hosting_registered_at, 'Nothing was adopted, so nothing is registered.' );
		$this->assertCount( 1, $this->http->attach_calls(), 'Still one write.' );
	}

	// ---------------------------------------------------------- concurrency --

	public function test_two_concurrent_creates_cannot_attach_the_same_hostname_twice(): void {
		$this->complete_setup();

		$mapping = $this->repo->save(
			new \PostDomain\Mapping\Mapping(
				0,
				'race.example.org',
				null,
				$this->target(),
				1,
				\PostDomain\Mapping\VerificationState::UNVERIFIED,
				\PostDomain\Mapping\ActivationState::INACTIVE,
				\PostDomain\Mapping\SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_pd-challenge'
			)
		);

		$transitions = new HostingTransitions();
		$environment = 'wordify:' . FakeWordifyTransport::TEAM . ':' . $this->http->site_id( 1 );

		$first  = $transitions->reserve( $mapping->id, $mapping->revision, 'wordify', $environment );
		$second = $transitions->reserve( $mapping->id, $mapping->revision, 'wordify', $environment );

		$this->assertNotNull( $first, 'The first worker claims the row.' );
		$this->assertNull( $second, 'The second finds it claimed and does not call the provider.' );
	}

	public function test_a_stale_claim_cannot_settle_a_rebound_registration(): void {
		$this->complete_setup();

		$mapping = $this->repo->save(
			new \PostDomain\Mapping\Mapping(
				0,
				'fenced.example.org',
				null,
				$this->target(),
				1,
				\PostDomain\Mapping\VerificationState::UNVERIFIED,
				\PostDomain\Mapping\ActivationState::INACTIVE,
				\PostDomain\Mapping\SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_pd-challenge'
			)
		);

		$transitions = new HostingTransitions();
		$claim       = $transitions->reserve( $mapping->id, $mapping->revision, 'wordify', 'wordify:team-a:site-a' );

		$this->assertNotNull( $claim );

		$stale = new \PostDomain\Hosting\HostingClaim(
			$claim->mapping_id,
			$claim->revision,
			'wordify',
			'wordify:team-b:site-b',
			$claim->attempt
		);

		$this->assertFalse(
			$transitions->attach( $stale, 'dom-x' ),
			'A claim from a different environment settles nothing.'
		);
		$this->assertSame( HostingState::RESERVED->value, $this->repo->by_id( $mapping->id )?->hosting_state );
	}

	public function test_an_ordinary_save_cannot_erase_hosting_authority(): void {
		$this->complete_setup();
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'keep.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$before = $this->repo->by_host( 'keep.example.org' );
		$this->assertNotNull( $before );

		// An edit rebuilt without the trailing hosting fields, as a PATCH body is.
		$this->repo->save(
			new \PostDomain\Mapping\Mapping(
				$before->id,
				$before->host,
				$before->alias_of,
				$before->post_id,
				$before->revision,
				$before->verification_state,
				\PostDomain\Mapping\ActivationState::ACTIVE,
				$before->ssl_state,
				null,
				$before->challenge,
				$before->challenge_label
			)
		);

		$after = $this->repo->by_id( $before->id );

		$this->assertSame( HostingState::ATTACHED->value, $after?->hosting_state );
		$this->assertSame( $before->hosting_ref, $after?->hosting_ref );
		$this->assertSame( $before->hosting_environment, $after?->hosting_environment );
	}

	// ----------------------------------------------------------- credential --

	public function test_a_binding_is_valid_only_under_the_credential_that_proved_it(): void {
		$this->complete_setup();

		$this->assertTrue( HostingBinding::current()->is_bound() );
		$this->assertNotNull( HostingBinding::current()->fingerprint );

		// An external credential changes with no action to hook, so the
		// fingerprint is what catches it.
		CredentialOptionStore::for_wordpress()->forget();
		CredentialOptionStore::for_wordpress()->put( new CredentialSecret( 'wpk_swapped-out-underneath' ) );

		$this->assertFalse(
			HostingBinding::current()->is_valid(),
			'A binding proved by one token is not authority under another.'
		);
	}

	public function test_a_clone_inherits_the_row_and_none_of_the_authority(): void {
		$this->complete_setup();
		$this->assertTrue( HostingBinding::current()->is_bound() );

		// What a restored backup looks like: the same option rows, a different
		// installation.
		delete_option( 'pd_installation_id' );
		Environment::installation_id();

		$this->assertFalse( HostingBinding::current()->is_valid() );
		$this->assertStringNotContainsString( 'pd_add_mapping', $this->page() );
	}

	// ---------------------------------------------------------------- REST --

	public function test_rest_creation_uses_the_same_coordinator_and_attaches_once(): void {
		$this->complete_setup();

		$request = new \WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_param( 'host', 'rest.example.org' );
		$request->set_param( 'post_id', $this->target() );

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->http->attach_calls(), 'REST goes through the same command, and attaches once.' );

		$mapping = $this->repo->by_host( 'rest.example.org' );

		$this->assertSame( HostingState::ATTACHED->value, $mapping?->hosting_state );
		$this->assertSame( 'wordify', $mapping?->hosting_provider );
	}

	public function test_rest_creation_is_refused_when_hosting_is_not_connected(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::WORDIFY ) );

		$request = new \WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_param( 'host', 'unconnected.example.org' );
		$request->set_param( 'post_id', $this->target() );

		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertNull( $this->repo->by_host( 'unconnected.example.org' ), 'Nothing is written when the origin cannot be told.' );
		$this->assertSame( array(), $this->http->attach_calls() );
	}

	// ------------------------------------------------------------ reporting --

	public function test_the_routing_step_stays_open_while_hosting_has_not_confirmed(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'unsettled.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'unsettled.example.org' );
		$this->assertNotNull( $mapping );

		$routing = null;

		foreach ( \PostDomain\Admin\Workflow::steps( $mapping ) as $step ) {
			if ( 2 === $step->number ) {
				$routing = $step;
			}
		}

		$this->assertNotNull( $routing );
		$this->assertNotSame(
			\PostDomain\Admin\Step::DONE,
			$routing->status,
			'A hostname the origin never accepted is not a routed hostname.'
		);

		// Once ownership is proved, the step reports the hosting reason rather
		// than asking for a DNS record that is already correct. Verification
		// moves through pending, which is the transition the row permits.
		$verified = $mapping;

		foreach ( array( \PostDomain\Mapping\VerificationState::PENDING, \PostDomain\Mapping\VerificationState::VERIFIED ) as $state ) {
			$verified = $this->repo->save(
				new \PostDomain\Mapping\Mapping(
					$verified->id,
					$verified->host,
					null,
					$verified->post_id,
					$verified->revision,
					$state,
					$verified->activation_state,
					$verified->ssl_state,
					null,
					$verified->challenge,
					$verified->challenge_label
				)
			);
		}

		foreach ( \PostDomain\Admin\Workflow::steps( $verified ) as $step ) {
			if ( 2 === $step->number ) {
				$this->assertSame( \PostDomain\Admin\Step::WAITING, $step->status );
				$this->assertStringContainsString( 'placeholder page', $step->detail );
			}
		}
	}

	/**
	 * Every local step green, and the origin still never accepted the hostname.
	 *
	 * The state comes from a row rather than from the full setup because that is
	 * exactly the combination being guarded: `summary()` is a function of stored
	 * state, and this is the state in which it used to claim completion.
	 */
	public function test_a_fully_green_mapping_is_not_called_set_up_while_hosting_refused(): void {
		$mapping = $this->repo->save(
			new \PostDomain\Mapping\Mapping(
				0,
				'green.example.org',
				null,
				$this->target(),
				1,
				\PostDomain\Mapping\VerificationState::VERIFIED,
				\PostDomain\Mapping\ActivationState::ACTIVE,
				\PostDomain\Mapping\SslState::ACTIVE,
				null,
				str_repeat( 'd', 32 ),
				'_pd-challenge'
			)
		);

		( new HostingTransitions() )->reserve( $mapping->id, $mapping->revision, 'wordify', 'wordify:t:s' );

		$refused = $this->repo->by_id( $mapping->id );
		$this->assertNotNull( $refused );
		$this->assertSame( HostingState::RESERVED->value, $refused->hosting_state );

		$summary = \PostDomain\Admin\Workflow::summary( $refused );

		$this->assertStringNotContainsString( 'set up and tested', $summary );
	}

	public function test_deleting_a_mapping_reports_the_manual_cleanup_only_wordify_can_do(): void {
		$this->complete_setup();
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'leaving.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'leaving.example.org' );
		$this->assertNotNull( $mapping );

		$this->post(
			'pd_delete_mapping',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision,
			)
		);

		$notice = $this->notices();

		$this->assertStringContainsString( 'leaving.example.org', $notice, 'The hostname to remove by hand is named.' );
		$this->assertStringContainsString( 'still attached', $notice );
		$this->assertStringContainsString( 'Wordify console', $notice );
		$this->assertSame(
			array(),
			array_filter(
				$this->http->calls,
				static fn ( array $c ): bool => 'DELETE' === $c['method']
			),
			'No detachment is invented.'
		);
	}

	public function test_a_manual_mapping_is_deleted_without_a_wordify_warning(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::MANUAL ) );
		$this->post(
			'pd_add_mapping',
			array(
				'pd_host'    => 'plain.example.org',
				'pd_post_id' => $this->target(),
			)
		);

		$mapping = $this->repo->by_host( 'plain.example.org' );
		$this->assertNotNull( $mapping );

		$this->post(
			'pd_delete_mapping',
			array(
				'pd_mapping'  => $mapping->id,
				'pd_revision' => $mapping->revision,
			)
		);

		$this->assertStringNotContainsString( 'Wordify console', $this->notices() );
	}

	public function test_no_credential_ever_reaches_the_rendered_page(): void {
		$this->complete_setup();

		$page = $this->page();

		$this->assertStringNotContainsString( 'wpk_test-token-not-a-credential', $page );
		$this->assertStringNotContainsString( 'wpk_', $page );
	}

	// ------------------------------------------- the result must tell truth --

	/**
	 * @return array{status: int, ok: bool, settled: bool, hosting: string, notice: string, redirect: ?string}
	 */
	private function add_domain( string $host ): array {
		$result = null;

		add_filter(
			'pd_test_command_result',
			static function ( $carry ) use ( &$result ) {
				$result = $carry;

				return $carry;
			}
		);

		$redirect = $this->post_returning_redirect(
			'pd_add_mapping',
			array(
				'pd_host'    => $host,
				'pd_post_id' => $this->target(),
			)
		);

		remove_all_filters( 'pd_test_command_result' );

		$notice = \PostDomain\Admin\Notices::take();

		/** @var array<string, mixed> $hosting */
		$hosting = null === $result || ! isset( $result->payload['hosting'] ) || ! is_array( $result->payload['hosting'] )
			? array()
			: $result->payload['hosting'];

		return array(
			'status'   => null === $result ? 0 : $result->status,
			'ok'       => null !== $result && $result->succeeded,
			'settled'  => true === ( $hosting['settled'] ?? false ),
			'hosting'  => (string) ( $hosting['state'] ?? '' ),
			'notice'   => null === $notice ? '' : $notice['type'] . ':' . $notice['message'],
			'redirect' => $redirect,
		);
	}

	/** @param array<string, string|int> $fields */
	private function post_returning_redirect( string $action, array $fields ): ?string {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$_POST             = array_merge( array( 'pd_action' => $action ), $fields );
		$_POST['_wpnonce'] = wp_create_nonce( Actions::nonce_action( $action, (int) ( $fields['pd_mapping'] ?? 0 ) ) );
		$_REQUEST          = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- assembling the request the handler verifies.

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
			HostingProviderFactory::reset();
		}
	}

	public function test_a_403_refusal_is_not_reported_as_a_successful_creation(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 403,
				'body'   => '{"error":{"code":"forbidden"}}',
			)
		);

		$outcome = $this->add_domain( 'refused-truth.example.org' );

		$this->assertFalse( $outcome['ok'], 'Hosting refused, so the operation did not succeed.' );
		$this->assertSame( 409, $outcome['status'] );
		$this->assertStringStartsWith( 'error:', $outcome['notice'], 'A refusal is not a green notice.' );
		$this->assertStringContainsString( 'Manage Sites', $outcome['notice'] );

		$mapping = $this->repo->by_host( 'refused-truth.example.org' );

		$this->assertNotNull( $mapping, 'The durable row survives, for fencing and for the operator to inspect.' );
		$this->assertStringContainsString(
			'mapping=' . $mapping->id,
			(string) $outcome['redirect'],
			'The operator is taken to the row that exists.'
		);
	}

	public function test_a_401_refusal_is_not_reported_as_a_successful_creation(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 401,
				'body'   => '{"error":{"code":"unauthenticated"}}',
			)
		);

		$outcome = $this->add_domain( 'rejected-truth.example.org' );

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( 409, $outcome['status'] );
		$this->assertStringStartsWith( 'error:', $outcome['notice'] );
	}

	public function test_a_foreign_hostname_is_a_conflict_rather_than_a_creation(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 422,
				'body'   => '{"error":{"code":"taken"}}',
			)
		);
		$this->http->owned_elsewhere['foreign-truth.example.org'] = $this->http->site_id( 3 );

		$outcome = $this->add_domain( 'foreign-truth.example.org' );

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( 409, $outcome['status'] );
		$this->assertStringStartsWith( 'error:', $outcome['notice'] );
		$this->assertNotNull( $this->repo->by_host( 'foreign-truth.example.org' ) );
	}

	public function test_an_ambiguous_write_is_accepted_but_never_called_settled(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);

		$outcome = $this->add_domain( 'pending-truth.example.org' );

		$this->assertSame( 202, $outcome['status'], 'Accepted, not completed.' );
		$this->assertFalse( $outcome['settled'] );
		$this->assertStringStartsWith( 'warning:', $outcome['notice'] );
		$this->assertStringNotContainsString( 'hosting has been told to accept it', $outcome['notice'] );
		$this->assertStringContainsString( 'did not confirm', $outcome['notice'] );
	}

	public function test_a_successful_attachment_is_still_a_201(): void {
		$this->complete_setup();

		$outcome = $this->add_domain( 'happy-truth.example.org' );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 201, $outcome['status'] );
		$this->assertStringStartsWith( 'success:', $outcome['notice'] );
	}

	public function test_manual_hosting_creation_is_still_a_plain_success(): void {
		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::MANUAL ) );

		$outcome = $this->add_domain( 'manual-truth.example.org' );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 201, $outcome['status'] );
		$this->assertStringStartsWith( 'success:', $outcome['notice'] );
	}

	public function test_rest_reports_the_hosting_refusal_rather_than_201(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 403,
				'body'   => '{"error":{"code":"forbidden"}}',
			)
		);

		$request = new \WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_param( 'host', 'rest-refused.example.org' );
		$request->set_param( 'post_id', $this->target() );

		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );

		$body = $response->get_data();

		$this->assertSame( 'pd_hosting_refused', is_array( $body ) ? ( $body['code'] ?? null ) : null );
		$this->assertNotNull( $this->repo->by_host( 'rest-refused.example.org' ), 'The row survives.' );
		$this->assertStringNotContainsString( 'forbidden', (string) wp_json_encode( $body ), 'No provider body escapes.' );
	}

	public function test_rest_reports_an_unconfirmed_attachment_as_accepted(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);

		$request = new \WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_param( 'host', 'rest-pending.example.org' );
		$request->set_param( 'post_id', $this->target() );

		$this->assertSame( 202, rest_do_request( $request )->get_status() );
	}

	// ------------------------------------------------------- lost settlement --

	/**
	 * A write that landed, and a settlement that lost its row.
	 *
	 * The row moves between the provider answering and the CAS running, so the
	 * CAS matches nothing. Reporting REGISTERED here would announce a success
	 * the database never recorded.
	 */
	public function test_a_settlement_that_loses_its_row_is_never_reported_as_success(): void {
		$this->complete_setup();

		// Bump the revision after the provider answers and before the settle.
		add_filter(
			'pd_test_before_hosting_settlement',
			function ( $carry, \PostDomain\Hosting\HostingClaim $claim ) {
				global $wpdb;

				$table = Schema::domains_table();

				$wpdb->query( // phpcs:ignore WordPress.DB
					$wpdb->prepare( "UPDATE {$table} SET revision = revision + 1 WHERE id = %d", $claim->mapping_id ) // phpcs:ignore WordPress.DB
				);

				return $carry;
			},
			10,
			2
		);

		$outcome = $this->add_domain( 'fenced-truth.example.org' );

		remove_all_filters( 'pd_test_before_hosting_settlement' );

		$this->assertSame( 202, $outcome['status'], 'Accepted and unresolved, never a completed creation.' );
		$this->assertSame( 'fenced', $outcome['hosting'] );
		$this->assertFalse( $outcome['settled'], 'The provider answer was never recorded, so nothing is settled.' );
		$this->assertStringStartsWith( 'warning:', $outcome['notice'], 'Not a green notice for a result nothing wrote.' );

		$mapping = $this->repo->by_host( 'fenced-truth.example.org' );

		$this->assertNotNull( $mapping );
		$this->assertSame(
			HostingState::RESERVED->value,
			$mapping->hosting_state,
			'The row stays claimed, which is exactly what recovery settles.'
		);

		$events = array_map(
			static fn ( array $row ): string => (string) $row['type'],
			\PostDomain\Mapping\EventLog::for_domain( $mapping->id )
		);

		$this->assertNotContains( 'hosting.attached', $events, 'No terminal event for a transition that never happened.' );

		// Recovery settles it by reading, with no further attach.
		$attaches = count( $this->http->attach_calls() );

		do_action( HostingRecoveryService::HOOK );

		$this->assertCount( $attaches, $this->http->attach_calls() );
		$this->assertSame( HostingState::ATTACHED->value, $this->repo->by_id( $mapping->id )?->hosting_state );
	}

	// ------------------------------------------------------ retry a refusal --

	public function test_a_refused_attachment_can_be_asked_again_once_the_token_is_fixed(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 403,
				'body'   => '{"error":{"code":"forbidden"}}',
			)
		);

		$this->add_domain( 'retry.example.org' );

		$refused = $this->repo->by_host( 'retry.example.org' );
		$this->assertNotNull( $refused );
		$this->assertSame( HostingState::REFUSED->value, $refused->hosting_state );

		// The screen offers the retry, so the operator is not told to delete and
		// rebuild a mapping to repeat an attachment that never happened.
		$_GET = array( 'mapping' => (string) $refused->id );
		ob_start();
		SettingsPage::render();
		$this->assertStringContainsString( 'pd_retry_hosting', (string) ob_get_clean() );

		$before = count( $this->http->attach_calls() );

		$this->post(
			'pd_retry_hosting',
			array(
				'pd_mapping'  => $refused->id,
				'pd_revision' => $refused->revision,
			)
		);

		$this->assertCount( $before + 1, $this->http->attach_calls(), 'Exactly one more attachment.' );

		$settled = $this->repo->by_id( $refused->id );

		$this->assertSame( HostingState::ATTACHED->value, $settled?->hosting_state );
		$this->assertNotNull( $settled?->hosting_registered_at );
		$this->assertSame( $refused->challenge, $settled?->challenge, 'The mapping was kept, not rebuilt.' );
	}

	public function test_an_unconfirmed_attachment_is_never_retried_by_hand(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 503,
				'body'   => '',
			)
		);
		$this->add_domain( 'no-retry.example.org' );

		$pending = $this->repo->by_host( 'no-retry.example.org' );
		$this->assertNotNull( $pending );
		$this->assertSame( HostingState::AMBIGUOUS->value, $pending->hosting_state );

		$_GET = array( 'mapping' => (string) $pending->id );
		ob_start();
		SettingsPage::render();
		$this->assertStringNotContainsString( 'pd_retry_hosting', (string) ob_get_clean(), 'A write that may have landed is not repeated.' );

		$before = count( $this->http->attach_calls() );

		$this->post(
			'pd_retry_hosting',
			array(
				'pd_mapping'  => $pending->id,
				'pd_revision' => $pending->revision,
			)
		);

		$this->assertCount( $before, $this->http->attach_calls(), 'Even a posted retry sends nothing.' );
		$this->assertSame( HostingState::AMBIGUOUS->value, $this->repo->by_id( $pending->id )?->hosting_state );
	}

	public function test_a_foreign_hostname_is_never_retried_by_hand(): void {
		$this->complete_setup();
		$this->http->attach_answers(
			array(
				'status' => 422,
				'body'   => '{"error":{"code":"taken"}}',
			)
		);
		$this->http->owned_elsewhere['elsewhere.example.org'] = $this->http->site_id( 3 );
		$this->add_domain( 'elsewhere.example.org' );

		$foreign = $this->repo->by_host( 'elsewhere.example.org' );
		$this->assertNotNull( $foreign );
		$this->assertSame( HostingState::FOREIGN->value, $foreign->hosting_state );

		$before = count( $this->http->attach_calls() );

		$this->post(
			'pd_retry_hosting',
			array(
				'pd_mapping'  => $foreign->id,
				'pd_revision' => $foreign->revision,
			)
		);

		$this->assertCount( $before, $this->http->attach_calls() );
		$this->assertSame( HostingState::FOREIGN->value, $this->repo->by_id( $foreign->id )?->hosting_state );
	}
}
