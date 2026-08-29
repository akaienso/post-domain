<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\QueryScopeProvider;

/**
 * Capability-gated. Ships disabled: enabling it requires evidence recorded in
 * docs/cte-capability-evidence.md AND a positive probe. Returning an unbounded
 * scope is the only thing it does when either gate is closed — it never emits
 * SQL it is not sure the server understands.
 */
final class CteSubtreeAdapter implements QueryScopeProvider {

	private bool $capable;

	public function __construct( ?bool $capable = null ) {
		$this->capable = $capable ?? DatabaseCapability::supports_recursive_cte();
	}

	public static function is_enabled(): bool {
		return defined( 'PD_ENABLE_CTE_SUBTREE' ) && (bool) constant( 'PD_ENABLE_CTE_SUBTREE' );
	}

	public function scope( ServingContext $context ): QueryScope {
		if ( ! $this->capable ) {
			return QueryScope::unbounded();
		}

		global $wpdb;

		$types    = implode( ',', array_fill( 0, count( $context->subtree_post_types ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( $context->post_statuses ), '%s' ) );

		$sql = "WITH RECURSIVE pd_tree (id) AS (
					SELECT ID FROM {$wpdb->posts} WHERE ID = %d
					UNION ALL
					SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN pd_tree t ON p.post_parent = t.id
					WHERE p.post_type IN ({$types}) AND p.post_status IN ({$statuses})
				)
				SELECT id FROM pd_tree";

		$values = array_merge(
			array( $context->effective_post_id ),
			$context->subtree_post_types,
			$context->post_statuses
		);

		/** @var string[] $ids */
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB

		return QueryScope::of_ids( array_map( 'intval', $ids ) );
	}
}
