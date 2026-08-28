<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Answers one question: may this plugin run here?
 *
 * @package PostDomain
 */
final class Environment {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.4';

	/**
	 * @return string|null A human-readable reason, or null when the plugin may run.
	 */
	public static function blocker( string $php_version, string $wp_version, bool $is_multisite ): ?string {
		if ( $is_multisite ) {
			return 'post-domain does not support multisite networks.';
		}

		if ( version_compare( $php_version, self::MIN_PHP, '<' ) ) {
			return sprintf(
				'post-domain requires PHP %s or later; this site runs %s.',
				self::MIN_PHP,
				$php_version
			);
		}

		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			return sprintf(
				'post-domain requires WordPress %s or later; this site runs %s.',
				self::MIN_WP,
				$wp_version
			);
		}

		return null;
	}
}
