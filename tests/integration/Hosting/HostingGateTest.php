<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Admin\SettingsPage;
use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\CredentialSecret;
use PostDomain\Hosting\HostingBinding;
use PostDomain\Hosting\HostingDetection;
use PostDomain\Hosting\HostingReadiness;
use PostDomain\Hosting\WordifySite;
use PostDomain\Hosting\WordifyTeam;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * Whether a domain may be added at all.
 *
 * A domain mapped on a Wordify site that the plugin cannot tell Wordify about
 * will verify, receive a certificate, and then serve the host's placeholder
 * page — the precise failure this plugin already exists to make visible. So the
 * form is withheld until the connection is real, and the reason is stated.
 */
final class HostingGateTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_settings' );
		delete_option( 'pd_hosting_binding' );
		delete_option( 'pd_environment_mismatch' );
		CredentialOptionStore::for_wordpress()->forget();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( 'pd_settings' );
		delete_option( 'pd_hosting_binding' );
		CredentialOptionStore::for_wordpress()->forget();
		remove_all_filters( 'pd_hosting_has_credential' );
		remove_all_filters( 'pd_hosting_platform' );
		$_GET = array();
		parent::tear_down();
	}

	private function page(): string {
		$_GET = array();

		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	private function choose( string $provider ): void {
		update_option( 'pd_settings', array( 'hosting_provider' => $provider ), false );
	}

	/**
	 * A real encrypted credential, not a stubbed answer.
	 *
	 * A binding is only valid under the credential that proved it, and the
	 * comparison is against what the store actually holds — so a gate test that
	 * faked the answer would be testing a state the plugin cannot be in.
	 */
	private function credential( bool $present ): void {
		$store = CredentialOptionStore::for_wordpress();

		$store->forget();

		if ( $present ) {
			$store->put( new CredentialSecret( 'wpk_gate-test-token-not-a-credential' ) );
		}
	}

	/** Binds the way `HostingActions::select_site()` does, after a confirmed read. */
	private function bind(): void {
		HostingBinding::bind(
			new WordifyTeam( 'team_01', 'Example Team' ),
			new WordifySite( '01JEXAMPLEULIDCHARSHERE123', 'active', 'example.test', 'example.test', 'example.test', false )
		);
	}

	// -- 1. Wordify selected, no token ---------------------------------------

	public function test_without_a_token_the_add_form_is_absent(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( false );

		$html = $this->page();

		$this->assertStringNotContainsString( 'name="pd_host"', $html, 'no way to add a domain yet' );
		$this->assertStringNotContainsString( 'value="pd_add_mapping"', $html );
		$this->assertFalse( HostingReadiness::evaluate()->may_add_domains );
	}

	public function test_the_page_explains_why_and_what_to_do(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( false );

		$html = $this->page();

		$this->assertStringContainsString( 'has not been given a Wordify API token', $html );
		$this->assertStringContainsString( 'Test connection', $html );
		$this->assertStringContainsString( 'name="pd_wordify_token"', $html );
	}

	// -- 2 and 3. Credential present but not yet valid ------------------------

	public function test_a_token_alone_is_not_enough(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );

		$readiness = HostingReadiness::evaluate();

		$this->assertFalse( $readiness->may_add_domains, 'an unvalidated token authorizes nothing' );
		$this->assertStringNotContainsString( 'name="pd_host"', $this->page() );
	}

	public function test_a_validated_binding_opens_the_form(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );
		$this->bind();

		$this->assertTrue( HostingReadiness::evaluate()->may_add_domains );
		$this->assertStringContainsString( 'name="pd_host"', $this->page() );
	}

	public function test_the_connected_team_and_site_are_shown(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );
		$this->bind();

		$html = $this->page();

		$this->assertStringContainsString( 'Example Team', $html );
		$this->assertStringContainsString( 'example.test', $html );
	}

	// -- a credential that stops working -------------------------------------

	public function test_an_invalidated_credential_closes_the_form_again(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );
		$this->bind();

		$this->assertTrue( HostingReadiness::evaluate()->may_add_domains );

		// Exactly what replacing or revoking a token does.
		HostingBinding::invalidate();

		$this->assertFalse( HostingReadiness::evaluate()->may_add_domains );
		$this->assertStringNotContainsString( 'name="pd_host"', $this->page() );
	}

	public function test_existing_mappings_keep_serving_when_the_credential_fails(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );
		$this->bind();

		$mapping = $this->repo->save(
			new Mapping(
				0,
				'already-serving.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'h', 32 ),
				'_post-domain-challenge'
			)
		);

		HostingBinding::invalidate();

		$after = $this->repo->by_id( $mapping->id );

		$this->assertNotNull( $after );
		$this->assertSame( ActivationState::ACTIVE, $after->activation_state, 'serving is untouched' );
		$this->assertSame( VerificationState::VERIFIED, $after->verification_state );
		$this->assertSame( SslState::ACTIVE, $after->ssl_state );
		$this->assertSame( $mapping->revision, $after->revision, 'and no state was rewritten' );
	}

	// -- 9. Manual hosting is unchanged --------------------------------------

	public function test_manual_hosting_needs_no_token_and_keeps_the_form(): void {
		$this->choose( HostingDetection::MANUAL );
		$this->credential( false );

		$html = $this->page();

		$this->assertTrue( HostingReadiness::evaluate()->may_add_domains );
		$this->assertStringContainsString( 'name="pd_host"', $html );
		$this->assertStringNotContainsString( 'name="pd_wordify_token"', $html );
	}

	public function test_manual_is_the_default_when_nothing_is_detected(): void {
		$this->assertSame( HostingDetection::MANUAL, HostingDetection::selected() );
		$this->assertTrue( HostingReadiness::evaluate()->may_add_domains );
	}

	// -- detection is advisory ------------------------------------------------

	public function test_detection_does_not_rest_on_a_hostname_substring(): void {
		$detected = HostingDetection::detect();

		$this->assertContains( $detected['provider'], array( HostingDetection::WORDIFY, HostingDetection::MANUAL ) );
		$this->assertIsArray( $detected['signals'] );
		$this->assertNotContains(
			'hostname_substring',
			$detected['signals'],
			'a domain containing the word is not evidence of anything'
		);
	}

	public function test_a_host_may_declare_the_platform_outright(): void {
		add_filter( 'pd_hosting_platform', static fn(): string => HostingDetection::WORDIFY );

		$detected = HostingDetection::detect();

		$this->assertSame( HostingDetection::WORDIFY, $detected['provider'] );
		$this->assertTrue( $detected['confident'] );
	}

	public function test_an_explicit_choice_overrules_detection(): void {
		add_filter( 'pd_hosting_platform', static fn(): string => HostingDetection::WORDIFY );

		$this->choose( HostingDetection::MANUAL );

		$this->assertSame(
			HostingDetection::MANUAL,
			HostingDetection::selected(),
			'detection suggests; the operator decides'
		);
	}

	public function test_the_page_says_detection_grants_no_access(): void {
		add_filter( 'pd_hosting_platform', static fn(): string => HostingDetection::WORDIFY );

		$this->assertStringContainsString( 'grants no access', $this->page() );
	}

	// -- 19. Clone safety ----------------------------------------------------

	public function test_a_clone_cannot_act_on_the_original_installations_binding(): void {
		$this->credential( true );
		$this->bind();

		$this->assertTrue( HostingBinding::current()->is_bound() );

		// A restored backup: same rows, different installation.
		delete_option( 'pd_installation_id' );
		Environment::installation_id();

		$this->assertFalse(
			HostingBinding::current()->is_valid(),
			'a copy inherits the row but not the authority'
		);
		$this->assertFalse( HostingBinding::current()->is_bound() );
	}

	public function test_resolving_as_a_clone_withdraws_hosting_authority(): void {
		$this->credential( true );
		$this->bind();

		Environment::resolve_as_clone();

		$binding = HostingBinding::current();

		$this->assertFalse( $binding->is_valid(), 'reconnection is required' );
		$this->assertSame(
			'01JEXAMPLEULIDCHARSHERE123',
			$binding->site_id,
			'the site choice is ordinary configuration and is kept'
		);
	}

	public function test_a_clone_reset_clears_hosting_registration_state(): void {
		global $wpdb;

		$mapping = $this->repo->save(
			new Mapping(
				0,
				'cloned-host.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'j', 32 ),
				'_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'hosting_provider'    => 'wordify',
				'hosting_environment' => 'wordify:team_01:01JEXAMPLEULIDCHARSHERE123',
				'hosting_ref'         => 'dom_123',
				'hosting_state'       => 'registered',
			),
			array( 'id' => $mapping->id )
		);

		Environment::resolve_as_clone();

		$after = $this->repo->by_id( $mapping->id );

		$this->assertNotNull( $after );
		$this->assertNull( $after->hosting_ref, 'the registration belongs to the source installation' );
		$this->assertNull( $after->hosting_environment );
		$this->assertNull( $after->hosting_provider );
	}

	// -- 18. Disconnect ------------------------------------------------------

	public function test_disconnecting_leaves_mappings_alone(): void {
		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );
		$this->bind();

		$mapping = $this->repo->save(
			new Mapping(
				0,
				'kept.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'k', 32 ),
				'_post-domain-challenge'
			)
		);

		HostingBinding::forget();

		$this->assertNotNull( $this->repo->by_id( $mapping->id ), 'no mapping is deleted' );
		$this->assertSame( ActivationState::ACTIVE, $this->repo->by_id( $mapping->id )?->activation_state );
	}

	// -- 11. The main site is untouched --------------------------------------

	public function test_nothing_here_changes_the_sites_own_urls(): void {
		$home    = get_option( 'home' );
		$siteurl = get_option( 'siteurl' );

		$this->choose( HostingDetection::WORDIFY );
		$this->credential( true );
		$this->bind();
		$this->page();

		$this->assertSame( $home, get_option( 'home' ), 'home is never rewritten' );
		$this->assertSame( $siteurl, get_option( 'siteurl' ), 'siteurl is never rewritten' );
	}
}
