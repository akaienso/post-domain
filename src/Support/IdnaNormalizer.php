<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use Symfony\Polyfill\Intl\Idn\Idn;

/**
 * The single UTS-46 implementation. Called through the class, never through the
 * global idn_to_* functions: those exist only when a PHP extension provides
 * them, and two UTS-46 implementations disagreeing on one input is a
 * verification-bypass shape.
 */
final class IdnaNormalizer {

	private const VARIANT = INTL_IDNA_VARIANT_UTS46;

	private const FLAGS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

	public function to_ascii( string $host ): ?string {
		$unicode = Idn::idn_to_utf8( $host, IDNA_NONTRANSITIONAL_TO_UNICODE, self::VARIANT, $unicode_info );

		if ( false === $unicode ) {
			return null;
		}

		$ascii = Idn::idn_to_ascii( $unicode, self::FLAGS, self::VARIANT, $ascii_info );

		if ( false === $ascii || '' === $ascii ) {
			return null;
		}

		$ascii = strtolower( $ascii );

		// The round trip must be stable, which is what catches invalid punycode.
		$again = Idn::idn_to_utf8( $ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, self::VARIANT );

		if ( false === $again ) {
			return null;
		}

		$restable = Idn::idn_to_ascii( $again, self::FLAGS, self::VARIANT );

		if ( false === $restable || strtolower( (string) $restable ) !== $ascii ) {
			return null;
		}

		return $ascii;
	}

	public function to_display( string $ascii ): string {
		$unicode = Idn::idn_to_utf8( $ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, self::VARIANT );

		return false === $unicode ? $ascii : $unicode;
	}
}
