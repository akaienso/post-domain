<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

final class SettingsPage {

	public const SLUG = 'post-domain';

	public static function register(): void {
		add_action(
			'admin_menu',
			static function (): void {
				$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', 'admin' );

				add_options_page(
					__( 'Domain mappings', 'post-domain' ),
					__( 'Domain mappings', 'post-domain' ),
					'' === $capability ? 'manage_options' : $capability,
					self::SLUG,
					array( self::class, 'render' )
				);
			}
		);

		EnvironmentNotice::register();
	}

	/**
	 * The selection is a closed list drawn from the registry, so an operator
	 * cannot name a driver that does not exist and then wonder why nothing
	 * provisions.
	 */
	public static function render_driver_selection(): string {
		$selected = \PostDomain\Ssl\DriverFactory::selected_driver_id();
		$html     = '<h2>' . esc_html__( 'Certificate provider', 'post-domain' ) . '</h2>';
		$html    .= '<select name="pd_ssl_driver">';

		foreach ( \PostDomain\Ssl\DriverFactory::registry()->ids() as $id ) {
			$label = \PostDomain\Ssl\DriverFactory::NULL_DRIVER === $id
				? __( 'None — certificates are managed outside this plugin', 'post-domain' )
				: $id;

			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( $selected, $id, false ),
				esc_html( $label )
			);
		}

		$html .= '</select>';

		if ( ! in_array( $selected, \PostDomain\Ssl\DriverFactory::registry()->ids(), true ) ) {
			// Named plainly rather than silently corrected: the stored value is
			// still what the site asked for, and the operator should see that.
			$html .= '<p class="notice notice-error">' . sprintf(
				/* translators: %s: the configured driver identifier. */
				esc_html__( 'The configured provider "%s" is not registered. Certificates will not be requested until this is resolved.', 'post-domain' ),
				esc_html( $selected )
			) . '</p>';
		}

		return $html;
	}

	public static function save_driver_selection(): void {
		if ( ! isset( $_POST['pd_ssl_driver'] ) || ! check_admin_referer( 'pd_settings' ) ) {
			return;
		}

		$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', 'admin' );

		if ( ! current_user_can( '' === $capability ? 'manage_options' : $capability ) ) {
			return;
		}

		$requested = sanitize_text_field( wp_unslash( (string) $_POST['pd_ssl_driver'] ) );

		if ( ! in_array( $requested, \PostDomain\Ssl\DriverFactory::registry()->ids(), true ) ) {
			return;
		}

		$settings = get_option( 'pd_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['ssl_driver'] = $requested;

		update_option( 'pd_settings', $settings, false );

		// The registry is memoized per request; the next one must see this.
		\PostDomain\Ssl\DriverFactory::reset();
	}

	public static function render(): void {
		self::save_driver_selection();

		echo '<div class="wrap"><h1>' . esc_html__( 'Domain mappings', 'post-domain' ) . '</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'pd_settings' );
		echo wp_kses_post( self::render_driver_selection() );
		submit_button( __( 'Save', 'post-domain' ) );
		echo '</form>';
		echo '<table class="widefat"><thead><tr>';

		foreach (
			array(
				__( 'Domain', 'post-domain' ),
				__( 'Target', 'post-domain' ),
				__( 'Verification', 'post-domain' ),
				__( 'Activation', 'post-domain' ),
				__( 'Certificate', 'post-domain' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( MappingListTable::rows() as $row ) {
			printf(
				'<tr><td><strong>%s</strong><br><code>%s</code></td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['host_display'] ),
				esc_html( (string) $row['host'] ),
				(int) $row['target'],
				esc_html( (string) $row['verification'] ),
				esc_html( (string) $row['activation'] ),
				esc_html( (string) $row['ssl'] )
			);
		}

		echo '</tbody></table></div>';
	}
}
