<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Contracts\HttpClient;
use PostDomain\Support\HttpResponse;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DohResolver;

/**
 * A hard outcome requires two *independent* endpoints to agree (spec §13.2).
 * Unanimity among one answer is not agreement.
 */
final class DohEndpointQuorumTest extends TestCase {

	/**
	 * @param string[]                 $endpoints
	 * @param array<int, HttpResponse> $responses
	 */
	private function resolver( array $endpoints, array $responses ): DohResolver {
		$client = new class( $responses ) implements HttpClient {
			/** @param array<int, HttpResponse> $responses */
			public function __construct( private array $responses ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};

		return new DohResolver( $client, $endpoints );
	}

	private function nxdomain(): HttpResponse {
		return new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode( array( 'Status' => 3 ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	private function match_response(): HttpResponse {
		return new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'Status' => 0,
					'Answer' => array(
						array(
							'name' => '_x.example',
							'type' => 16,
							'TTL'  => 300,
							'data' => '"post-domain-verify=abc"',
						),
					),
				)
			)
		);
	}

	public function test_zero_endpoints_cannot_produce_a_hard_outcome(): void {
		$result = $this->resolver( array(), array( $this->nxdomain() ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
		$this->assertStringContainsString( 'two distinct https endpoints', (string) $result->error );
	}

	public function test_one_endpoint_cannot_produce_a_hard_outcome(): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$result->outcome,
			'one answer is trivially unanimous and is never proof'
		);
	}

	public function test_two_identical_endpoints_are_one_endpoint(): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query', 'https://one.example/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function trivially_equivalent_spellings(): array {
		return array(
			'surrounding whitespace' => array( '  https://one.example/dns-query  ' ),
			'scheme case'            => array( 'HTTPS://one.example/dns-query' ),
			'host case'              => array( 'https://ONE.Example/dns-query' ),
			'default port'           => array( 'https://one.example:443/dns-query' ),
			'trailing slash'         => array( 'https://one.example/dns-query/' ),
		);
	}

	/**
	 * @dataProvider trivially_equivalent_spellings
	 */
	public function test_a_trivially_equivalent_duplicate_is_not_a_second_endpoint( string $spelling ): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query', $spelling ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_a_non_default_port_is_a_genuinely_different_endpoint(): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query', 'https://one.example:8443/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NXDOMAIN, $result->outcome );
	}

	public function test_an_invalid_endpoint_beside_a_valid_one_is_transient(): void {
		$result = $this->resolver(
			array( 'http://insecure.example/dns-query', 'https://two.example/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$result->outcome,
			'a non-https endpoint is disqualified, leaving one usable endpoint'
		);
	}

	public function test_a_transport_failure_beside_a_hard_answer_is_transient(): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query', 'https://two.example/dns-query' ),
			array( new HttpResponse( 0, array(), '', 'timeout' ), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_two_distinct_endpoints_agreeing_produce_a_hard_outcome(): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query', 'https://two.example/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NXDOMAIN, $result->outcome );
	}

	public function test_two_distinct_endpoints_disagreeing_are_transient(): void {
		$result = $this->resolver(
			array( 'https://one.example/dns-query', 'https://two.example/dns-query' ),
			array( $this->match_response(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
		$this->assertSame( 'endpoints disagreed', $result->error );
	}
}
