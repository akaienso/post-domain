<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\OwnershipOrigin;

/** Every value the consumption CAS re-checks. */
final class LeaseBinding {

	public function __construct(
		public readonly int $mapping_id,
		public readonly int $revision,
		public readonly string $token,
		public readonly MutationKind $kind,
		public readonly string $host,
		public readonly ?string $provider_id,
		public readonly ?string $provider_ref,
		public readonly string $challenge,
		public readonly ?string $requested_method,
		public readonly ?OwnershipOrigin $ownership_origin,
		public readonly ?string $owner_installation_id,
		public readonly string $mutation_driver,
		public readonly string $mutation_environment
	) {}

	/** The RESERVED owner this binding was granted against. */
	public function owner(): LeaseOwner {
		return new LeaseOwner(
			$this->mapping_id,
			$this->revision,
			$this->token,
			$this->kind,
			MutationPhase::RESERVED,
			$this->mutation_driver,
			$this->mutation_environment
		);
	}
}
