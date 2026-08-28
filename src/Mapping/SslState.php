<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum SslState: string {
	case NONE               = 'none';
	case REQUESTED          = 'requested';
	case PENDING_VALIDATION = 'pending_validation';
	case ACTIVE             = 'active';
	case FAILED             = 'failed';
	case PENDING_REMOVAL    = 'pending_removal';
	case REVOKED            = 'revoked';

	public function can_transition_to( self $to ): bool {
		if ( self::REVOKED === $to ) {
			return self::PENDING_REMOVAL === $this || self::REVOKED === $this;
		}

		if ( self::PENDING_REMOVAL === $to ) {
			return in_array(
				$this,
				array( self::REQUESTED, self::PENDING_VALIDATION, self::ACTIVE, self::FAILED, self::PENDING_REMOVAL ),
				true
			);
		}

		if ( self::FAILED === $to ) {
			return true;
		}

		return match ( $this ) {
			self::NONE               => in_array( $to, array( self::NONE, self::REQUESTED, self::ACTIVE ), true ),
			self::REQUESTED          => in_array( $to, array( self::REQUESTED, self::PENDING_VALIDATION, self::ACTIVE ), true ),
			self::PENDING_VALIDATION => in_array( $to, array( self::PENDING_VALIDATION, self::ACTIVE ), true ),
			self::ACTIVE             => self::ACTIVE === $to,
			self::PENDING_REMOVAL    => false,
			self::REVOKED            => self::REQUESTED === $to,
		};
	}
}
