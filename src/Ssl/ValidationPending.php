<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPending {

	public function __construct(
		public readonly string $purpose,
		public readonly string $reason,
		public readonly ?int $retry_after = null
	) {}
}
