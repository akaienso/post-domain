<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * The activation-time refusal, kept separate from the runtime gate so it can be
 * registered before the multisite early return.
 *
 * @package PostDomain
 */
final class Activation {

	/**
	 * @return string|null The message to die with, or null to allow activation.
	 */
	public static function refusal( string $php_version, string $wp_version, bool $is_multisite ): ?string {
		if ( $is_multisite ) {
			return 'post-domain cannot be activated on a multisite network. '
				. 'Domain mapping in a network is a different problem with a different '
				. 'solution, and supporting both makes both worse.';
		}

		return Environment::blocker( $php_version, $wp_version, false );
	}

	public static function guard(): void {
		$refusal = self::refusal( PHP_VERSION, get_bloginfo( 'version' ), is_multisite() );

		if ( null !== $refusal ) {
			wp_die( esc_html( $refusal ) );
		}
	}
}
