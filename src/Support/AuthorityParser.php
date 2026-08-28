<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * A Host header is an authority, not a hostname. Anything this returns null for
 * is MALFORMED_400 — never silently repaired, because a repaired authority could
 * match an allowlist entry it has no right to.
 */
final class AuthorityParser {

	public function parse( string $raw ): ?Authority {
		$value = trim( $raw, " \t" );

		if ( '' === $value ) {
			return null;
		}

		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return null;
		}

		if ( 1 === preg_match( '~[\s/\\\\?#@]~', $value ) ) {
			return null;
		}

		if ( str_starts_with( $value, '[' ) ) {
			return $this->parse_bracketed( $value );
		}

		if ( substr_count( $value, ':' ) > 1 ) {
			return null;
		}

		$host = $value;
		$port = null;

		if ( str_contains( $value, ':' ) ) {
			[ $host, $port_text ] = explode( ':', $value, 2 );
			$port                 = $this->parse_port( $port_text );

			if ( null === $port || '' === $host ) {
				return null;
			}
		}

		return new Authority( $host, $port, false, $host );
	}

	private function parse_bracketed( string $value ): ?Authority {
		if ( 1 !== preg_match( '/^\[([0-9A-Fa-f:.]+)\](?::([0-9]+))?$/', $value, $matches ) ) {
			return null;
		}

		$literal = $matches[1];

		if ( false === filter_var( $literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return null;
		}

		$port = null;

		if ( isset( $matches[2] ) ) {
			$port = $this->parse_port( $matches[2] );

			if ( null === $port ) {
				return null;
			}
		}

		return new Authority( $literal, $port, true, '[' . $literal . ']' );
	}

	private function parse_port( string $text ): ?int {
		if ( 1 !== preg_match( '/^[0-9]+$/', $text ) ) {
			return null;
		}

		$port = (int) $text;

		return ( $port >= 1 && $port <= 65535 ) ? $port : null;
	}
}
