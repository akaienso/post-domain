<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\QueryScopeProvider;
use PostDomain\Contracts\RoutingContract;

final class EnumerationScopeProvider implements QueryScopeProvider {

	/**
	 * Part of the signature every scope provider shares. The enumeration walk
	 * asks the database directly, so it never consults the contract.
	 *
	 * @phpstan-ignore-next-line
	 */
	private readonly RoutingContract $routing;

	private readonly int $limit;

	public function __construct( RoutingContract $routing, int $limit ) {
		$this->routing = $routing;
		$this->limit   = $limit;
	}

	public function scope( ServingContext $context ): QueryScope {
		/** @var mixed $supplied */
		$supplied = apply_filters( 'pd_query_scope', null, $context->mapping, $context );

		if ( $supplied instanceof QueryScope ) {
			return $supplied;
		}

		if ( null !== $supplied ) {
			// A non-QueryScope return is rejected; unbounded is never reachable by mistake.
			return QueryScope::unbounded();
		}

		$ids   = array( $context->effective_post_id );
		$queue = array( $context->effective_post_id );
		$limit = max( 0, $this->limit );

		while ( array() !== $queue ) {
			$parent   = (int) array_shift( $queue );
			$children = get_posts(
				array(
					'post_parent'      => $parent,
					'post_type'        => $context->subtree_post_types,
					'post_status'      => $context->post_statuses,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);

			foreach ( $children as $child ) {
				$ids[]   = (int) $child;
				$queue[] = (int) $child;

				if ( count( $ids ) > $limit ) {
					return QueryScope::unbounded();
				}
			}
		}

		return QueryScope::of_ids( $ids );
	}
}
