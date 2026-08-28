<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum VerificationState: string {
	case UNVERIFIED = 'unverified';
	case PENDING    = 'pending';
	case VERIFIED   = 'verified';
	case FAILED     = 'failed';

	public function can_transition_to( self $to ): bool {
		// Rotating the challenge resets any state to unverified.
		if ( self::UNVERIFIED === $to ) {
			return true;
		}

		return match ( $this ) {
			self::UNVERIFIED => self::PENDING === $to,
			self::PENDING    => in_array( $to, array( self::PENDING, self::VERIFIED, self::FAILED ), true ),
			self::VERIFIED   => in_array( $to, array( self::VERIFIED, self::FAILED ), true ),
			self::FAILED     => self::PENDING === $to,
		};
	}
}
