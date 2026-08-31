<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Contracts\HttpClient;
use PostDomain\Hosting\WordifyApiClient;
use PostDomain\Hosting\WordifyEndpoints;
use PostDomain\Support\HttpResponse;
use WP_UnitTestCase;

/**
 * The seam: what ships filled in, and how narrow the filter over it is.
 *
 * The whole verified transport ships. The filter exists so a test can point the
 * client at a fake base and so a future API version can move a path — it can
 * never remove a route and it has no say over authentication.
 */
final class WordifyEndpointsFilterTest extends WP_UnitTestCase {

	private const SITE = '01HQ0000000000000000000001';

	public function tear_down(): void {
		remove_all_filters( 'pd_wordify_endpoints' );
		parent::tear_down();
	}

	/** @return HttpClient&object{calls: int, last: array<string, mixed>} */
	private function recording_http(): HttpClient {
		return new class() implements HttpClient {
			public int $calls = 0;

			/** @var array<string, mixed> */
			public array $last = array();

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				++$this->calls;
				$this->last = array(
					'method' => $method,
					'url'    => $url,
					'opts'   => $opts,
				);

				return new HttpResponse( 200, array(), '[]' );
			}
		};
	}

	public function test_the_shipped_configuration_is_the_whole_verified_transport(): void {
		$endpoints = WordifyEndpoints::configured();

		$this->assertSame( WordifyEndpoints::BASE, $endpoints->base() );
		$this->assertSame( WordifyEndpoints::BASE . '/me', $endpoints->url( WordifyEndpoints::OP_ME ) );
		$this->assertSame( WordifyEndpoints::BASE . '/sites', $endpoints->url( WordifyEndpoints::OP_SITES ) );
		$this->assertSame(
			WordifyEndpoints::BASE . '/sites/' . self::SITE . '/domains',
			$endpoints->url( WordifyEndpoints::OP_ATTACH_DOMAIN, array( 'site_id' => self::SITE ) )
		);
		$this->assertSame(
			WordifyEndpoints::BASE . '/sites/' . self::SITE . '/domains/recheck',
			$endpoints->url( WordifyEndpoints::OP_RECHECK, array( 'site_id' => self::SITE ) )
		);
	}

	public function test_out_of_the_box_the_client_works_with_only_a_token(): void {
		$http   = $this->recording_http();
		$client = new WordifyApiClient(
			$http,
			static fn (): string => 'wpk_test-token-not-a-credential',
			WordifyEndpoints::supplied( 'https://host.example', array() ),
			'01HQTEAM0000000000000000AB'
		);

		$this->assertTrue( $client->is_ready(), 'No filter, no constant, no extra PHP: a token is enough.' );

		$client->domains( self::SITE );

		$this->assertSame( 1, $http->calls );
		$this->assertSame( 'https://host.example/sites/' . self::SITE . '/domains', $http->last['url'] );
	}

	public function test_a_filter_may_move_a_path_and_the_base(): void {
		add_filter(
			'pd_wordify_endpoints',
			static function ( array $config ): array {
				$config['base']                                  = 'https://host.example/api/v2';
				$config['paths'][ WordifyEndpoints::OP_DOMAINS ] = '/sites/{site_id}/hostnames';

				return $config;
			}
		);

		$endpoints = WordifyEndpoints::configured();

		$this->assertSame(
			'https://host.example/api/v2/sites/' . self::SITE . '/hostnames',
			$endpoints->url( WordifyEndpoints::OP_DOMAINS, array( 'site_id' => self::SITE ) )
		);
		$this->assertSame(
			'https://host.example/api/v2/sites/site%2F1/hostnames',
			$endpoints->url( WordifyEndpoints::OP_DOMAINS, array( 'site_id' => 'site/1' ) ),
			'Tokens are URL-encoded, so a site identifier cannot escape its path segment.'
		);
	}

	public function test_no_route_can_be_removed_by_a_filter(): void {
		add_filter(
			'pd_wordify_endpoints',
			static function ( array $config ): array {
				$config['paths'] = array();

				return $config;
			}
		);

		$endpoints = WordifyEndpoints::configured();

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
			$this->assertTrue( $endpoints->knows( $operation ), $operation . ' survives a filter that emptied the map.' );
		}
	}

	/**
	 * A filter that could rename the Authorization header could redirect a
	 * bearer credential to somewhere it was never meant to go. It cannot.
	 */
	public function test_a_filter_has_no_say_over_authentication(): void {
		add_filter(
			'pd_wordify_endpoints',
			static function ( array $config ): array {
				$config['base']        = 'https://host.example';
				$config['auth_header'] = 'X-Attacker-Header';

				return $config;
			}
		);

		$http   = $this->recording_http();
		$client = new WordifyApiClient(
			$http,
			static fn (): string => 'wpk_test-token-not-a-credential',
			WordifyEndpoints::configured(),
			null
		);

		$client->sites();

		/** @var array<string, string> $headers */
		$headers = $http->last['opts']['headers'];

		$this->assertArrayNotHasKey( 'X-Attacker-Header', $headers );
		$this->assertSame( 'Bearer wpk_test-token-not-a-credential', $headers['Authorization'] );
	}

	public function test_a_malformed_filter_return_is_discarded_rather_than_trusted(): void {
		add_filter( 'pd_wordify_endpoints', static fn (): string => 'nonsense' );

		$endpoints = WordifyEndpoints::configured();

		$this->assertSame( WordifyEndpoints::BASE, $endpoints->base() );
		$this->assertSame( WordifyEndpoints::BASE . '/sites', $endpoints->url( WordifyEndpoints::OP_SITES ) );
	}
}
