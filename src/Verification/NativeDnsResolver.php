<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;

/**
 * For hosts that cannot make outbound HTTPS calls. Restricted by design: it may
 * emit only MATCH, MISMATCH, or TRANSIENT, so it can never deactivate a verified
 * mapping. dns_get_record() returns an empty array for NXDOMAIN, for
 * NOERROR-with-no-TXT, and for SERVFAIL alike.
 */
final class NativeDnsResolver implements DnsResolver {

	/** @var callable */
	private $lookup;

	public function __construct( ?callable $lookup = null ) {
		$this->lookup = $lookup ?? static fn( string $name ) => @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	public function txt( string $name, string $expected ): DnsResult {
		/** @var mixed $records */
		$records = ( $this->lookup )( $name );

		if ( ! is_array( $records ) || array() === $records ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'no answer, cause indistinguishable' );
		}

		$values = array();

		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['txt'] ) ) {
				$values[] = (string) $record['txt'];
			}
		}

		if ( array() === $values ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'no TXT strings in the answer' );
		}

		foreach ( $values as $value ) {
			if ( hash_equals( $expected, $value ) ) {
				return new DnsResult( DnsOutcome::MATCH, $values );
			}
		}

		return new DnsResult( DnsOutcome::MISMATCH, $values );
	}
}
