<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

use PostDomain\Routing\ServingContext;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;

/**
 * Validates a BARE HOST returned by a filter. Distinct from AbsoluteUrl, which
 * validates a full URL: conflating them is how a scheme downgrade slips through.
 */
final class HostValue {

	public static function validated( string $host, ServingContext $context ): ?string {
		if ( str_contains( $host, '://' ) || str_contains( $host, '/' ) ) {
			return null;
		}

		$authority = ( new AuthorityParser() )->parse( $host );

		if ( null === $authority || null !== $authority->port ) {
			return null;
		}

		$ascii = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $ascii ) {
			return null;
		}

		return in_array( $ascii, array( $context->requested_host, $context->canonical_host ), true )
			? $ascii
			: null;
	}
}
