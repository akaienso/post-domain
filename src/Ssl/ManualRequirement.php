<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ManualRequirement {

	/** @param string[] $contacts */
	public function __construct(
		public readonly string $purpose,
		public readonly string $id,
		public readonly string $label,
		public readonly string $instruction,
		public readonly array $contacts,
		public readonly string $source
	) {}
}
