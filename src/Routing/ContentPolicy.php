<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\AliasResolver;

/**
 * Phase C, frozen at init : 99 — the earliest point at which any of these answers
 * can be validated, because post types and statuses must already be registered.
 */
final class ContentPolicy {

	public static function freeze( ?ServingEligibility $eligibility, AliasResolver $aliases ): ?ServingContext {
		if ( null === $eligibility ) {
			return null;
		}

		$mapping   = $eligibility->mapping;
		$canonical = $aliases->canonical_for( $mapping );

		if ( null === $canonical || null === $canonical->post_id ) {
			return null;
		}

		$default_type = get_post_type( $canonical->post_id );

		if ( false === $default_type ) {
			return null;
		}

		/** @var string[] $types */
		$types = (array) apply_filters( 'pd_subtree_post_types', array( $default_type ), $mapping );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );

		if ( array() === $types ) {
			return null;
		}

		/** @var string[] $statuses */
		$statuses = (array) apply_filters( 'pd_post_statuses', array( 'publish' ), $mapping );
		$statuses = array_values(
			array_filter( $statuses, static fn( $s ): bool => is_string( $s ) && null !== get_post_status_object( $s ) )
		);

		if ( array() === $statuses ) {
			return null;
		}

		$target = (int) apply_filters( 'pd_target_post_for_host', (int) $canonical->post_id, $mapping );
		$post   = get_post( $target );

		if ( null === $post
			|| ! in_array( $post->post_type, $types, true )
			|| ! in_array( $post->post_status, $statuses, true ) ) {
			return null;
		}

		$depth = (int) apply_filters( 'pd_max_subtree_depth', 10, $mapping );
		$depth = max( 1, min( 25, $depth ) );

		return new ServingContext(
			$mapping,
			$eligibility->requested_host,
			$eligibility->canonical_host,
			$eligibility->is_active,
			$target,
			$types,
			$statuses,
			$depth,
			QueryVarPolicy::preserved( $mapping )
		);
	}
}
