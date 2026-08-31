<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Guide;
use WP_UnitTestCase;

/**
 * The operator guide has to survive three things that have already gone wrong
 * in this plugin's history: markup being filtered away by an allowlist meant
 * for post content, an admin panel that only makes sense with JavaScript, and
 * documentation that lives in the repository where no operator will read it.
 *
 * These tests hold the content itself to account as well. The guide exists to
 * answer specific operator questions; asserting only that a string is non-empty
 * would let it be gutted without a single test noticing.
 */
final class GuideTest extends WP_UnitTestCase {

	public function test_the_panel_returns_markup(): void {
		$html = Guide::render_panel();

		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'Setup guide', $html );
	}

	public function test_the_panel_is_collapsible_without_javascript(): void {
		$html = Guide::render_panel();

		// `<details>` is opened and closed by the browser itself.
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( '<summary', $html );
		$this->assertStringContainsString( '</details>', $html );

		// Nothing in the panel may depend on a script to be readable.
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );

		// Every section must be a `<details>`, not a div that a script reveals.
		$this->assertSame(
			substr_count( $html, '<summary' ),
			substr_count( $html, '<details' )
		);
	}

	public function test_the_guide_never_filters_its_own_markup_through_an_allowlist(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Guide.php' );

		// Passing already-escaped plugin markup through `wp_kses_post()` is what
		// shipped in v1.0.0: that allowlist is for post content, so it dropped
		// every `<select>` from the provider selector. The guide escapes each
		// value where it is written instead, and never re-filters the result.
		$this->assertStringNotContainsString( 'wp_kses', str_replace( '`wp_kses_post()`', '', $source ) );
		$this->assertStringContainsString( 'esc_html', $source );
	}

	/**
	 * @dataProvider required_concepts
	 */
	public function test_the_panel_explains_every_required_concept( string $needle ): void {
		$this->assertStringContainsString( $needle, Guide::render_panel() );
	}

	/** @return array<string, array{0: string}> */
	public static function required_concepts(): array {
		return array(
			'resolved not redirected'  => array( 'resolved, not forwarded' ),
			'wordpress target'         => array( 'WordPress target' ),
			'authoritative dns'        => array( 'Authoritative DNS' ),
			'certificate service'      => array( 'Cloudflare for SaaS' ),
			'cname target'             => array( 'CNAME target' ),
			'fallback origin'          => array( 'fallback origin' ),
			'origin server'            => array( 'WordPress origin server' ),
			'permanent records'        => array( 'Permanent — the ownership record' ),
			'permanent routing'        => array( 'Permanent — the routing record' ),
			'temporary record'         => array( 'Temporary — the certificate service' ),
			'renewal may want it back' => array( 'a renewal can ask for it again' ),
			'ownership record stays'   => array( 'only after the mapping has been deleted' ),
			'stranded mapping'         => array( 'stranded' ),
			'two proofs'               => array( 'may this domain be attached to this certificate account' ),
			'issuance may be slow'     => array( 'It has to spread across the internet first' ),
			'no re-requesting'         => array( 'does not keep asking for a new certificate' ),
			'one minute cooldown'      => array( 'one such check per domain per minute' ),
			'server is authoritative'  => array( 'believe the server' ),
			'background reconcile'     => array( 'on its own schedule, in the background' ),
			'how to test'              => array( 'private or incognito window' ),
			'clone protection'         => array( 'it stands down' ),
			'restore from backup'      => array( 'restoring an old backup' ),
			'origin host prerequisite' => array( 'route it to this same WordPress installation' ),
			'no redirecting alias'     => array( 'Do not use that mode' ),
			'placeholder symptom'      => array( 'parked-domain' ),
		);
	}

	public function test_the_cleanup_order_is_given_and_is_the_right_way_round(): void {
		$html = Guide::render_panel();

		$stop        = strpos( $html, 'Stop serving the domain' );
		$certificate = strpos( $html, 'Remove the certificate from here' );
		$mapping     = strpos( $html, 'Delete the mapping' );
		$dns         = strpos( $html, 'Only then remove the DNS records' );

		$this->assertIsInt( $stop );
		$this->assertIsInt( $certificate );
		$this->assertIsInt( $mapping );
		$this->assertIsInt( $dns );

		$this->assertLessThan( $certificate, $stop );
		$this->assertLessThan( $mapping, $certificate );
		$this->assertLessThan( $dns, $mapping );
	}

	public function test_the_hosting_prerequisite_is_near_the_top(): void {
		$html = Guide::render_panel();

		$hosting         = strpos( $html, 'your hosting must accept the domain' );
		$troubleshooting = strpos( $html, 'does not show the right page' );

		$this->assertIsInt( $hosting );
		$this->assertIsInt( $troubleshooting );

		// It is a prerequisite, so it must be read before the failure it causes.
		$this->assertLessThan( $troubleshooting, $hosting );
	}

	public function test_the_hosting_prerequisite_separates_readiness_from_routing(): void {
		$html = Guide::render_panel();

		$this->assertStringContainsString( 'verified, serving, and certificate active', $html );
		$this->assertStringContainsString( 'None of that makes your web host hand the request', $html );
		$this->assertStringContainsString( 'cannot perform for you', $html );
	}

	public function test_dynamic_looking_values_are_escaped_where_they_are_written(): void {
		$html = Guide::render_panel();

		// The record name is written with a placeholder in angle brackets. If any
		// value reached the page unescaped, this is where it would show.
		$this->assertStringContainsString( '_post-domain-challenge.&lt;your domain&gt;', $html );
		$this->assertStringNotContainsString( '_post-domain-challenge.<', $html );

		// Apostrophes come through the same escaping path.
		$this->assertStringContainsString( '&#039;', $html );

		// No unresolved placeholder ever reaches the page.
		$this->assertStringNotContainsString( '%s', $html );
		$this->assertStringNotContainsString( '%1$s', $html );
	}

	public function test_only_expected_tags_appear_in_the_panel(): void {
		$tags = array();

		preg_match_all( '/<\/?([a-z0-9]+)/i', Guide::render_panel(), $tags );

		$found = array_values( array_unique( array_map( 'strtolower', $tags[1] ) ) );
		sort( $found );

		$this->assertSame(
			array( 'details', 'div', 'h2', 'li', 'ol', 'p', 'summary', 'ul' ),
			$found,
			'An unexpected tag means markup reached the panel from somewhere other than the guide itself.'
		);
	}

	/**
	 * @dataProvider forbidden_vocabulary
	 */
	public function test_the_guide_does_not_explain_itself_in_internal_vocabulary( string $needle ): void {
		$html = Guide::render_panel();

		$this->assertStringNotContainsString( $needle, $html );
	}

	/** @return array<string, array{0: string}> */
	public static function forbidden_vocabulary(): array {
		return array(
			'api token constant'    => array( 'PD_CLOUDFLARE_API_TOKEN' ),
			'cname target constant' => array( 'PD_CLOUDFLARE_CNAME_TARGET' ),
			'zone id constant'      => array( 'PD_CLOUDFLARE_ZONE_ID' ),
			'bearer credential'     => array( 'Bearer ' ),
			'ssl state enum'        => array( 'SslState' ),
			'verification enum'     => array( 'VerificationState' ),
			'activation enum'       => array( 'ActivationState' ),
			'lease phase enum'      => array( 'MutationPhase' ),
			'recovering phase'      => array( 'RECOVERING' ),
			'ownership origin'      => array( 'OwnershipOrigin' ),
			'option name'           => array( 'pd_provider_cooldowns' ),
			'error code'            => array( 'pd_ssl_not_configured' ),
			'http status'           => array( '429' ),
			'class reference'       => array( 'SslDriver' ),
			'spec reference'        => array( 'spec ' ),
			'section reference'     => array( '§' ),
			'repository reference'  => array( 'GitHub' ),
		);
	}

	public function test_help_tabs_register_when_a_screen_exists(): void {
		set_current_screen( 'settings_page_post-domain' );

		$screen = get_current_screen();

		$this->assertInstanceOf( \WP_Screen::class, $screen );

		$before = count( $screen->get_help_tabs() );

		Guide::register_help_tabs();

		$tabs = $screen->get_help_tabs();

		$this->assertGreaterThan( $before, count( $tabs ) );

		$ours = array_filter(
			array_keys( $tabs ),
			static fn( string $id ): bool => str_starts_with( $id, Guide::TAB_PREFIX )
		);

		$this->assertNotEmpty( $ours );

		foreach ( $ours as $id ) {
			$this->assertNotSame( '', (string) $tabs[ $id ]['title'] );
			$this->assertNotSame( '', (string) $tabs[ $id ]['content'] );
		}

		$this->assertStringContainsString( 'Worth remembering', $screen->get_help_sidebar() );
	}

	public function test_the_hosting_prerequisite_is_reachable_from_the_help_tabs(): void {
		set_current_screen( 'settings_page_post-domain' );

		Guide::register_help_tabs();

		$screen = get_current_screen();

		$this->assertInstanceOf( \WP_Screen::class, $screen );

		$tab = $screen->get_help_tab( Guide::TAB_PREFIX . 'hosting-prerequisite' );

		$this->assertIsArray( $tab );
		$this->assertStringContainsString( 'route it to this same WordPress installation', (string) $tab['content'] );
		$this->assertStringNotContainsString( '<script', (string) $tab['content'] );
	}

	public function test_registering_help_tabs_does_not_fatal_without_a_screen(): void {
		unset( $GLOBALS['current_screen'] );

		$this->assertNull( get_current_screen() );

		Guide::register_help_tabs();

		// Reaching this line is the assertion: no fatal, and no screen invented.
		$this->assertNull( get_current_screen() );
	}
}
