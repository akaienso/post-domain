<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Total, never throws. Its output is the stored key, the lookup key, and what
 * goes into DNS.
 */
final class HostNormalizer {

	public function __construct( private readonly IdnaNormalizer $idna ) {}

	public function normalize( Authority $authority ): ?string {
		if ( $authority->is_ipv6_literal ) {
			return null;
		}

		$host = $authority->host;

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( str_ends_with( $host, '.' ) ) {
			$host = substr( $host, 0, -1 );
		}

		if ( '' === $host || str_contains( $host, '*' ) ) {
			return null;
		}

		$ascii = $this->idna->to_ascii( $host );

		if ( null === $ascii || strlen( $ascii ) > 253 ) {
			return null;
		}

		foreach ( explode( '.', $ascii ) as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return null;
			}
		}

		return $ascii;
	}
}
