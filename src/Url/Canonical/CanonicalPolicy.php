<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical;

use PostDomain\Routing\HostContext;
use PostDomain\Routing\ServingContext;
use PostDomain\Url\AbsoluteUrl;

/**
 * Pure. Computed per request, never cached: the policy has no persistence layer
 * by construction, so there is nowhere for a stale answer to live.
 */
final class CanonicalPolicy {

	public static function for_request(
		?HostContext $host,
		?ServingContext $serving,
		\WP_Query $query
	): ?CanonicalUrl {
		if ( null === $serving ) {
			return null;
		}

		$post_id = (int) $query->get( 'page_id' );

		if ( 0 === $post_id ) {
			$post_id = (int) $query->get( 'p' );
		}

		$computed = 0 === $post_id
			? 'https://' . $serving->canonical_host . '/'
			: (string) get_permalink( $post_id );

		$default = new CanonicalUrl( $computed );

		/** @var CanonicalUrl|null $supplied */
		$supplied = apply_filters( 'pd_canonical_url', $default, $host, $serving, $query );

		if ( ! $supplied instanceof CanonicalUrl ) {
			return $default;
		}

		$permitted = array(
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			$serving->requested_host,
			$serving->canonical_host,
		);

		$validated = AbsoluteUrl::validated( $supplied->url, $permitted, (bool) ( $host?->is_https ?? true ) );

		return null === $validated ? $default : new CanonicalUrl( $validated );
	}
}
