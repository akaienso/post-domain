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
	 * Every URL the resolver actually requested, in order, for the most recent
	 * resolver built by resolver(). Several requirements here are about requests
	 * that must *not* be sent, which the returned outcome alone cannot show.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	protected function setUp(): void {
		parent::setUp();

		$this->requested = array();
	}

	/**
	 * @param string[]                 $endpoints
	 * @param array<int, HttpResponse> $responses
	 */
	private function resolver( array $endpoints, array $responses ): DohResolver {
		$record = function ( string $url ): void {
			$this->requested[] = $url;
		};

		$client = new class( $responses, $record ) implements HttpClient {
			/**
			 * @param array<int, HttpResponse> $responses
			 * @param callable(string): void   $record
			 */
			public function __construct( private array $responses, private $record ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				( $this->record )( $url );

				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};

		return new DohResolver( $client, $endpoints );
	}

	/**
	 * The hosts requested, in order, so a test can assert who was actually asked.
	 *
	 * @return string[]
	 */
	private function requested_hosts(): array {
		return array_map(
			static function ( string $url ): string {
				return (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			},
			$this->requested
		);
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

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function one_authority_spelled_twice(): array {
		return array(
			'different paths'          => array(
				'https://dns.google/resolve',
				'https://dns.google/dns-query',
			),
			'different query strings'  => array(
				'https://one.example/dns-query?ct=application/dns-json',
				'https://one.example/dns-query?cd=1',
			),
			'explicit vs implicit 443' => array(
				'https://one.example:443/dns-query',
				'https://one.example/resolve',
			),
			'differing host case'      => array(
				'https://ONE.Example/dns-query',
				'https://one.example/other',
			),
		);
	}

	/**
	 * Independence is a property of the authority — host plus effective port —
	 * not of the URL. Two doors into one resolver are one opinion.
	 *
	 * @dataProvider one_authority_spelled_twice
	 */
	public function test_two_endpoints_on_one_authority_are_one_endpoint( string $first, string $second ): void {
		$result = $this->resolver(
			array( $first, $second ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	/**
	 * A request that cannot contribute to quorum must never be sent, or a caller
	 * could manufacture the appearance of quorum by watching the side effects.
	 */
	public function test_an_unreachable_quorum_sends_no_request_at_all(): void {
		$result = $this->resolver(
			array( 'https://dns.google/resolve', 'https://dns.google/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
		$this->assertSame( array(), $this->requested, 'no endpoint may be asked when quorum is impossible' );
	}

	public function test_two_distinct_hosts_are_two_authorities(): void {
		$result = $this->resolver(
			array( 'https://one.example/resolve', 'https://two.example/dns-query' ),
			array( $this->nxdomain(), $this->nxdomain() )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NXDOMAIN, $result->outcome );
		$this->assertSame( array( 'one.example', 'two.example' ), $this->requested_hosts() );
	}

	/**
	 * One authority written three ways still votes once, and the vote it casts is
	 * the first spelling in configuration order. The second authority is genuinely
	 * attempted and genuinely fails, so the TRANSIENT here is a real failure to
	 * agree rather than a silently skipped endpoint.
	 */
	public function test_one_authority_many_spellings_plus_a_failing_second_is_transient(): void {
		$result = $this->resolver(
			array(
				'https://one.example/dns-query',
				'https://ONE.example:443/resolve',
				'  https://one.example/dns-query?cd=1  ',
				'https://two.example/dns-query',
			),
			array( $this->nxdomain(), new HttpResponse( 0, array(), '', 'timeout' ) )
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
		$this->assertSame(
			array( 'one.example', 'two.example' ),
			$this->requested_hosts(),
			'the second authority must actually be attempted'
		);
		$this->assertStringStartsWith(
			'https://one.example/dns-query?name=',
			$this->requested[0],
			'the first spelling of a repeated authority is the one that votes'
		);
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
