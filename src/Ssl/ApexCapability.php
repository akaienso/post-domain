<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ApexCapability {

	public const PROVENANCES = array( 'static_ip_prefix', 'byoip' );

	/** @param string[] $targets */
	public function __construct(
		public readonly ApexRouting $routing,
		public readonly string $reason,
		public readonly array $targets,
		public readonly ?string $target_provenance,
		public readonly bool $operator_attested
	) {}

	public static function unsupported( string $reason ): self {
		return new self( ApexRouting::UNSUPPORTED, $reason, array(), null, false );
	}

	/**
	 * A/AAAA records are emitted only for a fully attested Apex Proxying or BYOIP
	 * capability. Ordinary origin addresses are never valid apex proxy targets.
	 *
	 * @param mixed $candidate
	 */
	public static function validated( $candidate ): self {
		if ( ! $candidate instanceof self ) {
			return self::unsupported( 'filter did not return an ApexCapability' );
		}

		if ( ApexRouting::APEX_PROXY !== $candidate->routing ) {
			return $candidate;
		}

		if ( array() === $candidate->targets ) {
			return self::unsupported( 'apex proxying declared with no targets' );
		}

		foreach ( $candidate->targets as $target ) {
			if ( ! is_string( $target ) || false === filter_var( $target, FILTER_VALIDATE_IP ) ) {
				return self::unsupported( 'apex proxy target is not an IP address' );
			}
		}

		if ( ! in_array( $candidate->target_provenance, self::PROVENANCES, true ) ) {
			return self::unsupported( 'apex proxy targets need a declared Cloudflare provenance' );
		}

		if ( ! $candidate->operator_attested ) {
			return self::unsupported( 'apex proxying requires an explicit operator attestation' );
		}

		return $candidate;
	}
}
