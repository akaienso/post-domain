<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Contracts\HttpClient;
use PostDomain\Support\HttpResponse;

/**
 * The HTTP client for the verified Wordify operations.
 *
 * Two rules shape this class.
 *
 * It fails closed. Every path and the auth header name live in
 * `WordifyEndpoints`; anything not verified there produces a `WordifyFailure`
 * naming the missing piece before a request is made. No path is guessed, and no
 * token is ever sent under a header name nobody read from the specification.
 *
 * It never surfaces a response. Bodies are decoded into value objects and then
 * dropped: no body, and no fragment of one, reaches an exception message, a log
 * line or a failure message. The token likewise only exists as the return value
 * of a supplier callable, never as a property, so it cannot be printed by a
 * var_dump of this object.
 *
 * The response envelope is not verified either, so decoding is deliberately
 * tolerant of where a list sits (`data`, a named key, or the top level) while
 * being strict about the field names the tool schemas do state.
 *
 * @package PostDomain
 */
final class WordifyApiClient implements WordifyClient {

	/** Short on purpose: an admin request must not hang on a hosting provider. */
	private const TIMEOUT = 8;

	/**
	 * @var callable():string
	 */
	private $token_supplier;

	/**
	 * @param callable():string $token_supplier Resolved per request; never stored as a token.
	 */
	public function __construct(
		private readonly HttpClient $http,
		callable $token_supplier,
		private readonly WordifyEndpoints $endpoints
	) {
		$this->token_supplier = $token_supplier;
	}

	public function is_ready(): bool {
		return null !== $this->endpoints->auth_header() && '' !== $this->token();
	}

	/** @return WordifyAccount|WordifyFailure */
	public function me() {
		$response = $this->request( WordifyEndpoints::OP_ME, 'GET' );

		if ( $response instanceof WordifyFailure ) {
			return $response;
		}

		$payload = $this->payload( $response, WordifyEndpoints::OP_ME );

		if ( $payload instanceof WordifyFailure ) {
			return $payload;
		}

		$record = $this->record( $payload, array( 'user', 'me' ) );
		$teams  = $this->list_in( $payload, array( 'teams' ) );

		$team_ids = array();

		foreach ( $teams as $team ) {
			if ( is_array( $team ) && isset( $team['id'] ) && is_scalar( $team['id'] ) ) {
				$team_ids[] = (string) $team['id'];
			}
		}

		return new WordifyAccount(
			isset( $record['id'] ) && is_scalar( $record['id'] ) ? (string) $record['id'] : null,
			$team_ids
		);
	}

	/**
	 * @param array<string, string> $filters
	 * @return WordifySiteList|WordifyFailure
	 */
	public function sites( array $filters = array() ) {
		$response = $this->request( WordifyEndpoints::OP_SITES, 'GET', null, array(), $filters );

		if ( $response instanceof WordifyFailure ) {
			return $response;
		}

		$payload = $this->payload( $response, WordifyEndpoints::OP_SITES );

		if ( $payload instanceof WordifyFailure ) {
			return $payload;
		}

		$sites = array();

		foreach ( $this->list_in( $payload, array( 'sites' ) ) as $record ) {
			$site = is_array( $record ) ? $this->to_site( $record ) : null;

			if ( null !== $site ) {
				$sites[] = $site;
			}
		}

		return new WordifySiteList( $sites );
	}

	/** @return WordifySite|WordifyFailure */
	public function site( string $site_id ) {
		$response = $this->request( WordifyEndpoints::OP_SITE, 'GET', null, array( 'site_id' => $site_id ) );

		if ( $response instanceof WordifyFailure ) {
			return $response;
		}

		$payload = $this->payload( $response, WordifyEndpoints::OP_SITE );

		if ( $payload instanceof WordifyFailure ) {
			return $payload;
		}

		$site = $this->to_site( $this->record( $payload, array( 'site' ) ) );

		return $site ?? WordifyFailure::malformed( WordifyEndpoints::OP_SITE, $response->status );
	}

	/** @return WordifyDomainList|WordifyFailure */
	public function domains( string $site_id ) {
		$response = $this->request( WordifyEndpoints::OP_DOMAINS, 'GET', null, array( 'site_id' => $site_id ) );

		return $this->domain_list( $response, WordifyEndpoints::OP_DOMAINS );
	}

	/**
	 * Attaches a hostname. `make_primary` is hard-coded false and is not a
	 * parameter of this method: promoting a mapped hostname to a site's primary
	 * domain would change where the whole site answers, which is never something
	 * a domain mapping should do on its own.
	 *
	 * @return WordifyDomain|WordifyFailure
	 */
	public function attach_domain( string $site_id, string $host ) {
		$response = $this->request(
			WordifyEndpoints::OP_ATTACH_DOMAIN,
			'POST',
			array(
				'site_id'      => $site_id,
				'domain'       => $host,
				'make_primary' => false,
			),
			array( 'site_id' => $site_id )
		);

		if ( $response instanceof WordifyFailure ) {
			return $response;
		}

		$payload = $this->payload( $response, WordifyEndpoints::OP_ATTACH_DOMAIN );

		if ( $payload instanceof WordifyFailure ) {
			return $payload;
		}

		$domain = $this->to_domain( $this->record( $payload, array( 'domain' ) ) );

		return $domain ?? WordifyFailure::malformed( WordifyEndpoints::OP_ATTACH_DOMAIN, $response->status );
	}

	/** @return WordifyDomainList|WordifyFailure */
	public function recheck( string $site_id ) {
		$response = $this->request( WordifyEndpoints::OP_RECHECK, 'POST', array( 'site_id' => $site_id ), array( 'site_id' => $site_id ) );

		return $this->domain_list( $response, WordifyEndpoints::OP_RECHECK );
	}

	/**
	 * @param HttpResponse|WordifyFailure $response
	 * @return WordifyDomainList|WordifyFailure
	 */
	private function domain_list( $response, string $operation ) {
		if ( $response instanceof WordifyFailure ) {
			return $response;
		}

		$payload = $this->payload( $response, $operation );

		if ( $payload instanceof WordifyFailure ) {
			return $payload;
		}

		$domains = array();

		foreach ( $this->list_in( $payload, array( 'domains' ) ) as $record ) {
			$domain = is_array( $record ) ? $this->to_domain( $record ) : null;

			if ( null !== $domain ) {
				$domains[] = $domain;
			}
		}

		return new WordifyDomainList( $domains );
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @param array<string, string>     $tokens
	 * @param array<string, string>     $query
	 * @return HttpResponse|WordifyFailure
	 */
	private function request( string $operation, string $method, ?array $body = null, array $tokens = array(), array $query = array() ) {
		$header = $this->endpoints->auth_header();

		if ( null === $header ) {
			return WordifyFailure::auth_unverified( $operation );
		}

		$url = $this->endpoints->url( $operation, $tokens );

		if ( null === $url ) {
			return WordifyFailure::endpoint_unverified( $operation );
		}

		$token = $this->token();

		if ( '' === $token ) {
			return WordifyFailure::not_configured( $operation );
		}

		if ( array() !== $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query );
		}

		$opts = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				$header        => $token,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$opts['body'] = (string) wp_json_encode( $body );
		}

		$response = $this->http->request( $method, $url, $opts );

		if ( null !== $response->error || 0 === $response->status || $response->status >= 500 ) {
			return WordifyFailure::transport( $operation, $response->status );
		}

		if ( 429 === $response->status ) {
			return WordifyFailure::rate_limited( $operation );
		}

		if ( $response->status >= 400 ) {
			return WordifyFailure::refused( $operation, $response->status );
		}

		return $response;
	}

	private function token(): string {
		return ( $this->token_supplier )();
	}

	/**
	 * The decoded body, or a failure. The body itself never leaves this method.
	 *
	 * @return array<array-key, mixed>|WordifyFailure
	 */
	private function payload( HttpResponse $response, string $operation ) {
		/** @var mixed $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) ) {
			return WordifyFailure::malformed( $operation, $response->status );
		}

		return $decoded;
	}

	/**
	 * A single record, wherever the envelope put it. The envelope is unverified,
	 * so `data`, a named key, and the top level are all accepted.
	 *
	 * @param array<array-key, mixed> $payload
	 * @param string[]                $keys
	 * @return array<string, mixed>
	 */
	private function record( array $payload, array $keys ): array {
		foreach ( array_merge( array( 'data' ), $keys ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) && ! isset( $payload[ $key ][0] ) ) {
				/** @var array<string, mixed> $record */
				$record = $payload[ $key ];

				return $record;
			}
		}

		/** @var array<string, mixed> $payload */
		return $payload;
	}

	/**
	 * A list of records, wherever the envelope put it.
	 *
	 * @param array<array-key, mixed> $payload
	 * @param string[]                $keys
	 * @return array<int, mixed>
	 */
	private function list_in( array $payload, array $keys ): array {
		foreach ( array_merge( array( 'data' ), $keys ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				return array_values( $payload[ $key ] );
			}
		}

		return isset( $payload[0] ) ? array_values( $payload ) : array();
	}

	/** @param array<string, mixed> $record */
	private function to_site( array $record ): ?WordifySite {
		if ( ! isset( $record['id'] ) || ! is_scalar( $record['id'] ) || '' === (string) $record['id'] ) {
			return null;
		}

		return new WordifySite(
			(string) $record['id'],
			isset( $record['provisioning_status'] ) && is_scalar( $record['provisioning_status'] )
				? (string) $record['provisioning_status']
				: null
		);
	}

	/** @param array<string, mixed> $record */
	private function to_domain( array $record ): ?WordifyDomain {
		$host = '';

		foreach ( array( 'domain', 'host', 'name' ) as $key ) {
			if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) && '' !== (string) $record[ $key ] ) {
				$host = (string) $record[ $key ];
				break;
			}
		}

		if ( '' === $host ) {
			return null;
		}

		$reference = isset( $record['id'] ) && is_scalar( $record['id'] ) && '' !== (string) $record['id']
			? (string) $record['id']
			: $host;

		return new WordifyDomain(
			$host,
			isset( $record['is_primary'] ) && (bool) $record['is_primary'],
			isset( $record['ssl_state'] ) && is_scalar( $record['ssl_state'] ) ? (string) $record['ssl_state'] : null,
			isset( $record['dns_verified_at'] ) && is_scalar( $record['dns_verified_at'] ) ? (string) $record['dns_verified_at'] : null,
			$reference
		);
	}
}
