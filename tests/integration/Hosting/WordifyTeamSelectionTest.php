<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Admin\Actions;
use PostDomain\Admin\RedirectedAway;
use PostDomain\Admin\SettingsPage;
use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\HostingBinding;
use PostDomain\Hosting\HostingDetection;
use PostDomain\Hosting\HostingProviderFactory;
use PostDomain\Hosting\HostingWiring;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * An account whose token can act for more than one team.
 *
 * `GET /me` names two teams and no current team, so nothing implies which one
 * this installation belongs to. Guessing would list — and could bind — the
 * wrong team's site, so the operator is asked, and only the teams the
 * authenticated response actually named may be chosen.
 */
final class WordifyTeamSelectionTest extends OwnedSessionTestCase {

	private FakeWordifyTransport $http;

	public function set_up(): void {
		parent::set_up();
		Schema::install();

		delete_option( 'pd_settings' );
		HostingBinding::forget();
		CredentialOptionStore::for_wordpress()->forget();
		HostingProviderFactory::reset();
		Environment::remember_primary_host();

		// Twelve sites: 1-6 in the first team, 7-12 in the second.
		$this->http = ( new FakeWordifyTransport() )
			->with_sites( 12 )
			->with_two_teams()
			->assign_sites( FakeWordifyTransport::TEAM, 1, 6 )
			->assign_sites( FakeWordifyTransport::TEAM2, 7, 12 );

		add_filter( 'pd_wordify_http', fn (): FakeWordifyTransport => $this->http );

		HostingWiring::register();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->post( 'pd_set_hosting', array( 'pd_hosting_provider' => HostingDetection::WORDIFY ) );
		$this->post( 'pd_set_wordify_token', array( 'pd_wordify_token' => 'wpk_two-team-token-not-a-credential' ) );
	}

	public function tear_down(): void {
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
		CredentialOptionStore::for_wordpress()->forget();
		delete_option( 'pd_settings' );
		HostingProviderFactory::reset();
		parent::tear_down();
	}

	/** @param array<string, string|int> $fields */
	private function post( string $action, array $fields = array() ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$_POST             = array_merge( array( 'pd_action' => $action ), $fields );
		$_POST['_wpnonce'] = wp_create_nonce( Actions::nonce_action( $action, 0 ) );
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

	/** @param array<string, string> $query */
	private function page( array $query = array() ): string {
		$_GET = $query;

		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	private function notice(): string {
		$taken = \PostDomain\Admin\Notices::take();

		return null === $taken ? '' : $taken['type'] . ':' . $taken['message'];
	}

	private function choose_second_team(): void {
		$this->post( 'pd_select_wordify_team', array( 'pd_wordify_team' => FakeWordifyTransport::TEAM2 ) );
	}

	public function test_both_teams_are_offered_and_no_site_is_guessed(): void {
		$page = $this->page();

		$this->assertStringContainsString( 'pd_select_wordify_team', $page, 'A team choice is offered.' );
		$this->assertStringContainsString( 'First Team', $page );
		$this->assertStringContainsString( 'Second Team', $page );

		$this->assertStringNotContainsString( 'pd_wordify_site', $page, 'No site list is drawn before a team is chosen.' );
		$this->assertStringNotContainsString( 'site-1.example', $page );
		$this->assertStringNotContainsString( 'pd_add_mapping', $page, 'And nothing may be added.' );

		$this->assertSame(
			array(),
			array_filter(
				$this->http->calls,
				static fn ( array $c ): bool => str_contains( (string) $c['url'], '/sites' )
			),
			'Nothing even asks for a site list while the team is unknown.'
		);
	}

	public function test_choosing_the_second_team_lists_only_that_teams_sites(): void {
		$this->choose_second_team();

		$page = $this->page();

		$this->assertStringContainsString( 'site-7.example', $page );
		$this->assertStringContainsString( 'site-12.example', $page );
		$this->assertStringNotContainsString( 'site-1.example', $page, 'The other team\'s sites are not offered.' );
		$this->assertStringNotContainsString( 'site-6.example', $page );
		$this->assertStringContainsString( 'Second Team', $page );

		$this->assertContains(
			FakeWordifyTransport::TEAM2,
			$this->http->team_headers,
			'The listing is made as the chosen team.'
		);
		$this->assertNotContains( FakeWordifyTransport::TEAM, $this->http->team_headers );
	}

	public function test_search_stays_inside_the_selected_team(): void {
		$this->choose_second_team();

		// A site that exists, in the other team. The search box echoes whatever
		// was typed, so the assertion is on the offered row, not on the string
		// appearing anywhere on the page.
		$across = $this->page( array( 'pd_sites_search' => 'site-3.example' ) );

		$this->assertStringNotContainsString(
			'value="' . $this->http->site_id( 3 ) . '"',
			$across,
			'Search never reaches across into a team the operator did not choose.'
		);
		$this->assertStringContainsString( 'No Wordify site matched that search', $across );

		$within = $this->page( array( 'pd_sites_search' => 'site-9.example' ) );

		$this->assertStringContainsString( 'value="' . $this->http->site_id( 9 ) . '"', $within );
	}

	public function test_pagination_stays_inside_the_selected_team(): void {
		$this->http = ( new FakeWordifyTransport() )
			->with_sites( 60 )
			->with_two_teams()
			->assign_sites( FakeWordifyTransport::TEAM, 1, 30 )
			->assign_sites( FakeWordifyTransport::TEAM2, 31, 60 );

		$this->choose_second_team();

		$second_page = $this->page( array( 'pd_sites_page' => '2' ) );

		$this->assertStringContainsString( 'site-56.example', $second_page );
		$this->assertStringNotContainsString( 'site-2.example', $second_page );

		foreach ( $this->http->team_headers as $header ) {
			if ( '' !== $header ) {
				$this->assertSame( FakeWordifyTransport::TEAM2, $header );
			}
		}
	}

	public function test_binding_confirms_the_exact_site_in_the_exact_team(): void {
		$this->choose_second_team();

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM2,
				'pd_wordify_site'    => $this->http->site_id( 9 ),
				'pd_wordify_confirm' => '1',
			)
		);

		$binding = HostingBinding::current();

		$this->assertTrue( $binding->is_bound() );
		$this->assertSame( FakeWordifyTransport::TEAM2, $binding->team_id );
		$this->assertSame( $this->http->site_id( 9 ), $binding->site_id );

		$reads = array_filter(
			$this->http->calls,
			fn ( array $c ): bool => str_ends_with( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/sites/' . $this->http->site_id( 9 ) )
		);

		$this->assertNotEmpty( $reads, 'The exact site is read back before it is trusted.' );

		foreach ( $reads as $read ) {
			/** @var array<string, string> $headers */
			$headers = $read['opts']['headers'];
			$this->assertSame( FakeWordifyTransport::TEAM2, $headers['X-Wordify-Team'] );
		}

		$this->assertStringContainsString( 'pd_add_mapping', $this->page() );
	}

	public function test_a_site_from_another_team_cannot_be_bound(): void {
		$this->choose_second_team();

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM2,
				'pd_wordify_site'    => $this->http->site_id( 2 ),
				'pd_wordify_confirm' => '1',
			)
		);

		$this->assertFalse( HostingBinding::current()->is_bound() );
		$this->assertStringStartsWith( 'error:', $this->notice() );
	}

	public function test_a_team_the_account_does_not_name_is_refused(): void {
		$this->post( 'pd_select_wordify_team', array( 'pd_wordify_team' => '01HQTEAMNOTMINE0000000000X' ) );

		$this->assertStringStartsWith( 'error:', $this->notice() );
		$this->assertNull( HostingBinding::current()->team_id, 'Nothing was recorded.' );
		$this->assertSame(
			array(),
			array_filter(
				$this->http->calls,
				static fn ( array $c ): bool => 'GET' !== $c['method']
			),
			'And nothing was mutated.'
		);
	}

	public function test_a_posted_team_absent_from_the_account_binds_nothing(): void {
		$this->choose_second_team();

		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => '01HQTEAMNOTMINE0000000000X',
				'pd_wordify_site'    => $this->http->site_id( 9 ),
				'pd_wordify_confirm' => '1',
			)
		);

		$this->assertFalse( HostingBinding::current()->is_bound() );
		$this->assertStringStartsWith( 'error:', $this->notice() );
		$this->assertSame(
			array(),
			array_filter(
				$this->http->calls,
				static fn ( array $c ): bool => 'GET' !== $c['method']
			)
		);
	}

	public function test_changing_the_team_drops_a_site_chosen_under_the_previous_one(): void {
		$this->post( 'pd_select_wordify_team', array( 'pd_wordify_team' => FakeWordifyTransport::TEAM ) );
		$this->post(
			'pd_select_wordify_site',
			array(
				'pd_wordify_team'    => FakeWordifyTransport::TEAM,
				'pd_wordify_site'    => $this->http->site_id( 3 ),
				'pd_wordify_confirm' => '1',
			)
		);

		$this->assertTrue( HostingBinding::current()->is_bound() );

		$this->choose_second_team();

		$rebound = HostingBinding::current();

		$this->assertFalse( $rebound->is_bound(), 'A site in the old team is not a site in the new one.' );
		$this->assertNull( $rebound->site_id );
		$this->assertSame( FakeWordifyTransport::TEAM2, $rebound->team_id );
	}
}
