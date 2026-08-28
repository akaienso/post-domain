<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\SslState;

final class SslStatus {

	/** @param array<string, mixed>|null $provider_state */
	public function __construct(
		public readonly SslState $state,
		public readonly ?string $ref = null,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?string $confirmed_method = null,
		public readonly bool $transient = false,
		public readonly ?array $provider_state = null
	) {}
}
