<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\SettingsPage;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Credentials;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * Tests the page an administrator actually sees.
 *
 * Every other admin test in this suite asserts on a helper's return value.
 * v1.0.0 shipped with `render_driver_selection()` returning perfectly good
 * markup that `render()` then passed through `wp_kses_post()`, which drops
 * `<select>` and `<option>` because they are not post content. The helper tests
 * all passed and the operator saw the words "Cloudflare for SaaSNone" run
 * together as plain text.
 *
 * So these capture the complete output of `render()` and assert against that.
 */
final class RenderedScreenTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_settings' );
		delete_option( 'pd_ssl_credentials' );
		DriverFactory::reset();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( 'pd_settings' );
		delete_option( 'pd_ssl_credentials' );
		DriverFactory::reset();
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
	}

	/** The complete page, exactly as WordPress would emit it. */
	private function page(): string {
		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	private function configure_cloudflare(): void {
		update_option(
			'pd_ssl_credentials',
			array(
				'api_token'    => 'cf-token-value',
				'zone_id'      => 'zone-1',
				'cname_target' => 'saas.example.net',
			),
			false
		);
		DriverFactory::reset();
	}

	private function seed( string $host ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				self::factory()->post->create(
					array(
						'post_status' => 'publish',
						'post_title'  => 'Club home',
					)
				),
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				substr( md5( $host ), 0, 32 ),
				'_post-domain-challenge'
			)
		);
	}

	// -- the v1.0.0 defect ---------------------------------------------------

	public function test_the_rendered_page_contains_a_real_driver_select(): void {
		$html = $this->page();

		$this->assertMatchesRegularExpression(
			'/<select[^>]*\bname=["\']pd_ssl_driver["\']/',
			$html,
			'the operator needs a select element, not the option labels run together as text'
		);
		$this->assertMatchesRegularExpression( '/<option[^>]*value=["\'][^"\']*["\']/', $html );
		$this->assertStringNotContainsString( 'disabled', $this->select_markup( $html ) );
	}

	public function test_the_driver_select_offers_cloudflare_with_a_readable_label(): void {
		$this->configure_cloudflare();

		$html   = $this->page();
		$select = $this->select_markup( $html );

		$this->assertStringContainsString( 'value="cloudflare-saas"', $select );
		$this->assertMatchesRegularExpression(
			'/<option[^>]*value="cloudflare-saas"[^>]*>\s*Cloudflare/i',
			$select,
			'a raw driver id is not a label an operator should have to decode'
		);
	}

	public function test_the_select_survives_rendering_rather_than_being_stripped_to_text(): void {
		$this->configure_cloudflare();

		$html = $this->page();

		// The exact v1.0.0 symptom: labels present, element absent.
		$labels_present  = str_contains( $html, 'Cloudflare' );
		$element_present = (bool) preg_match( '/<select[^>]*pd_ssl_driver/', $html );

		$this->assertTrue( $labels_present, 'the provider label must appear' );
		$this->assertTrue(
			$element_present,
			'provider names rendered as text with no <select> is exactly the v1.0.0 failure'
		);
	}

	public function test_no_credential_value_reaches_the_page(): void {
		$this->configure_cloudflare();

		$html = $this->page();

		$this->assertStringNotContainsString( 'cf-token-value', $html );
		$this->assertStringNotContainsString( 'saas.example.net', $html );
	}

	private function select_markup( string $html ): string {
		if ( 1 !== preg_match( '/<select[^>]*pd_ssl_driver.*?<\/select>/s', $html, $m ) ) {
			return '';
		}

		return $m[0];
	}
}
