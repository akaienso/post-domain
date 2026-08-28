<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

/**
 * Scope is optimization only. This is the guarantee.
 */
final class MembershipFilter {

	public function __construct( private readonly RoutingContract $routing ) {}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public function keep_members( array $posts, ServingContext $context ): array {
		return array_values(
			array_filter(
				$posts,
				fn( \WP_Post $post ): bool => $this->routing->belongs_to_mapping( $context, $post )
			)
		);
	}
}
