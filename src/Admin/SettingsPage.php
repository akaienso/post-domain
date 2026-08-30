<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

final class SettingsPage {

	public const SLUG = 'post-domain';

	public static function register(): void {
		// The POST handler runs on admin_init, before any output: Post/Redirect/Get
		// needs to send a Location header, which a page callback is far too late for.
		add_action( 'admin_init', array( Actions::class, 'on_admin_init' ) );

		add_action(
			'admin_menu',
			static function (): void {
				add_options_page(
					__( 'Domain mappings', 'post-domain' ),
					__( 'Domain mappings', 'post-domain' ),
					Actions::capability(),
					self::SLUG,
					array( self::class, 'render' )
				);
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
