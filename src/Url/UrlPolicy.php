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

		/*
		 * A mapped host is always addressed over HTTPS. Rebasing must never
		 * produce a downgrade (spec §11.8), and the mapping only serves once it
		 * is verified and active.
		 */
		return 'https://' . $target . $suffix;
	}

	private function link_host( ServingContext $context, UrlKind $kind ): string {
		$default = $kind->prefers_canonical_host() ? $context->canonical_host : $context->requested_host;
		$host    = (string) apply_filters( 'pd_link_host', $default, $kind, $context );

		return HostValue::validated( $host, $context ) ?? $default;
	}

	/**
	 * `pd_rebase_url` hands a filter complete control of a link's absolute form,
	 * so what comes back is untrusted input to the mapped-host contract rather
	 * than a result. It goes through the same strict validator the canonical
	 * filter uses — control characters, userinfo, and a scheme downgrade are all
	 * refused there — with HTTPS required unconditionally, because a mapped host
	 * is only ever addressed over HTTPS (spec §11.8).
	 */
	private function validated( string $url, ServingContext $context ): ?string {
		$validated = AbsoluteUrl::validated(
			$url,
			array( $context->requested_host, $context->canonical_host ),
			true
		);

		if ( null === $validated ) {
			return null;
		}

		// The mapped-host contract has no port to offer: the plugin never emits
		// one, and an authority the contract would not itself produce is not one
		// it will hand to a browser. An explicit :443 says nothing the scheme has
		// not already said, so it is the one port that survives.
		$port = wp_parse_url( $validated, PHP_URL_PORT );

		return null === $port || 443 === $port ? $validated : null;
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
