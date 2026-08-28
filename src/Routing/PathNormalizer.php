<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class PathNormalizer {

	/**
	 * @return string[]|null Null means the path must not resolve.
	 */
	public function segments( string $base ): ?array {
		if ( str_contains( strtolower( $base ), '%2f' ) || str_contains( strtolower( $base ), '%5c' ) ) {
			return null;
		}

		$parts = preg_split( '~/+~', trim( $base, '/' ) );

		if ( false === $parts ) {
			return null;
		}

		$segments = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$decoded = rawurldecode( $part );

			if ( '.' === $decoded || '..' === $decoded ) {
				return null;
			}

			$segments[] = \Normalizer::isNormalized( $decoded )
				? $decoded
				: (string) \Normalizer::normalize( $decoded );
		}

		return $segments;
	}
}
