<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Contracts\HttpClient;

/**
 * The grace policy depends on an RCODE, which dns_get_record() cannot supply.
 * A hard outcome requires two independent endpoints to agree.
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
		$outcomes = array();
		$values   = array();

		foreach ( $this->endpoints as $endpoint ) {
			$single = $this->query( $endpoint, $name, $expected );

			$outcomes[] = $single->outcome;
			$values     = array_merge( $values, $single->values );
		}

		if ( array() === $outcomes ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'no endpoints configured' );
		}

		$distinct = array_unique( array_map( static fn( DnsOutcome $o ): string => $o->value, $outcomes ) );

		if ( 1 !== count( $distinct ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, $values, 'endpoints disagreed' );
		}

		return new DnsResult( $outcomes[0], array_values( array_unique( $values ) ) );
	}

	private function query( string $endpoint, string $name, string $expected ): DnsResult {
		if ( ! str_starts_with( $endpoint, 'https://' ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'endpoint is not https' );
		}

		$url = $endpoint . '?name=' . rawurlencode( $name ) . '&type=TXT';

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

	/** Concatenates the character-strings of one TXT record, per RFC 1035. */
	private function unquote( string $data ): string {
		if ( 0 === preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches ) ) {
			return trim( $data, '"' );
		}

		return implode( '', $matches[1] );
	}
}
