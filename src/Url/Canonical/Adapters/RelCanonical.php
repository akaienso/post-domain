<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\Canonical\CanonicalPolicy;

final class RelCanonical {

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 10, 2 );
	}

	/** @param \WP_Post|null $post */
	public function filter_canonical( ?string $url, $post ): ?string {
		global $wp_query;

		$serving = $this->context->serving();

		if ( null === $serving || ! $wp_query instanceof \WP_Query ) {
			return $url;
		}

		unset( $post );

		return CanonicalPolicy::for_request( $this->context->host(), $serving, $wp_query )?->url ?? $url;
	}
}
