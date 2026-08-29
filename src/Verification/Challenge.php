<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\Mapping;

final class Challenge {

	public const DEFAULT_LABEL = '_post-domain-challenge';

	public const VALUE_PREFIX = 'post-domain-verify=';

	private const MAX_NAME = 253;

	public static function token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Runs the filter. Called only at create and rotate — ordinary verification
	 * composes the name from the persisted label instead.
	 */
	public static function label_for( Mapping $mapping ): string {
		$label = (string) apply_filters( 'pd_txt_record_label', self::DEFAULT_LABEL, $mapping );
		$label = strtolower( $label );

		return self::is_valid_label( $label ) ? $label : self::DEFAULT_LABEL;
	}

	/**
	 * Validates a label's own shape, which is what makes a persisted label
	 * trustworthy at read time. A label carrying a dot is not one label.
	 */
	public static function is_valid_label( string $label ): bool {
		return 1 === preg_match( '/^_?[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label ) && strlen( $label ) <= 63;
	}

	/** A challenge token is exactly 32 lowercase hex characters, always. */
	public static function is_valid_token( string $token ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}$/', $token );
	}

	public static function record_name( string $label, string $host ): ?string {
		$name = $label . '.' . $host;

		if ( strlen( $name ) > self::MAX_NAME ) {
			return null;
		}

		foreach ( explode( '.', $name ) as $part ) {
			if ( '' === $part || strlen( $part ) > 63 ) {
				return null;
			}
		}

		return $name;
	}

	public static function expected_value( string $token ): string {
		return self::VALUE_PREFIX . $token;
	}

	public static function max_host_length( string $label ): int {
		return self::MAX_NAME - strlen( $label ) - 1;
	}
}
