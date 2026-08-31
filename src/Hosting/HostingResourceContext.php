<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Mapping\Mapping;

/** Everything a hosting provider needs about one mapping, and nothing more. */
final class HostingResourceContext {

	public function __construct(
		public readonly int $mapping_id,
		public readonly string $host,
		public readonly string $installation_id,
		public readonly ?string $provider_ref,
		public readonly ?string $environment_id
	) {}

	public static function from_mapping( Mapping $mapping, string $installation_id ): self {
		return new self(
			$mapping->id,
			$mapping->host,
			$installation_id,
			$mapping->hosting_ref,
			$mapping->hosting_environment
		);
	}
}
