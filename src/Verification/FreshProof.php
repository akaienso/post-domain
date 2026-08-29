<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\Mapping;

/**
 * A live proof with no stored state. Cached verification is not sufficient
 * authorization for a provider mutation, and a rotated challenge must invalidate
 * any authority a copy of the database might otherwise claim.
 */
final class FreshProof {

	public function __construct( private readonly DnsResolver $resolver ) {}

	public function prove( Mapping $mapping ): DnsOutcome {
		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return DnsOutcome::TRANSIENT;
		}

		return $this->resolver->txt( $name, Challenge::expected_value( $mapping->challenge ) )->outcome;
	}
}
