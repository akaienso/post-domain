<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Routing\QueryScope;
use PostDomain\Routing\ServingContext;

interface QueryScopeProvider {

	public function scope( ServingContext $context ): QueryScope;
}
