<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

final class AbsoluteUrl {

	/**
	 * @param string[] $permitted_hosts
	 */
	public static function validated( string $url, array $permitted_hosts, bool $request_is_https ): ?string {
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return null;
		}

		/*
		 * parse_url() rather than wp_parse_url(): this validator is deliberately
		 * WordPress-free so it can be unit tested without loading core, and the
		 * control-character and scheme checks above already cover what
		 * wp_parse_url() normalizes.
		 */
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return null;
		}

		if ( $request_is_https && 'https' !== $parts['scheme'] ) {
			return null;
		}

		return in_array( $parts['host'], $permitted_hosts, true ) ? $url : null;
	}
}
