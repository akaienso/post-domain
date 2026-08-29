<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class Resolution {

	public function __construct(
		public readonly int $post_id,
		public readonly string $post_type,
		public readonly int $depth,
		public readonly string $canonical_path
	) {}
}
