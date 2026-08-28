<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ProviderMarker {

	/** @param array<string, mixed> $raw */
	public function __construct(
		public readonly ?string $installation_id,
		public readonly ?int $mapping_id,
		public readonly array $raw
	) {}

	public function names( string $installation_id, int $mapping_id ): bool {
		return $this->installation_id === $installation_id && $this->mapping_id === $mapping_id;
	}
}
