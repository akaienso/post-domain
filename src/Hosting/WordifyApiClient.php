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
 * It fails closed. Every path lives in `WordifyEndpoints`; an operation with no
 * route there produces a `WordifyFailure` before a request is made. All six
 * verified routes ship, so that is a floor rather than an expected state. The
 * authentication scheme is fixed in this class and is not configurable: a
 * bearer credential is only a credential under the grammar it was issued for.
 *
 * It never surfaces a response. Bodies are decoded into value objects and then
 * dropped: no body, and no fragment of one, reaches an exception message, a log
 * line or a failure message. The token likewise only exists as the return value
 * of a supplier callable, never as a property, so it cannot be printed by a
 * var_dump of this object.
 *
 * Envelopes differ per route — `/sites` paginates under `data`, `/domains`
 * answers with a top-level array — so decoding is deliberately tolerant of
 * where a list sits (`data`, a named key, or the top level) while being strict
 * about the field names observed in real responses.
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
		private readonly WordifyEndpoints $endpoints,
		private readonly ?string $team_id = null
	) {
		$this->token_supplier = $token_supplier;
	}

	/**
	 * Ready once there is a credential to send.
	 *
	 * The transport is verified and shipped, so readiness is about the operator's
	 * token and nothing else.
	 */
	public function is_ready(): bool {
		return '' !== $this->token();
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

		// `teams` sits beside the user in the observed response, so it is looked
		// for on the record and then on the envelope, rather than assumed.
		$teams = array();

		foreach ( $this->list_in( isset( $record['teams'] ) ? $record : $payload, array( 'teams' ) ) as $team ) {
			if ( ! is_array( $team ) || ! isset( $team['id'] ) || ! is_scalar( $team['id'] ) || '' === (string) $team['id'] ) {
				continue;
			}

			$teams[] = new WordifyTeam( (string) $team['id'], self::field( $team, array( 'name', 'display_name' ) ) );
		}

		return new WordifyAccount(
			isset( $record['id'] ) && is_scalar( $record['id'] ) ? (string) $record['id'] : null,
			$teams,
			self::field( $record, array( 'current_team_id' ) )
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

		$meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

		return new WordifySiteList(
			$sites,
			(int) self::counted( $meta, 'current_page', isset( $filters['page'] ) ? (int) $filters['page'] : 1 ),
			(int) self::counted( $meta, 'per_page', count( $sites ) ),
			self::counted( $meta, 'total', null ),
			self::counted( $meta, 'last_page', null )
		);
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
		// No body: the site is in the path, and inventing a field the contract
		// does not name is how a request starts being rejected for the wrong
		// reason.
		$response = $this->request( WordifyEndpoints::OP_RECHECK, 'POST', null, array( 'site_id' => $site_id ) );

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

		$headers = array(
			// The scheme is part of the credential's meaning, not a header name
			// to be configured. Sending the bare token would authenticate nothing
			// and would put a secret on the wire under the wrong grammar.
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		);

		// Multi-team accounts need to be told which team the request is for.
		// Opaque string: never cast, never compared numerically.
		if ( null !== $this->team_id && '' !== $this->team_id ) {
			$headers[ WordifyEndpoints::TEAM_HEADER ] = $this->team_id;
		}

		$opts = array(
			'timeout'     => self::TIMEOUT,
			// A redirect from an API endpoint is not something to follow with a
			// bearer token attached.
			'redirection' => 0,
			'headers'     => $headers,
		);

		if ( null !== $body ) {
			$opts['body'] = (string) wp_json_encode( $body );
		}

		$response = $this->http->request( $method, $url, $opts );

		if ( null !== $response->error || 0 === $response->status || $response->status >= 500 ) {
			return WordifyFailure::transport( $operation, $response->status );
		}

		$request_id = self::request_id( $response );

		if ( 401 === $response->status ) {
			return WordifyFailure::unauthenticated( $operation, $request_id );
		}

		if ( 403 === $response->status ) {
			return WordifyFailure::insufficient_ability( $operation, $request_id );
		}

		if ( 429 === $response->status ) {
			return WordifyFailure::rate_limited( $operation );
		}

		if ( $response->status >= 400 ) {
			return WordifyFailure::refused( $operation, $response->status );
		}

		return $response;
	}

	/**
	 * Wordify's request id, for a support conversation.
	 *
	 * A correlation id and nothing else: it names no account, no site and no
	 * person, so it is the one part of a refusal that can safely be shown.
	 */
	private static function request_id( HttpResponse $response ): ?string {
		foreach ( $response->headers as $name => $value ) {
			if ( 'x-request-id' === strtolower( (string) $name ) && is_scalar( $value ) ) {
				$id = trim( (string) $value );

				// Bounded and character-restricted: a header is caller-controlled
				// and this one is rendered.
				return 1 === preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $id ) ? $id : null;
			}
		}

		return null;
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

	/**
	 * A count from a pagination envelope, or the fallback.
	 *
	 * @param array<array-key, mixed> $meta
	 */
	private static function counted( array $meta, string $key, ?int $fallback ): ?int {
		return isset( $meta[ $key ] ) && is_numeric( $meta[ $key ] ) ? (int) $meta[ $key ] : $fallback;
	}

	/** @param array<string, mixed> $record */
	private function to_site( array $record ): ?WordifySite {
		if ( ! isset( $record['id'] ) || ! is_scalar( $record['id'] ) || '' === (string) $record['id'] ) {
			return null;
		}

		return new WordifySite(
			(string) $record['id'],
			self::field( $record, array( 'provisioning_status' ) ),
			self::field( $record, array( 'display_name' ) ),
			self::field( $record, array( 'name' ) ),
			self::field( $record, array( 'domain' ) ),
			isset( $record['is_staging'] ) && is_scalar( $record['is_staging'] )
				? (bool) $record['is_staging']
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
			// Current names first. The aliases are a courtesy to an older or a
			// future shape and must never win over a field that is actually
			// present, or a live response would be read through a stale name.
			self::field( $record, array( 'ssl_status', 'ssl_state' ) ),
			self::field( $record, array( 'dns_status' ) ),
			self::field( $record, array( 'dns_checked_at', 'dns_verified_at' ) ),
			self::field( $record, array( 'ssl_checked_at' ) ),
			$reference
		);
	}

	/**
	 * The first of these keys the record actually carries.
	 *
	 * Order is the contract: the observed current name is always first, so an
	 * alias can only fill a gap and can never shadow a present field.
	 *
	 * @param array<string, mixed> $record
	 * @param string[]             $keys
	 */
	private static function field( array $record, array $keys ): ?string {
		foreach ( $keys as $key ) {
			if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) && '' !== (string) $record[ $key ] ) {
				return (string) $record[ $key ];
			}
		}

		return null;
	}
}
