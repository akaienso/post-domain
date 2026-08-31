<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\SettingsPage;
use WP_UnitTestCase;

/**
 * The Domain mappings screen ships its own stylesheet.
 *
 * The screen's markup carries `pd-` classes for the copy blocks, the target
 * combobox and the workflow steps. Without a stylesheet those classes style
 * nothing, so the page renders as unstyled admin defaults — which is what
 * acceptance testing found. These prove the stylesheet is loaded on that screen
 * and nowhere else, that it is versioned so a release busts the cache, and that
 * the file it points at is real.
 */
final class AdminAssetsTest extends WP_UnitTestCase {

	private const HANDLE = 'post-domain-admin';

	private const HOOK = 'settings_page_post-domain';

	private const RELATIVE_PATH = 'assets/admin.css';

	public function set_up(): void {
		parent::set_up();

		$this->forget_style();
	}

	public function tear_down(): void {
		$this->forget_style();

		parent::tear_down();
	}

	/** The style registry is a global; a leftover registration would prove nothing. */
	private function forget_style(): void {
		wp_dequeue_style( self::HANDLE );
		wp_deregister_style( self::HANDLE );
	}

	private function plugin_dir(): string {
		return dirname( __DIR__, 3 );
	}

	private function css_path(): string {
		return $this->plugin_dir() . '/' . self::RELATIVE_PATH;
	}

	public function test_the_stylesheet_is_enqueued_on_the_domain_mappings_screen(): void {
		SettingsPage::enqueue( self::HOOK );

		$this->assertTrue(
			wp_style_is( self::HANDLE, 'enqueued' ),
			'The Domain mappings screen must enqueue the plugin stylesheet.'
		);
	}

	/**
	 * @dataProvider other_admin_hooks
	 */
	public function test_the_stylesheet_is_not_enqueued_on_other_admin_screens( string $hook ): void {
		SettingsPage::enqueue( $hook );

		$this->assertFalse(
			wp_style_is( self::HANDLE, 'enqueued' ),
			sprintf( 'The plugin stylesheet must not load on %s.', $hook )
		);
	}

	/** @return array<string, array{string}> */
	public static function other_admin_hooks(): array {
		return array(
			'the dashboard'         => array( 'index.php' ),
			'the plugins list'      => array( 'plugins.php' ),
			'general settings'      => array( 'options-general.php' ),
			'the post editor'       => array( 'post.php' ),
			'another settings page' => array( 'settings_page_some-other-plugin' ),
		);
	}

	public function test_the_stylesheet_carries_the_plugin_version(): void {
		SettingsPage::enqueue( self::HOOK );

		$registered = wp_styles()->registered[ self::HANDLE ] ?? null;

		$this->assertNotNull( $registered, 'The stylesheet must be registered.' );

		$header = get_file_data(
			$this->plugin_dir() . '/post-domain.php',
			array( 'version' => 'Version' )
		);

		$this->assertSame(
			$header['version'],
			$registered->ver,
			'A release must bust the stylesheet cache, so it is versioned with the plugin.'
		);
		$this->assertNotSame( '', (string) $registered->ver, 'An empty version defeats cache busting.' );
	}

	public function test_the_registered_source_is_the_file_the_screen_expects(): void {
		SettingsPage::enqueue( self::HOOK );

		$registered = wp_styles()->registered[ self::HANDLE ] ?? null;

		$this->assertNotNull( $registered, 'The stylesheet must be registered.' );

		$this->assertSame(
			plugins_url( self::RELATIVE_PATH, $this->plugin_dir() . '/post-domain.php' ),
			$registered->src,
			'The stylesheet must be served from the plugin directory.'
		);
		$this->assertStringEndsWith( '/' . self::RELATIVE_PATH, (string) $registered->src );
	}

	public function test_the_stylesheet_file_exists_and_is_not_empty(): void {
		$path = $this->css_path();

		$this->assertFileExists( $path, 'assets/admin.css must ship with the plugin.' );
		$this->assertGreaterThan(
			0,
			(int) filesize( $path ),
			'An empty stylesheet would pass every other assertion and style nothing.'
		);
	}

	/**
	 * A parse check, not a linter: an unbalanced brace silently kills every rule
	 * after it, and a browser reports nothing when it happens.
	 */
	public function test_the_stylesheet_parses(): void {
		$css = (string) file_get_contents( $this->css_path() );

		// Comments may legitimately contain braces and stray quotes.
		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$this->assertSame(
			substr_count( $code, '{' ),
			substr_count( $code, '}' ),
			'Braces must balance, or every rule after the mismatch is dropped.'
		);
		$this->assertSame( 0, substr_count( $code, '"' ) % 2, 'Double quotes must be paired.' );
		$this->assertSame(
			substr_count( $code, '(' ),
			substr_count( $code, ')' ),
			'Parentheses must balance.'
		);

		// Every at-rule used here must be one that opens a block and is known.
		preg_match_all( '/@([a-zA-Z-]+)/', $code, $matches );

		foreach ( array_unique( $matches[1] ) as $at_rule ) {
			$this->assertContains(
				$at_rule,
				array( 'media', 'supports' ),
				sprintf( '@%s is not an at-rule this stylesheet should be using.', $at_rule )
			);
		}

		// No @import, no external URL, no web font: the file must stand alone.
		$this->assertStringNotContainsString( '@import', $code );
		$this->assertStringNotContainsString( 'http://', $code );
		$this->assertStringNotContainsString( 'https://', $code );

		// !important is a last resort, and nothing here needs one.
		$this->assertStringNotContainsString( '!important', $code );
	}

	/**
	 * Scope is the whole safety argument: this file loads inside wp-admin, where
	 * a loose selector reaches every other plugin's markup on the screen.
	 */
	public function test_every_selector_is_scoped_to_the_plugin(): void {
		$css = (string) file_get_contents( $this->css_path() );

		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		// The selector list is everything before a `{` that opens a declaration
		// block; at-rule preludes are skipped, their inner rules are not.
		preg_match_all( '/(^|[};])\s*([^{}@;]+)\{/m', $code, $matches );

		foreach ( $matches[2] as $selector_list ) {
			foreach ( explode( ',', $selector_list ) as $selector ) {
				$selector = trim( $selector );

				if ( '' === $selector ) {
					continue;
				}

				$this->assertMatchesRegularExpression(
					'/(^|[\s>+~])\.(pd-|settings_page_post-domain)/',
					' ' . $selector,
					sprintf( 'Selector "%s" is not scoped to the plugin.', $selector )
				);
			}
		}
	}
}
