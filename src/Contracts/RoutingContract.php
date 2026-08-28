<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Routing\Resolution;
use PostDomain\Routing\ServingContext;

interface RoutingContract {

	public function resolve_path( ServingContext $context, string $path ): ?Resolution;

	public function path_for_post( ServingContext $context, \WP_Post $post ): ?string;

	public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool;
}
