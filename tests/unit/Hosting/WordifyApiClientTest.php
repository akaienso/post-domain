<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Contracts\HttpClient;
use PostDomain\Hosting\WordifyApiClient;
use PostDomain\Hosting\WordifyEndpoints;
use PostDomain\Hosting\WordifyFailure;
use PostDomain\Hosting\WordifyFailureKind;
use PostDomain\Hosting\WordifySiteList;
use PostDomain\Support\HttpResponse;

/**
 * The client's contract with reality: it sends nothing it cannot justify, and
 * it never lets a response body out.
 */
final class WordifyApiClientTest extends TestCase {

	/** A token that is not, and never was, a credential. */
	private const TOKEN = 'test-token-not-a-credential';

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

	private function client( HttpClient $http, WordifyEndpoints $endpoints ): WordifyApiClient {
		return new WordifyApiClient( $http, static fn (): string => self::TOKEN, $endpoints );
	}

	private function supplied(): WordifyEndpoints {
		// What an operator who holds the specification would fill in. The plugin
		// ships none of this.
		return WordifyEndpoints::supplied(
			'https://host.example',
			array(
				WordifyEndpoints::OP_SITES   => WordifyEndpoints::VERIFIED_SITES_PATH,
				WordifyEndpoints::OP_DOMAINS => '/api/v1/sites/{site_id}/domains',
			),
			'X-Test-Authorization'
		);
	}

	public function test_the_shipped_map_fills_in_only_the_one_verified_path(): void {
		$endpoints = WordifyEndpoints::verified();

		$this->assertTrue( $endpoints->knows( WordifyEndpoints::OP_SITES ) );
		$this->assertFalse( $endpoints->knows( WordifyEndpoints::OP_DOMAINS ) );
		$this->assertFalse( $endpoints->knows( WordifyEndpoints::OP_ATTACH_DOMAIN ) );
		$this->assertFalse( $endpoints->knows( WordifyEndpoints::OP_RECHECK ) );
		$this->assertNull( $endpoints->auth_header() );
	}

	public function test_an_unverified_endpoint_fails_closed_without_sending_anything(): void {
		$http   = $this->http();
		$result = $this->client( $http, WordifyEndpoints::supplied( 'https://host.example', array(), 'X-Test-Authorization' ) )
			->domains( 'site-1' );

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::ENDPOINT_UNVERIFIED, $result->kind );
		$this->assertSame( array(), $http->calls );
	}

	public function test_an_unverified_auth_header_fails_closed_even_on_the_verified_path(): void {
		$http   = $this->http();
		$result = $this->client( $http, WordifyEndpoints::verified() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::AUTH_UNVERIFIED, $result->kind );
		$this->assertSame( array(), $http->calls, 'No credential is sent under a header name nobody verified.' );
	}

	public function test_the_verified_sites_read_is_sent_and_parsed(): void {
		$http = $this->http(
			array(
				new HttpResponse(
					200,
					array(),
					(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
						array(
							'data' => array(
								array(
									'id'                  => '01HQ0000000000000000000001',
									'provisioning_status' => 'active',
								),
							),
						)
					)
				),
			)
		);

		$result = $this->client( $http, $this->supplied() )->sites( array( 'domain' => 'mapped.test' ) );

		$this->assertInstanceOf( WordifySiteList::class, $result );
		$this->assertSame( '01HQ0000000000000000000001', (string) $result->first()?->id );
		$this->assertSame( 'GET', $http->calls[0]['method'] );
		$this->assertSame( 'https://host.example/api/v1/sites?domain=mapped.test', $http->calls[0]['url'] );

		/** @var array<string, string> $headers */
		$headers = $http->calls[0]['opts']['headers'];
		$this->assertSame( self::TOKEN, $headers['X-Test-Authorization'] );
		$this->assertLessThanOrEqual( 10, (int) $http->calls[0]['opts']['timeout'] );
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

	public function test_a_server_error_is_transient_and_a_client_error_is_not(): void {
		$http = $this->http(
			array(
				new HttpResponse( 503, array(), 'unavailable' ),
				new HttpResponse( 429, array(), 'slow down' ),
				new HttpResponse( 422, array(), 'no' ),
				new HttpResponse( 0, array(), '', 'timed out' ),
			)
		);

		$client = $this->client( $http, $this->supplied() );

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
		$http   = $this->http( array( new HttpResponse( 422, array(), $secret ) ) );

		$result = $this->client( $http, $this->supplied() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertStringNotContainsString( $secret, $result->message );
		// Nor anywhere else on the failure object: it carries a status and a
		// sentence this plugin wrote, and nothing the provider said.
		$this->assertStringNotContainsString(
			$secret,
			(string) json_encode( $result ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	public function test_an_unreadable_body_is_malformed_rather_than_an_empty_answer(): void {
		$http   = $this->http( array( new HttpResponse( 200, array(), 'not json' ) ) );
		$result = $this->client( $http, $this->supplied() )->sites();

		$this->assertInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( WordifyFailureKind::MALFORMED, $result->kind );
	}

	public function test_a_domains_read_substitutes_the_site_into_the_supplied_path(): void {
		$http = $this->http( array( new HttpResponse( 200, array(), '{"domains":[{"domain":"mapped.test","is_primary":false,"ssl_state":"active","dns_verified_at":null,"id":"dom-1"}]}' ) ) );

		$result = $this->client( $http, $this->supplied() )->domains( '01HQ0000000000000000000001' );

		$this->assertNotInstanceOf( WordifyFailure::class, $result );
		$this->assertSame( 'https://host.example/api/v1/sites/01HQ0000000000000000000001/domains', $http->calls[0]['url'] );
		$this->assertNotNull( $result->find( 'mapped.test' ) );
		$this->assertFalse( $result->find( 'mapped.test' )->is_primary );
	}
}
