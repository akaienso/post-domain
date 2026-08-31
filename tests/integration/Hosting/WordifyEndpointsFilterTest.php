<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Contracts\HttpClient;
use PostDomain\Hosting\WordifyApiClient;
use PostDomain\Hosting\WordifyEndpoints;
use PostDomain\Hosting\WordifyFailure;
use PostDomain\Hosting\WordifyFailureKind;
use PostDomain\Support\HttpResponse;
use WP_UnitTestCase;

/**
 * The seam itself: what ships filled in, what does not, and how an operator who
 * holds the specification supplies the rest.
 */
final class WordifyEndpointsFilterTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pd_wordify_endpoints' );
		parent::tear_down();
	}

	/** An HTTP client that fails the test if it is ever asked to send anything. */
	private function forbidden_http(): HttpClient {
		return new class() implements HttpClient {
			public int $calls = 0;

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				++$this->calls;

				return new HttpResponse( 200, array(), '{}' );
			}
		};
	}

	public function test_the_shipped_configuration_knows_only_the_verified_path_and_no_auth_header(): void {
		$endpoints = WordifyEndpoints::configured();

		$this->assertSame(
			WordifyEndpoints::OBSERVED_BASE . WordifyEndpoints::VERIFIED_SITES_PATH,
			$endpoints->url( WordifyEndpoints::OP_SITES )
		);
		$this->assertNull( $endpoints->url( WordifyEndpoints::OP_DOMAINS ) );
		$this->assertNull( $endpoints->url( WordifyEndpoints::OP_ATTACH_DOMAIN ) );
		$this->assertNull( $endpoints->url( WordifyEndpoints::OP_RECHECK ) );
		$this->assertNull( $endpoints->auth_header() );
	}

	public function test_out_of_the_box_the_client_sends_nothing_at_all(): void {
		$http   = $this->forbidden_http();
		$client = new WordifyApiClient( $http, static fn (): string => 'test-token-not-a-credential', WordifyEndpoints::configured() );

		$this->assertFalse( $client->is_ready() );

		foreach ( array( $client->sites(), $client->domains( 'site-1' ), $client->attach_domain( 'site-1', 'x.test' ), $client->recheck( 'site-1' ) ) as $result ) {
			$this->assertInstanceOf( WordifyFailure::class, $result );
			$this->assertContains(
				$result->kind,
				array( WordifyFailureKind::AUTH_UNVERIFIED, WordifyFailureKind::ENDPOINT_UNVERIFIED )
			);
		}

		$this->assertSame( 0, $http->calls );
	}

	public function test_an_operator_with_the_specification_supplies_the_rest_through_the_filter(): void {
		add_filter(
			'pd_wordify_endpoints',
			static function ( array $config ): array {
				$config['base']                                        = 'https://host.example';
				$config['auth_header']                                 = 'X-Test-Authorization';
				$config['paths'][ WordifyEndpoints::OP_DOMAINS ]       = '/api/v1/sites/{site_id}/domains';
				$config['paths'][ WordifyEndpoints::OP_ATTACH_DOMAIN ] = '/api/v1/sites/{site_id}/domains';

				return $config;
			}
		);

		$endpoints = WordifyEndpoints::configured();

		$this->assertSame( 'X-Test-Authorization', $endpoints->auth_header() );
		$this->assertSame(
			'https://host.example/api/v1/sites/site%2F1/domains',
			$endpoints->url( WordifyEndpoints::OP_DOMAINS, array( 'site_id' => 'site/1' ) ),
			'Tokens are URL-encoded, so a site identifier cannot escape its path segment.'
		);
		$this->assertNull( $endpoints->url( WordifyEndpoints::OP_RECHECK ), 'What the operator did not supply stays unverified.' );
	}

	public function test_the_one_verified_path_cannot_be_unset_by_a_filter(): void {
		add_filter(
			'pd_wordify_endpoints',
			static function ( array $config ): array {
				$config['paths'] = array();

				return $config;
			}
		);

		$this->assertSame(
			WordifyEndpoints::OBSERVED_BASE . WordifyEndpoints::VERIFIED_SITES_PATH,
			WordifyEndpoints::configured()->url( WordifyEndpoints::OP_SITES )
		);
	}

	public function test_a_malformed_filter_return_is_discarded_rather_than_trusted(): void {
		add_filter( 'pd_wordify_endpoints', static fn (): string => 'nonsense' );

		$endpoints = WordifyEndpoints::configured();

		$this->assertNull( $endpoints->auth_header() );
		$this->assertSame(
			WordifyEndpoints::OBSERVED_BASE . WordifyEndpoints::VERIFIED_SITES_PATH,
			$endpoints->url( WordifyEndpoints::OP_SITES )
		);
	}
}
