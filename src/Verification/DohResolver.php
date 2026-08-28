<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Contracts\HttpClient;

/**
 * The grace policy depends on an RCODE, which dns_get_record() cannot supply.
 *
 * A hard outcome requires two independent endpoints to agree — and "two
 * independent endpoints" is enforced, not assumed. Unanimity among one answer is
 * not agreement, and a list that names the same resolver twice is one resolver.
 * Below two distinct usable endpoints the only outcome is TRANSIENT, so a
 * misconfigured or filtered-down endpoint list can never deactivate a live
 * mapping on a single opinion.
 */
final class DohResolver implements DnsResolver {

	private const MAX_BYTES = 65536;

	private const TYPE_TXT = 16;

	/** @param string[] $endpoints */
	public function __construct(
		private readonly HttpClient $http,
		private readonly array $endpoints
	) {}

	public function txt( string $name, string $expected ): DnsResult {
		$usable = $this->usable_endpoints();

		// Below two, there is nothing to agree with. An endpoint that is missing,
		// non-HTTPS, unparseable, or a duplicate of another is disqualified here
		// rather than allowed to cast a TRANSIENT vote, because a vote it cannot
		// cast honestly is not the same as a vote against.
		if ( count( $usable ) < 2 ) {
			return new DnsResult(
				DnsOutcome::TRANSIENT,
				array(),
				sprintf(
					'a hard outcome needs two distinct https endpoints; %d usable',
					count( $usable )
				)
			);
		}

		$outcomes = array();
		$values   = array();

		foreach ( $usable as $endpoint ) {
			$single = $this->query( $endpoint, $name, $expected );

			$outcomes[] = $single->outcome;
			$values     = array_merge( $values, $single->values );
		}

		$distinct = array_unique( array_map( static fn( DnsOutcome $o ): string => $o->value, $outcomes ) );

		if ( 1 !== count( $distinct ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, $values, 'endpoints disagreed' );
		}

		return new DnsResult( $outcomes[0], array_values( array_unique( $values ) ) );
	}

	private function query( string $endpoint, string $name, string $expected ): DnsResult {
		// No scheme check here: usable_endpoints() has already rejected anything
		// that is not HTTPS, so a non-HTTPS endpoint never reaches a vote.
		// A normalized endpoint may legitimately carry a query of its own, which is
		// part of what makes it distinct from another on the same host.
		$separator = str_contains( $endpoint, '?' ) ? '&' : '?';
		$url       = $endpoint . $separator . 'name=' . rawurlencode( $name ) . '&type=TXT';

		$response = $this->http->request(
			'GET',
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/dns-json' ),
			)
		);

		if ( null !== $response->error || 200 !== $response->status ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), $response->error ?? 'http ' . $response->status );
		}

		$type = strtolower( $response->headers['content-type'] ?? '' );

		if ( ! str_contains( $type, 'json' ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'unexpected content type' );
		}

		if ( strlen( $response->body ) > self::MAX_BYTES ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'oversize response' );
		}

		/** @var mixed $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['Status'] ) || ! is_int( $decoded['Status'] ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'malformed response' );
		}

		if ( 3 === $decoded['Status'] ) {
			return new DnsResult( DnsOutcome::NXDOMAIN );
		}

		if ( 0 !== $decoded['Status'] ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'rcode ' . $decoded['Status'] );
		}

		$answers = $decoded['Answer'] ?? array();

		if ( ! is_array( $answers ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'malformed answer section' );
		}

		$values = array();

		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) || ( $answer['type'] ?? null ) !== self::TYPE_TXT ) {
				continue;
			}

			$values[] = $this->unquote( (string) ( $answer['data'] ?? '' ) );
		}

		if ( array() === $values ) {
			return new DnsResult( DnsOutcome::NO_RECORD );
		}

		foreach ( $values as $value ) {
			if ( hash_equals( $expected, $value ) ) {
				return new DnsResult( DnsOutcome::MATCH, $values );
			}
		}

		return new DnsResult( DnsOutcome::MISMATCH, $values );
	}

	/**
	 * The configured endpoints, normalized, filtered to the usable ones, and
	 * deduplicated — in configuration order.
	 *
	 * @return string[]
	 */
	private function usable_endpoints(): array {
		$seen = array();

		foreach ( $this->endpoints as $endpoint ) {
			if ( ! is_string( $endpoint ) ) {
				continue;
			}

			$normalized = self::normalize( $endpoint );

			if ( null === $normalized ) {
				continue;
			}

			// Keyed by the normalized form: two spellings of one resolver collapse
			// into one entry rather than counting as independent corroboration.
			$seen[ $normalized ] = $normalized;
		}

		return array_values( $seen );
	}

	/**
	 * Normalizes an endpoint, or returns null if it cannot be used at all.
	 *
	 * "Trivially equivalent" means: written differently in a way that cannot
	 * change which server is asked or what is asked of it. That is exactly —
	 *
	 * - surrounding whitespace;
	 * - the case of the scheme and of the host, both case-insensitive per
	 *   RFC 3986 §3.1 and §3.2.2;
	 * - an explicitly written default port (`:443` under https);
	 * - a trailing slash on the path.
	 *
	 * Everything else is left alone and stays distinguishing: a different host,
	 * a non-default port, a different path, or a query string all describe
	 * genuinely different endpoints, so they are kept apart.
	 *
	 * Unusable: anything that is not HTTPS (the transport hardening in §13.2 is
	 * not optional), anything `parse_url()` cannot read, anything with no host,
	 * and anything carrying userinfo — credentials on a public DoH endpoint are
	 * never meaningful and dropping them silently would change the request.
	 */
	private static function normalize( string $endpoint ): ?string {
		// parse_url(), not wp_parse_url(): this class is exercised by the unit
		// suite, which boots no WordPress. The inconsistency wp_parse_url() papers
		// over is in relative URLs, and a relative endpoint has no scheme and is
		// rejected here anyway.
		$parts = parse_url( trim( $endpoint ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( ! is_array( $parts ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( 'https' !== $scheme || '' === $host ) {
			return null;
		}

		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$authority = $host . ( null === $port || 443 === $port ? '' : ':' . $port );
		$path      = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query     = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';

		return 'https://' . $authority . $path . $query;
	}

	/** Concatenates the character-strings of one TXT record, per RFC 1035. */
	private function unquote( string $data ): string {
		if ( 0 === preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches ) ) {
			return trim( $data, '"' );
		}

		return implode( '', $matches[1] );
	}
}
