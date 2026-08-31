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

	/** @var object{calls: array<int, array{method: string, url: string, opts: array<string, mixed>}>} */
	private $http;

	public function set_up(): void {
		parent::set_up();

		$this->http = new class() implements HttpClient {
			/** @var array<int, array{method: string, url: string, opts: array<string, mixed>}> */
			public array $calls = array();

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				$this->calls[] = array(
					'method' => $method,
					'url'    => $url,
					'opts'   => $opts,
				);

				return new HttpResponse(
					201,
					array(),
					(string) wp_json_encode(
						array(
							'data' => array(
								'id'              => 'dom-1',
								'domain'          => 'mapped.test',
								'is_primary'      => false,
								'ssl_state'       => 'pending',
								'dns_verified_at' => null,
							),
						)
					)
				);
			}
		};
	}

	/** The transport an operator holding the specification would have supplied. */
	private function endpoints(): WordifyEndpoints {
		return WordifyEndpoints::supplied(
			'https://host.example',
			array(
				WordifyEndpoints::OP_SITES         => WordifyEndpoints::VERIFIED_SITES_PATH,
				WordifyEndpoints::OP_DOMAINS       => '/api/v1/sites/{site_id}/domains',
				WordifyEndpoints::OP_ATTACH_DOMAIN => '/api/v1/sites/{site_id}/domains',
			),
			'X-Test-Authorization'
		);
	}

	private function provider(): WordifyHostingProvider {
		$client = new WordifyApiClient(
			$this->http,
			static fn (): string => 'test-token-not-a-credential',
			$this->endpoints()
		);

		return new WordifyHostingProvider(
			$client,
			new HostingEnvironment( 'wordify', 'team-1', self::SITE )
		);
	}

	/** @return array<string, mixed> */
	private function body_of( int $call ): array {
		/** @var array<string, mixed> $decoded */
		$decoded = json_decode( (string) $this->http->calls[ $call ]['opts']['body'], true );

		return $decoded;
	}

	public function test_an_attach_never_asks_for_primary_promotion(): void {
		$outcome = $this->provider()->register(
			new HostingResourceContext( 4, 'mapped.test', 'install-a', null, null )
		);

		$this->assertTrue( $outcome->succeeded() );
		$this->assertCount( 1, $this->http->calls, 'Exactly one request: the write, and no read after a clean answer.' );

		$body = $this->body_of( 0 );

		$this->assertArrayHasKey( 'make_primary', $body );
		$this->assertFalse( $body['make_primary'] );
		$this->assertSame( 'mapped.test', $body['domain'] );
		$this->assertSame( self::SITE, $body['site_id'] );
	}

	public function test_the_request_carries_the_supplied_auth_header_and_a_short_timeout(): void {
		$this->provider()->register( new HostingResourceContext( 4, 'mapped.test', 'install-a', null, null ) );

		$call = $this->http->calls[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( 'https://host.example/api/v1/sites/' . self::SITE . '/domains', $call['url'] );

		/** @var array<string, string> $headers */
		$headers = $call['opts']['headers'];

		$this->assertArrayHasKey( 'X-Test-Authorization', $headers );
		$this->assertArrayNotHasKey( 'Authorization', $headers, 'No invented header name is ever sent.' );
		$this->assertLessThanOrEqual( 10, (int) $call['opts']['timeout'] );
		$this->assertSame( 0, (int) $call['opts']['redirection'] );
	}

	public function test_use_www_is_never_sent_because_it_only_matters_with_primary_promotion(): void {
		$this->provider()->register( new HostingResourceContext( 4, 'mapped.test', 'install-a', null, null ) );

		$this->assertArrayNotHasKey( 'use_www', $this->body_of( 0 ) );
	}
}
