<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Ssl\Environment;

final class EnvironmentNotice {

	public static function render(): string {
		$mismatch = get_option( 'pd_environment_mismatch', null );

		if ( ! is_array( $mismatch ) ) {
			return '';
		}

		return sprintf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
			esc_html__( 'post-domain: this site has moved or been copied.', 'post-domain' ),
			sprintf(
				/* translators: 1: stored host, 2: current host */
				esc_html__( 'It was installed on %1$s and is now running on %2$s. Every provider mutation is blocked until you choose.', 'post-domain' ),
				'<code>' . esc_html( (string) $mismatch['stored'] ) . '</code>',
				'<code>' . esc_html( (string) $mismatch['current'] ) . '</code>'
			),
			esc_html__( 'Restore or move: the same site at a new address — keep certificates and challenges. Clone: a copy — new identity, cleared certificate ownership, rotated challenges.', 'post-domain' )
		);
	}

	public static function register(): void {
		add_action(
			'admin_notices',
			static function (): void {
				echo wp_kses_post( self::render() );
			}
		);
	}

	public static function resolve( string $choice ): void {
		if ( 'clone' === $choice ) {
			Environment::resolve_as_clone();

			return;
		}

		Environment::resolve_as_restore();
	}
}
