<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class RemovalResult {

	public function __construct(
		public readonly RemovalOutcome $outcome,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?int $retry_after = null
	) {}
}
