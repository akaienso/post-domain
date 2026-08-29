<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

/**
 * Both directions live here against one filter set, so a URL the plugin emits
 * and a URL it resolves cannot disagree.
 */
final class Subtree implements RoutingContract {

	public function __construct( private readonly PathNormalizer $normalizer ) {}

	public function resolve_path( ServingContext $context, string $path ): ?Resolution {
		/** @var Resolution|null $short_circuit */
		$short_circuit = apply_filters( 'pd_resolve_path', null, $path, $context->mapping );

		if ( $short_circuit instanceof Resolution ) {
			return $short_circuit;
		}

		$segments = $this->normalizer->segments( $path );

		if ( null === $segments ) {
			return null;
		}

		if ( count( $segments ) > $context->max_depth ) {
			return null;
		}

		$current = get_post( $context->effective_post_id );

		if ( null === $current ) {
			return null;
		}

		$depth = 0;

		foreach ( $segments as $segment ) {
			$candidates = array();

			foreach ( $this->children_of( $context, $current ) as $child ) {
				if ( $this->segment_for( $context, $child ) === $segment ) {
					$candidates[] = $child;
				}
			}

			if ( count( $candidates ) > 1 ) {
				AmbiguousPath::record(
					$context->mapping->id,
					$segment,
					array_map( static fn( \WP_Post $p ): int => $p->ID, $candidates )
				);

				/** @var \WP_Post|null $winner */
				$winner = apply_filters( 'pd_resolve_ambiguity', null, $context->mapping, $candidates, $segment );

				if ( ! $winner instanceof \WP_Post ) {
					return null;
				}

				$candidates = array( $winner );
			}

			if ( array() === $candidates ) {
				return null;
			}

			$current = $candidates[0];
			++$depth;
		}

		return new Resolution(
			$current->ID,
			$current->post_type,
			$depth,
			implode( '/', $segments )
		);
	}

	public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
		/** @var string|null $short_circuit */
		$short_circuit = apply_filters( 'pd_path_for_post', null, $post, $context->mapping );

		if ( is_string( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( $post->ID === $context->effective_post_id ) {
			return '';
		}

		$segments = array();
		$current  = $post;

		for ( $i = 0; $i < $context->max_depth; $i++ ) {
			if ( ! in_array( $current->post_type, $context->subtree_post_types, true )
				|| ! in_array( $current->post_status, $context->post_statuses, true ) ) {
				return null;
			}

			if ( $this->has_colliding_sibling( $context, $current ) ) {
				return null;
			}

			array_unshift( $segments, $this->segment_for( $context, $current ) );

			$parent_id = (int) $current->post_parent;

			if ( $parent_id === $context->effective_post_id ) {
				return implode( '/', $segments );
			}

			if ( 0 === $parent_id ) {
				return null;
			}

			$parent = get_post( $parent_id );

			if ( null === $parent ) {
				return null;
			}

			$current = $parent;
		}

		return null;
	}

	public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
		/** @var bool|null $short_circuit */
		$short_circuit = apply_filters( 'pd_belongs_to_mapping', null, $post, $context->mapping );

		if ( is_bool( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( $post->ID === $context->effective_post_id ) {
			return true;
		}

		return null !== $this->path_for_post( $context, $post );
	}

	/** @return \WP_Post[] */
	private function children_of( ServingContext $context, \WP_Post $parent_post ): array {
		/** @var \WP_Post[]|null $supplied */
		$supplied = apply_filters( 'pd_subtree_children', null, $parent_post, $context->mapping );

		if ( is_array( $supplied ) ) {
			return $supplied;
		}

		return get_posts(
			array(
				'post_parent'      => $parent_post->ID,
				'post_type'        => $context->subtree_post_types,
				'post_status'      => $context->post_statuses,
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	private function has_colliding_sibling( ServingContext $context, \WP_Post $post ): bool {
		$parent = 0 === (int) $post->post_parent ? null : get_post( (int) $post->post_parent );

		if ( null === $parent ) {
			return false;
		}

		$segment = $this->segment_for( $context, $post );
		$matches = 0;

		foreach ( $this->children_of( $context, $parent ) as $sibling ) {
			if ( $this->segment_for( $context, $sibling ) === $segment ) {
				++$matches;
			}
		}

		return $matches > 1;
	}

	private function segment_for( ServingContext $context, \WP_Post $post ): string {
		return (string) apply_filters(
			'pd_path_segment_for_post',
			(string) $post->post_name,
			$post,
			$context->mapping
		);
	}
}
