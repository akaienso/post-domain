<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class HttpRequirementSet {

	public function __construct(
		public readonly string $purpose,
		public readonly string $id,
		public readonly string $label,
		public readonly string $url,
		public readonly string $body,
		public readonly string $source,
		public readonly bool $removable_once_active = false
	) {}
}
