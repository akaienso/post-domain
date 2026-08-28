<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

final class Resolver {

	public function __construct(
		private readonly RoutingContract $routing,
		private readonly PathDecomposer $decomposer
	) {}

	public function resolve( ServingContext $context, \WP $wp ): ?Resolution {
		$decomposition = $this->decomposer->decompose( (string) $wp->request );
		$resolution    = $this->routing->resolve_path( $context, $decomposition->base );

		if ( null === $resolution ) {
			return null;
		}

		$preserved = array();

		foreach ( $context->preserved_query_vars as $var ) {
			if ( isset( $wp->query_vars[ $var ] ) ) {
				$preserved[ $var ] = $wp->query_vars[ $var ];
			}
		}

		$vars = $preserved;

		if ( 'page' === $resolution->post_type ) {
			$vars['page_id'] = $resolution->post_id;
		} else {
			$vars['p']         = $resolution->post_id;
			$vars['post_type'] = $resolution->post_type;
		}

		if ( Representation::FEED === $decomposition->rep ) {
			$vars['feed'] = $decomposition->feed_type ?? 'feed';
		}

		if ( Representation::EMBED === $decomposition->rep ) {
			$vars['embed'] = true;
		}

		if ( null !== $decomposition->paged ) {
			$vars['paged'] = $decomposition->paged;
		}

		if ( null !== $decomposition->comment_page ) {
			$vars['cpage'] = $decomposition->comment_page;
		}

		$wp->query_vars = $vars;

		return $resolution;
	}
}
