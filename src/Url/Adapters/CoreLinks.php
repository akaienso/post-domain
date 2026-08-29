<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Routing\RoundTripVerifier;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

/**
 * Registered unconditionally; every callback no-ops when serving() is null. That
 * is what lets pd_with_mapping() work in cron and CLI.
 */
final class CoreLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy,
		private readonly RoundTripVerifier $verifier,
		private readonly string $primary_origin
	) {}

	public function register(): void {
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'attachment_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10 );
		add_filter( 'rest_url', array( $this, 'filter_rest_url' ), 10 );
		add_filter( 'admin_url', array( $this, 'filter_admin_url' ), 10, 2 );
	}

	public function filter_home_url( string $url, string $path = '' ): string {
		unset( $path );

		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::HOME );
	}

	/** @param \WP_Post|int $post */
	public function filter_post_link( string $url, $post ): string {
		$serving = $this->context->serving();
		$post    = get_post( $post );

		if ( null === $serving || null === $post ) {
			return $url;
		}

		$path = $this->verifier->verified_path( $serving, $post );

		if ( null === $path ) {
			/*
			 * The primary permalink is correct on the wrong domain; that beats a
			 * wrong URL on the right one. The incoming URL was built from
			 * home_url(), which this adapter has already rebased, so it has to be
			 * put back on the primary origin here.
			 */
			return $this->on_primary_origin( $url, $serving );
		}

		$suffix = '' === $path ? '/' : '/' . $path . '/';

		return 'https://' . $serving->requested_host . user_trailingslashit( $suffix );
	}

	private function on_primary_origin( string $url, \PostDomain\Routing\ServingContext $serving ): string {
		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['host'] ) ) {
			return $url;
		}

		if ( ! in_array( $parts['host'], array( $serving->requested_host, $serving->canonical_host ), true ) ) {
			return $url;
		}

		$suffix = $parts['path'] ?? '/';

		if ( isset( $parts['query'] ) ) {
			$suffix .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$suffix .= '#' . $parts['fragment'];
		}

		return rtrim( $this->primary_origin, '/' ) . $suffix;
	}

	/** @param \WP_Post|int $post */
	public function filter_page_link( string $url, $post ): string {
		return $this->filter_post_link( $url, $post );
	}

	public function filter_term_link( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::TERM );
	}

	public function filter_rest_url( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::REST );
	}

	public function filter_admin_url( string $url, string $path = '' ): string {
		$serving = $this->context->serving();

		if ( null === $serving || 'admin-ajax.php' !== ltrim( $path, '/' ) ) {
			return $url;
		}

		return $this->policy->rebase( $url, $serving, UrlKind::AJAX );
	}
}
