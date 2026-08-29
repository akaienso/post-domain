<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class QueryScope {

	/**
	 * @param int[]|null           $post__in
	 * @param int[]|null           $post_parent__in
	 * @param array<string, mixed> $query_args
	 */
	public function __construct(
		public readonly bool $is_bounded,
		public readonly ?array $post__in,
		public readonly ?array $post_parent__in,
		public readonly array $query_args
	) {}

	public static function unbounded(): self {
		return new self( false, null, null, array( 'ignore_sticky_posts' => true ) );
	}

	/** @param int[] $ids */
	public static function of_ids( array $ids ): self {
		if ( array() === $ids ) {
			// An empty inclusion array is silently ignored by WP_Query.
			return self::unbounded();
		}

		return new self( true, array_values( $ids ), null, array( 'ignore_sticky_posts' => true ) );
	}
}
