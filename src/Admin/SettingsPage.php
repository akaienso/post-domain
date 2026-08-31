<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

final class SettingsPage {

	public const SLUG = 'post-domain';

	public static function register(): void {
		// The POST handler runs on admin_init, before any output: Post/Redirect/Get
		// needs to send a Location header, which a page callback is far too late for.
		add_action( 'admin_init', array( Actions::class, 'on_admin_init' ) );

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );

		add_action(
			'admin_menu',
			static function (): void {
				$hook = add_options_page(
					__( 'Domain mappings', 'post-domain' ),
					__( 'Domain mappings', 'post-domain' ),
					Actions::capability(),
					self::SLUG,
					array( self::class, 'render' )
				);

				// False for a user without the capability, in which case there is
				// no screen to hang help on.
				if ( is_string( $hook ) && '' !== $hook ) {
					add_action( 'load-' . $hook, array( Guide::class, 'register_help_tabs' ) );
				}
			}
		);

		EnvironmentNotice::register();
	}

	/**
	 * Kept as the compatibility name for the provider selector.
	 *
	 * It returns markup that is already escaped and must be echoed as-is. Passing
	 * it through `wp_kses_post()` is what shipped in v1.0.0: that allowlist is for
	 * post content, so it dropped every `<select>` and `<option>` and left the
	 * provider names running together as plain text.
	 */
	public static function render_driver_selection(): string {
		return Screen::driver_form();
	}

	/**
	 * The screen works without this; it enhances what is already there.
	 *
	 * Loaded only on this page, because nothing else uses it.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'post-domain-admin',
			plugins_url( 'assets/admin.css', dirname( __DIR__, 2 ) . '/post-domain.php' ),
			array(),
			self::asset_version()
		);

		wp_enqueue_script(
			'post-domain-admin',
			plugins_url( 'assets/admin.js', dirname( __DIR__, 2 ) . '/post-domain.php' ),
			array(),
			self::asset_version(),
			true
		);

		wp_localize_script(
			'post-domain-admin',
			'postDomainAdmin',
			array(
				'copied'            => __( 'Copied', 'post-domain' ),
				'copyManual'        => __( 'Selected — press Ctrl/Cmd+C to copy', 'post-domain' ),
				'searchPlaceholder' => __( 'Search your content', 'post-domain' ),
				'searching'         => __( 'Searching…', 'post-domain' ),
				'noResults'         => __( 'Nothing matched that search.', 'post-domain' ),
				'searchError'       => __( 'That search could not be completed.', 'post-domain' ),
				'tryAgainIn'        => __( 'Try again in', 'post-domain' ),
				'testing'           => __( 'Testing…', 'post-domain' ),
				'testFailed'        => __( 'That test could not be completed.', 'post-domain' ),
				'testUnreachable'   => __( 'The domain did not reach this WordPress site. Your hosting may not be routing it here yet.', 'post-domain' ),
			)
		);
	}

	/** The plugin version, so a released change busts the cache. */
	private static function asset_version(): string {
		$data = get_file_data( dirname( __DIR__, 2 ) . '/post-domain.php', array( 'version' => 'Version' ) );

		return is_string( $data['version'] ?? null ) && '' !== $data['version'] ? $data['version'] : '0';
	}

	public static function render(): void {
		if ( ! current_user_can( Actions::capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage domain mappings.', 'post-domain' ),
				'',
				array( 'response' => 403 )
			);
		}

		Screen::render();
	}
}
