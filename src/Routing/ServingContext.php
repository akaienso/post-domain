<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\Mapping;

final class ServingContext {

	/**
	 * @param string[] $subtree_post_types
	 * @param string[] $post_statuses
	 * @param string[] $preserved_query_vars
	 */
	public function __construct(
		public readonly Mapping $mapping,
		public readonly string $requested_host,
		public readonly string $canonical_host,
		public readonly bool $is_active,
		public readonly int $effective_post_id,
		public readonly array $subtree_post_types,
		public readonly array $post_statuses,
		public readonly int $max_depth,
		public readonly array $preserved_query_vars,
		public readonly ?object $resolution = null,
		public readonly Representation $representation = Representation::HTML
	) {}

	public function with_resolution( object $resolution, Representation $representation ): self {
		return new self(
			$this->mapping,
			$this->requested_host,
			$this->canonical_host,
			$this->is_active,
			$this->effective_post_id,
			$this->subtree_post_types,
			$this->post_statuses,
			$this->max_depth,
			$this->preserved_query_vars,
			$resolution,
			$representation
		);
	}
}
