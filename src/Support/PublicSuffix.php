<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use Pdp\Domain;
use Pdp\Rules;

/**
 * Apex is decided against the registrable domain, never a label count: a label
 * count is wrong for example.co.uk and every other multi-label suffix.
 */
final class PublicSuffix {

	private static ?Rules $rules = null;

	public static function is_apex( string $host ): bool {
		try {
			$resolved = self::rules()->resolve( Domain::fromIDNA2008( $host ) );

			return $resolved->registrableDomain()->toString() === $host;
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}

	private static function rules(): Rules {
		if ( null === self::$rules ) {
			self::$rules = Rules::fromPath( dirname( __DIR__, 2 ) . '/references/public_suffix_list.dat' );
		}

		return self::$rules;
	}
}
