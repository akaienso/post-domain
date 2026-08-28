<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class IdentityResult {

	public function __construct(
		public readonly IdentityVerdict $verdict,
		public readonly ?string $expected_ref,
		public readonly ?string $observed_ref,
		public readonly ?string $observed_hostname,
		public readonly ?ProviderMarker $marker,
		public readonly MarkerSupport $marker_support,
		public readonly bool $read_complete,
		public readonly bool $transient,
		public readonly ?string $code = null,
		public readonly ?string $message = null
	) {}

	/** The strict rule for an already-bound resource. Never relaxed. */
	public function is_usable_for_mutation( string $expected_host ): bool {
		return IdentityVerdict::MATCH === $this->verdict
			&& $this->read_complete
			&& ! $this->transient
			&& null !== $this->expected_ref
			&& $this->observed_ref === $this->expected_ref
			&& $this->observed_hostname === $expected_host;
	}

	/** Reachable only while the reference is unbound. */
	public function is_recoverable_create( string $installation_id, int $mapping_id, string $expected_host ): bool {
		return IdentityVerdict::RECOVERABLE_CREATE === $this->verdict
			&& $this->read_complete
			&& ! $this->transient
			&& null === $this->expected_ref
			&& $this->observed_hostname === $expected_host
			&& null !== $this->marker
			&& $this->marker->names( $installation_id, $mapping_id );
	}

	/** An absent marker establishes nothing either way, so it never conflicts. */
	public function has_conflicting_marker( string $installation_id, int $mapping_id ): bool {
		if ( null === $this->marker ) {
			return false;
		}

		if ( null === $this->marker->installation_id && null === $this->marker->mapping_id ) {
			return false;
		}

		return ! $this->marker->names( $installation_id, $mapping_id );
	}
}
