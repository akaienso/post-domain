<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

enum DnsOutcome: string {
	case MATCH     = 'match';
	case MISMATCH  = 'mismatch';
	case NO_RECORD = 'no_record';
	case NXDOMAIN  = 'nxdomain';
	case TRANSIENT = 'transient';

	public function is_hard(): bool {
		return in_array( $this, array( self::MISMATCH, self::NO_RECORD, self::NXDOMAIN ), true );
	}
}
