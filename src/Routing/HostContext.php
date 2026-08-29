<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Authority;

final class HostContext {

	public function __construct(
		public readonly string $raw_authority,
		public readonly ?Authority $authority,
		public readonly ?string $ascii_host,
		public readonly HostKind $kind,
		public readonly ?Mapping $mapping,
		public readonly EndpointClass $endpoint,
		public readonly bool $is_https,
		public readonly string $method
	) {}

	public function has_row(): bool {
		return null !== $this->mapping;
	}

	/** Stored eligibility only; the filter veto is applied in phase B. */
	public function may_serve(): bool {
		if ( null === $this->mapping ) {
			return false;
		}

		return VerificationState::VERIFIED === $this->mapping->verification_state
			&& ActivationState::ACTIVE === $this->mapping->activation_state
			&& null === $this->mapping->integrity_error;
	}
}
