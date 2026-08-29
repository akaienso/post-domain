<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class MutationRefusal {

	public function __construct(
		public readonly string $precondition,
		public readonly bool $transient,
		public readonly ?string $detail = null
	) {}
}
