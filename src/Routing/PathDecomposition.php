<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class PathDecomposition {

	public function __construct(
		public readonly string $base,
		public readonly Representation $rep,
		public readonly ?string $feed_type,
		public readonly ?int $paged,
		public readonly ?int $comment_page,
		public readonly string $raw_query
	) {}
}
