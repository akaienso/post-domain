<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

use PostDomain\Routing\ServingContext;

final class UrlPolicy {

	public const PROTECTED_PATHS = array(
		'/wp-admin/',
		'/wp-login.php',
		'/wp-signup.php',
		'/wp-activate.php',
		'/xmlrpc.php',
		'/wp-cron.php',
	);

	public const EXEMPT_PATHS = array( '/wp-admin/admin-ajax.php' );

	private const INFRASTRUCTURE_PREFIXES = array( '/wp-content/', '/wp-includes/' );

	public function __construct( private readonly string $primary_origin ) {}

	public function is_rebasable_path( string $path, ServingContext $context ): bool {
		$derived = $this->derive_rebasable( $path );
		$result  = (bool) apply_filters( 'pd_is_rebasable_path', $derived, $path, $context->mapping );

		// Protected paths are forced not rebasable after the filter.
		return $this->is_protected( $path ) ? false : $result;
	}

	public function rebase( string $url, ServingContext $context, UrlKind $kind ): string {
		/** @var string|null $supplied */
		$supplied = apply_filters( 'pd_rebase_url', null, $url, $context, $kind );

		if ( is_string( $supplied ) ) {
			return $this->validated( $supplied, $context ) ?? $url;
		}

		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['host'] ) ) {
			return $url;
		}

		if ( (string) wp_parse_url( $this->primary_origin, PHP_URL_HOST ) !== $parts['host'] ) {
			return $url;
		}

		$path = $parts['path'] ?? '/';

		if ( ! $this->is_rebasable_path( $path, $context ) ) {
			return $url;
		}

		$target = $this->link_host( $context, $kind );
		$suffix = $path;

		if ( isset( $parts['query'] ) ) {
			$suffix .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$suffix .= '#' . $parts['fragment'];
		}

		return ( $parts['scheme'] ?? 'https' ) . '://' . $target . $suffix;
	}

	private function link_host( ServingContext $context, UrlKind $kind ): string {
		$default = $kind->prefers_canonical_host() ? $context->canonical_host : $context->requested_host;
		$host    = (string) apply_filters( 'pd_link_host', $default, $kind, $context );

		return HostValue::validated( $host, $context ) ?? $default;
	}

	private function validated( string $url, ServingContext $context ): ?string {
		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return null;
		}

		$permitted = array( $context->requested_host, $context->canonical_host );

		return in_array( $parts['host'], $permitted, true ) ? $url : null;
	}

	private function is_protected( string $path ): bool {
		foreach ( self::EXEMPT_PATHS as $exempt ) {
			if ( $exempt === $path ) {
				return false;
			}
		}

		foreach ( self::PROTECTED_PATHS as $protected ) {
			if ( str_starts_with( $path, $protected ) || rtrim( $protected, '/' ) === $path ) {
				return true;
			}
		}

		return str_starts_with( $path, '/' . rest_get_url_prefix() . '/post-domain/v1' );
	}

	private function derive_rebasable( string $path ): bool {
		foreach ( self::INFRASTRUCTURE_PREFIXES as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}

		if ( str_starts_with( $path, '/' . rest_get_url_prefix() . '/' ) ) {
			return true;
		}

		return ! $this->is_protected( $path );
	}
}
