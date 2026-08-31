<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Contracts\HttpClient;
use PostDomain\Hosting\HostingEnvironment;
use PostDomain\Hosting\HostingResourceContext;
use PostDomain\Hosting\WordifyApiClient;
use PostDomain\Hosting\WordifyEndpoints;
use PostDomain\Hosting\WordifyHostingProvider;
use PostDomain\Support\HttpResponse;
use WP_UnitTestCase;

// See HostingProviderFactoryTest for why this file is loaded by hand.

/**
 * What actually goes on the wire when a hostname is attached.
 *
 * Runs in the integration suite because the request body is encoded with
 * `wp_json_encode()`, which only exists with WordPress loaded.
 */
final class WordifyAttachRequestTest extends WP_UnitTestCase {

	private const SITE = '01HQ0000000000000000000001';
	private const TEAM = '01HQTEAM0000000000000000AB';

	/** @var object{calls: array<int, array{method: string, url: string, opts: array<string, mixed>}>, responses: array<int, HttpResponse>} */
	private $http;

	public function set_up(): void {
		parent::set_up();

		$this->http = new class() implements HttpClient {
			/** @var array<int, array{method: string, url: string, opts: array<string, mixed>}> */
			public array $calls = array();

			/** @var array<int, HttpResponse> */
			public array $responses = array();

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				$this->calls[] = array(
					'method' => $method,
					'url'    => $url,
					'opts'   => $opts,
				);

				if ( array() !== $this->responses ) {
					return array_shift( $this->responses );
				}

				return new HttpResponse(
					201,
					array(),
					(string) wp_json_encode(
						array(
							'id'             => 'dom-1',
							'domain'         => 'mapped.test',
							'is_primary'     => false,
							'dns_status'     => 'pending',
							'ssl_status'     => 'pending',
							'dns_checked_at' => null,
							'ssl_checked_at' => null,
						)
					)
				);
			}
		};
	}

	/** The shipped transport, pointed at a host no test can reach. */
	private function endpoints(): WordifyEndpoints {
		return WordifyEndpoints::supplied( 'https://host.example', array() );
	}

	private function provider(): WordifyHostingProvider {
		$client = new WordifyApiClient(
			$this->http,
			static fn (): string => 'wpk_test-token-not-a-credential',
			$this->endpoints(),
			self::TEAM
		);

		return new WordifyHostingProvider(
			$client,
			new HostingEnvironment( 'wordify', self::TEAM, self::SITE )
		);
	}

	private function context(): HostingResourceContext {
		return new HostingResourceContext( 4, 'mapped.test', 'install-a', null, null );
	}

	/** @return array<string, mixed> */
	private function body_of( int $call ): array {
		/** @var array<string, mixed> $decoded */
		$decoded = json_decode( (string) $this->http->calls[ $call ]['opts']['body'], true );

		return $decoded;
	}

	public function test_an_attach_never_asks_for_primary_promotion(): void {
		$outcome = $this->provider()->register( $this->context() );

		$this->assertTrue( $outcome->succeeded() );
		$this->assertCount( 1, $this->http->calls, 'Exactly one request: the write, and no read after a clean answer.' );

		$body = $this->body_of( 0 );

		$this->assertArrayHasKey( 'make_primary', $body );
		$this->assertFalse( $body['make_primary'] );
		$this->assertSame( 'mapped.test', $body['domain'] );
	}

	public function test_the_body_carries_nothing_the_contract_does_not_name(): void {
		$this->provider()->register( $this->context() );

		$body = $this->body_of( 0 );

		$this->assertSame( array( 'domain', 'make_primary' ), array_keys( $body ) );
		// `use_www` is only meaningful alongside primary promotion, and
		// `site_id` is already in the path. Neither is invented here.
		$this->assertArrayNotHasKey( 'use_www', $body );
		$this->assertArrayNotHasKey( 'site_id', $body );
	}

	public function test_the_request_carries_bearer_authentication_and_the_bound_team(): void {
		$this->provider()->register( $this->context() );

		$call = $this->http->calls[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( 'https://host.example/sites/' . self::SITE . '/domains', $call['url'] );

		/** @var array<string, string> $headers */
		$headers = $call['opts']['headers'];

		$this->assertSame( 'Bearer wpk_test-token-not-a-credential', $headers['Authorization'] );
		$this->assertSame( self::TEAM, $headers[ WordifyEndpoints::TEAM_HEADER ] );
		$this->assertLessThanOrEqual( 10, (int) $call['opts']['timeout'] );
		$this->assertSame( 0, (int) $call['opts']['redirection'] );
	}

	/**
	 * A token with Read Sites but not Manage Sites gets through every check the
	 * connection test can run, and fails here. It must fail once, plainly, and
	 * without claiming anything happened.
	 */
	public function test_a_token_without_manage_sites_refuses_once_and_reads_nothing_further(): void {
		$this->http->responses = array(
			new HttpResponse( 403, array( 'X-Request-Id' => 'req_forbidden' ), '{"error":{"code":"forbidden"}}' ),
		);

		$outcome = $this->provider()->register( $this->context() );

		$this->assertFalse( $outcome->succeeded() );
		$this->assertCount( 1, $this->http->calls, 'No retry, and no follow-up read: the token is the problem.' );
		$this->assertStringContainsString( 'Manage Sites', (string) $outcome->message );
	}

	public function test_a_rejected_token_refuses_once_rather_than_writing_again(): void {
		$this->http->responses = array(
			new HttpResponse( 401, array(), '{"error":{"code":"unauthenticated"}}' ),
		);

		$outcome = $this->provider()->register( $this->context() );

		$this->assertFalse( $outcome->succeeded() );
		$this->assertCount( 1, $this->http->calls );
	}

	public function test_the_provider_message_is_never_the_providers_own_prose(): void {
		$this->http->responses = array(
			new HttpResponse( 403, array(), '{"error":{"message":"team-billing-address-and-other-account-data"}}' ),
		);

		$outcome = $this->provider()->register( $this->context() );

		$this->assertStringNotContainsString( 'team-billing-address', (string) $outcome->message );
	}

	public function test_a_transient_failure_is_still_settled_by_reading_not_by_writing(): void {
		$this->http->responses = array(
			new HttpResponse( 503, array(), '' ),
			new HttpResponse( 200, array(), '[{"id":"dom-1","domain":"mapped.test","is_primary":false}]' ),
		);

		$outcome = $this->provider()->register( $this->context() );

		$this->assertTrue( $outcome->succeeded() );
		$this->assertSame( 'POST', $this->http->calls[0]['method'] );
		$this->assertSame( 'GET', $this->http->calls[1]['method'], 'The second call is a read, never a second write.' );
		$this->assertCount( 2, $this->http->calls );
	}
}
