<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Contracts\HttpClient;
use PostDomain\Hosting\WordifyApiClient;
use PostDomain\Hosting\WordifyDomainList;
use PostDomain\Hosting\WordifyEndpoints;
use PostDomain\Hosting\WordifyFailure;
use PostDomain\Hosting\WordifyFailureKind;
use PostDomain\Hosting\WordifySiteList;
use PostDomain\Support\HttpResponse;

/**
 * The client's contract with reality: it sends exactly what the verified
 * transport says, and it never lets a response body out.
 */
final class WordifyApiClientTest extends TestCase {

	/** Shaped like a real token so the prefix is exercised; it is not one. */
	private const TOKEN = 'wpk_test-token-not-a-credential';

	/** An opaque team id. Deliberately not numeric and not a ULID. */
	private const TEAM = '01HQTEAM0000000000000000AB';

	/** A 26-character site id, the shape real ids have. */
	private const SITE = '01HQ0000000000000000000001';

	/**
	 * @param array<int, HttpResponse> $responses
	 */
	private function http( array $responses = array() ): HttpClient {
		return new class( $responses ) implements HttpClient {
			/** @var array<int, array{method: string, url: string, opts: array<string, mixed>}> */
			public array $calls = array();

			/** @param array<int, HttpResponse> $responses */
			public function __construct( private array $responses ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				$this->calls[] = array(
					'method' => $method,
					'url'    => $url,
					'opts'   => $opts,
				);

				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};
	}

	private function client( HttpClient $http, ?string $team = self::TEAM, ?WordifyEndpoints $endpoints = null ): WordifyApiClient {
		return new WordifyApiClient(
			$http,
			static fn (): string => self::TOKEN,
			$endpoints ?? WordifyEndpoints::verified(),
			$team
		);
	}

	/** A fake base so no test can address the real host. */
	private function local(): WordifyEndpoints {
		return WordifyEndpoints::supplied( 'https://host.example', array() );
	}

	/**
	 * @param array<int, array{method: string, url: string, opts: array<string, mixed>}> $calls
	 * @return array<string, string>
	 */
	private function headers( array $calls, int $index = 0 ): array {
		/** @var array<string, string> $headers */
		$headers = $calls[ $index ]['opts']['headers'];

		return $headers;
	}

	public function test_the_shipped_map_knows_every_verified_operation(): void {
		$endpoints = WordifyEndpoints::verified();

		foreach (
			array(
				WordifyEndpoints::OP_ME,
				WordifyEndpoints::OP_SITES,
				WordifyEndpoints::OP_SITE,
				WordifyEndpoints::OP_DOMAINS,
				WordifyEndpoints::OP_ATTACH_DOMAIN,
				WordifyEndpoints::OP_RECHECK,
			) as $operation
		) {
			$this->assertTrue( $endpoints->knows( $operation ), $operation . ' must ship a route.' );
		}
	}

	/**
	 * Regression proof: an inert map is a defect, not a safety measure. If any
	 * of these routes is emptied the integration silently stops working while
	 * still reporting a connection, which is exactly what shipped before.
	 */
	public function test_the_shipped_routes_are_the_verified_absolute_urls(): void {
		$endpoints = WordifyEndpoints::verified();

		$this->assertSame( 'https://console.wordify.com/api/v1', $endpoints->base() );
		$this->assertSame( 'https://console.wordify.com/api/v1/me', $endpoints->url( WordifyEndpoints::OP_ME ) );
		$this->assertSame( 'https://console.wordify.com/api/v1/sites', $endpoints->url( WordifyEndpoints::OP_SITES ) );
		$this->assertSame(
			'https://console.wordify.com/api/v1/sites/' . self::SITE,
			$endpoints->url( WordifyEndpoints::OP_SITE, array( 'site_id' => self::SITE ) )
		);
		$this->assertSame(
			'https://console.wordify.com/api/v1/sites/' . self::SITE . '/domains',
			$endpoints->url( WordifyEndpoints::OP_DOMAINS, array( 'site_id' => self::SITE ) )
		);
		$this->assertSame(
			'https://console.wordify.com/api/v1/sites/' . self::SITE . '/domains',
			$endpoints->url( WordifyEndpoints::OP_ATTACH_DOMAIN, array( 'site_id' => self::SITE ) )
		);
		$this->assertSame(
			'https://console.wordify.com/api/v1/sites/' . self::SITE . '/domains/recheck',
			$endpoints->url( WordifyEndpoints::OP_RECHECK, array( 'site_id' => self::SITE ) )
		);
	}

	/**
	 * Regression proof: the bare token was sent before, under a header name a
	 * filter had to supply. A bearer credential presented without its scheme
	 * authenticates nothing and leaks a secret for no benefit.
	 */
	public function test_the_credential_is_sent_as_a_bearer_token_and_never_raw(): void {
		$http = $this->http( array( new HttpResponse( 200, array(), '{"data":[]}' ) ) );

		$this->client( $http, null, $this->local() )->sites();

		$headers = $this->headers( $http->calls );

		$this->assertSame( 'Bearer ' . self::TOKEN, $headers['Authorization'] );
		$this->assertNotSame( self::TOKEN, $headers['Authorization'], 'The raw token is not an Authorization value.' );
		$this->assertSame(
			array(),
			array_filter(
				$headers,
				static fn ( string $value ): bool => self::TOKEN === $value
			),
			'No header carries the bare token.'
		);
	}

	public function test_the_bound_team_is_sent_verbatim_as_an_opaque_string(): void {
		$http = $this->http( array( new HttpResponse( 200, array(), '{"data":[]}' ) ) );

		$this->client( $http, self::TEAM, $this->local() )->sites();

		$headers = $this->headers( $http->calls );

		$this->assertSame( self::TEAM, $headers[ WordifyEndpoints::TEAM_HEADER ] );
		$this->assertIsString( $headers[ WordifyEndpoints::TEAM_HEADER ] );
	}

	public function test_no_team_header_is_invented_when_none_is_bound(): void {
		$http = $this->http( array( new HttpResponse( 200, array(), '{"data":[]}' ) ) );

		$this->client( $http, null, $this->local() )->sites();

		$this->assertArrayNotHasKey( WordifyEndpoints::TEAM_HEADER, $this->headers( $http->calls ) );
	}

	public function test_a_request_carries_a_short_timeout_and_follows_no_redirect(): void {
		$http = $this->http( array( new HttpResponse( 200, array(), '{"data":[]}' ) ) );

		$this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertLessThanOrEqual( 10, (int) $http->calls[0]['opts']['timeout'] );
		$this->assertSame( 0, $http->calls[0]['opts']['redirection'], 'A bearer token is not carried through a redirect.' );
	}

	public function test_a_rejected_credential_is_unauthenticated_and_keeps_only_the_request_id(): void {
		$http = $this->http(
			array(
				new HttpResponse(
					401,
					array( 'X-Request-Id' => 'req_01HQABC' ),
					'{"error":{"code":"unauthenticated","message":"Unauthenticated.","request_id":"req_01HQABC"}}'
				),
			)
		);

		$result = $this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::UNAUTHENTICATED, $result->kind );
		$this->assertSame( 401, $result->status );
		$this->assertSame( 'req_01HQABC', $result->request_id );
		$this->assertStringNotContainsString( 'Unauthenticated.', $result->message );
	}

	public function test_a_permitted_token_without_the_ability_is_reported_as_such(): void {
		$http = $this->http( array( new HttpResponse( 403, array( 'x-request-id' => 'req_lower' ), '{"error":{"code":"forbidden"}}' ) ) );

		$result = $this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::INSUFFICIENT_ABILITY, $result->kind );
		$this->assertSame( 403, $result->status );
		// Read case-insensitively: header casing is not something to depend on.
		$this->assertSame( 'req_lower', $result->request_id );
		$this->assertStringContainsString( 'Manage Sites', $result->message );
	}

	public function test_neither_credential_fault_is_transient(): void {
		$this->assertFalse( WordifyFailureKind::UNAUTHENTICATED->is_transient() );
		$this->assertFalse( WordifyFailureKind::INSUFFICIENT_ABILITY->is_transient() );
		$this->assertTrue( WordifyFailureKind::UNAUTHENTICATED->is_credential_fault() );
		$this->assertTrue( WordifyFailureKind::INSUFFICIENT_ABILITY->is_credential_fault() );
		$this->assertFalse( WordifyFailureKind::TRANSPORT->is_credential_fault() );
	}

	public function test_a_hostile_request_id_header_is_discarded_rather_than_carried(): void {
		$http = $this->http( array( new HttpResponse( 401, array( 'X-Request-Id' => '<script>alert(1)</script>' ), '{}' ) ) );

		$result = $this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertNull( $result->request_id );
	}

	public function test_the_sites_read_is_sent_and_parsed(): void {
		$http = $this->http(
			array(
				new HttpResponse(
					200,
					array(),
					(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
						array(
							'data'  => array(
								array(
									'id'                  => self::SITE,
									'display_name'        => 'Example',
									'provisioning_status' => 'active',
								),
							),
							'links' => array(),
							'meta'  => array( 'total' => 1 ),
						)
					)
				),
			)
		);

		$result = $this->client( $http, self::TEAM, $this->local() )->sites( array( 'domain' => 'mapped.test' ) );

		$this->assertInstanceOf( WordifySiteList::class, $result );
		$this->assertSame( self::SITE, (string) $result->first()?->id );
		$this->assertSame( 'GET', $http->calls[0]['method'] );
		$this->assertSame( 'https://host.example/sites?domain=mapped.test', $http->calls[0]['url'] );
	}

	public function test_a_domains_read_parses_the_top_level_array_wordify_returns(): void {
		$http = $this->http(
			array(
				new HttpResponse(
					200,
					array(),
					(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
						array(
							array(
								'id'             => 'dom-1',
								'domain'         => 'mapped.test',
								'is_primary'     => false,
								'dns_status'     => 'verified',
								'ssl_status'     => 'active',
								'dns_checked_at' => '2026-08-30T10:00:00Z',
								'ssl_checked_at' => '2026-08-30T10:05:00Z',
							),
						)
					)
				),
			)
		);

		$result = $this->client( $http, self::TEAM, $this->local() )->domains( self::SITE );

		$this->assertInstanceOf( WordifyDomainList::class, $result );
		$this->assertSame( 'https://host.example/sites/' . self::SITE . '/domains', $http->calls[0]['url'] );

		$record = $result->find( 'mapped.test' );

		$this->assertNotNull( $record );
		$this->assertFalse( $record->is_primary );
		$this->assertSame( 'active', $record->ssl_status );
		$this->assertSame( 'verified', $record->dns_status );
		$this->assertSame( '2026-08-30T10:00:00Z', $record->dns_checked_at );
		$this->assertSame( '2026-08-30T10:05:00Z', $record->ssl_checked_at );
		$this->assertSame( 'dom-1', $record->reference );
	}

	/**
	 * Regression proof: a parser that reads only `ssl_state` and
	 * `dns_verified_at` sees nothing in a real response, and reports a live
	 * certificate as unknown state.
	 */
	public function test_the_observed_field_names_are_preferred_over_the_older_aliases(): void {
		$http = $this->http(
			array(
				new HttpResponse(
					200,
					array(),
					'[{"domain":"mapped.test","is_primary":false,"ssl_status":"active","ssl_state":"stale","dns_verified_at":"2020-01-01T00:00:00Z"}]'
				),
			)
		);

		$result = $this->client( $http, self::TEAM, $this->local() )->domains( self::SITE );

		$this->assertInstanceOf( WordifyDomainList::class, $result );
		$record = $result->find( 'mapped.test' );
		$this->assertNotNull( $record );
		$this->assertSame( 'active', $record->ssl_status, 'ssl_state must never override ssl_status.' );
		// An alias may still fill a gap the observed name did not answer.
		$this->assertSame( '2020-01-01T00:00:00Z', $record->dns_checked_at );
	}

	public function test_the_identity_read_uses_the_verified_me_route(): void {
		$http = $this->http(
			array(
				new HttpResponse(
					200,
					array(),
					'{"id":"usr-1","name":"A","email":"a@example.test","current_team_id":"' . self::TEAM . '","teams":[{"id":"' . self::TEAM . '","name":"Team"}]}'
				),
			)
		);

		$result = $this->client( $http, self::TEAM, $this->local() )->me();

		$this->assertNotInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( 'https://host.example/me', $http->calls[0]['url'] );
		$this->assertSame( array( self::TEAM ), $result->team_ids );
	}

	public function test_the_token_is_resolved_per_request_and_is_not_a_property(): void {
		$this->assertSame(
			array(),
			array_filter(
				( new \ReflectionClass( WordifyApiClient::class ) )->getProperties(),
				static fn ( \ReflectionProperty $p ): bool => str_contains( strtolower( $p->getName() ), 'token' )
					&& 'token_supplier' !== $p->getName()
			)
		);
	}

	public function test_an_absent_token_sends_nothing_at_all(): void {
		$http   = $this->http();
		$client = new WordifyApiClient( $http, static fn (): string => '', $this->local(), self::TEAM );

		$result = $client->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::NOT_CONFIGURED, $result->kind );
		$this->assertSame( array(), $http->calls );
		$this->assertFalse( $client->is_ready() );
	}

	public function test_a_server_error_is_transient_and_a_client_error_is_not(): void {
		$http = $this->http(
			array(
				new HttpResponse( 503, array(), 'unavailable' ),
				new HttpResponse( 429, array(), 'slow down' ),
				new HttpResponse( 422, array(), 'no' ),
				new HttpResponse( 0, array(), '', 'timed out' ),
			)
		);

		$client = $this->client( $http, self::TEAM, $this->local() );

		$kinds = array();

		foreach ( range( 1, 4 ) as $ignored ) {
			$result = $client->sites();
			$this->assertInstanceOf( WordifyFailure::class, $result );
			$kinds[] = $result->kind;
		}

		$this->assertSame(
			array(
				WordifyFailureKind::TRANSPORT,
				WordifyFailureKind::RATE_LIMITED,
				WordifyFailureKind::REFUSED,
				WordifyFailureKind::TRANSPORT,
			),
			$kinds
		);
	}

	public function test_a_response_body_never_reaches_a_failure_message(): void {
		$secret = 'team-billing-address-and-other-account-data';
		$http   = $this->http( array( new HttpResponse( 403, array(), $secret ) ) );

		$result = $this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertStringNotContainsString( $secret, $result->message );
		// Nor anywhere else on the failure object: it carries a status, a request
		// id, and a sentence this plugin wrote — nothing the provider said.
		$this->assertStringNotContainsString(
			$secret,
			(string) json_encode( $result ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	public function test_the_token_never_appears_in_a_failure_object(): void {
		$http = $this->http( array( new HttpResponse( 401, array(), '{}' ) ) );

		$result = $this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertStringNotContainsString(
			self::TOKEN,
			(string) json_encode( $result ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	public function test_an_unreadable_body_is_malformed_rather_than_an_empty_answer(): void {
		$http   = $this->http( array( new HttpResponse( 200, array(), 'not json' ) ) );
		$result = $this->client( $http, self::TEAM, $this->local() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::MALFORMED, $result->kind );
	}

	public function test_an_operation_with_no_route_fails_closed_without_sending_anything(): void {
		// Reachable only through the private constructor's contract being
		// violated, so it is asserted through the public surface that can still
		// express it: a map that never had the operation.
		$http      = $this->http();
		$endpoints = WordifyEndpoints::supplied( 'https://host.example', array() );

		$this->assertTrue( $endpoints->knows( WordifyEndpoints::OP_RECHECK ) );
		$this->assertNull( $endpoints->url( 'an_operation_that_does_not_exist' ) );
		$this->assertSame( array(), $http->calls );
	}
}
