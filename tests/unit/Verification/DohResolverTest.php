<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Contracts\HttpClient;
use PostDomain\Support\HttpResponse;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DohResolver;

final class DohResolverTest extends TestCase {

	/**
	 * @param array<int, HttpResponse> $responses
	 */
	private function resolver( array $responses ): DohResolver {
		$client = new class( $responses ) implements HttpClient {
			/** @param array<int, HttpResponse> $responses */
			public function __construct( private array $responses ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};

		return new DohResolver( $client, array( 'https://one.example/dns-query', 'https://two.example/dns-query' ) );
	}

	private function json( int $status, array $answers ): HttpResponse {
		return new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode( array( 'Status' => $status, 'Answer' => $answers ) )
		);
	}

	private function txt( string $value ): array {
		return array( array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"' . $value . '"' ) );
	}

	public function test_both_endpoints_agreeing_on_the_value_is_a_match(): void {
		$result = $this->resolver(
			array(
				$this->json( 0, $this->txt( 'post-domain-verify=abc' ) ),
				$this->json( 0, $this->txt( 'post-domain-verify=abc' ) ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::MATCH, $result->outcome );
	}

	public function test_both_endpoints_agreeing_on_a_different_value_is_a_mismatch(): void {
		$result = $this->resolver(
			array(
				$this->json( 0, $this->txt( 'post-domain-verify=zzz' ) ),
				$this->json( 0, $this->txt( 'post-domain-verify=zzz' ) ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::MISMATCH, $result->outcome );
	}

	public function test_both_endpoints_agreeing_on_nxdomain(): void {
		$result = $this->resolver( array( $this->json( 3, array() ), $this->json( 3, array() ) ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NXDOMAIN, $result->outcome );
	}

	public function test_noerror_with_no_txt_is_no_record_not_nxdomain(): void {
		$result = $this->resolver( array( $this->json( 0, array() ), $this->json( 0, array() ) ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NO_RECORD, $result->outcome );
	}

	public function test_disagreement_between_endpoints_is_transient(): void {
		$result = $this->resolver(
			array(
				$this->json( 0, $this->txt( 'post-domain-verify=abc' ) ),
				$this->json( 3, array() ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$result->outcome,
			'a hard outcome requires agreement'
		);
	}

	public function test_servfail_is_transient(): void {
		$result = $this->resolver( array( $this->json( 2, array() ), $this->json( 2, array() ) ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_a_transport_error_is_transient(): void {
		$result = $this->resolver(
			array(
				new HttpResponse( 0, array(), '', 'timeout' ),
				new HttpResponse( 0, array(), '', 'timeout' ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_a_non_200_is_transient(): void {
		$result = $this->resolver(
			array(
				new HttpResponse( 502, array( 'content-type' => 'application/dns-json' ), '{}' ),
				new HttpResponse( 502, array( 'content-type' => 'application/dns-json' ), '{}' ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_malformed_json_is_transient(): void {
		$broken = new HttpResponse( 200, array( 'content-type' => 'application/dns-json' ), 'not json' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$this->resolver( array( $broken, $broken ) )->txt( '_x.example', 'x' )->outcome
		);
	}

	public function test_a_wrong_shape_is_transient(): void {
		$wrong = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode( array( 'Status' => 'zero', 'Answer' => 'nope' ) )
		);

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$this->resolver( array( $wrong, $wrong ) )->txt( '_x.example', 'x' )->outcome
		);
	}

	public function test_a_non_json_content_type_is_transient(): void {
		$html = new HttpResponse( 200, array( 'content-type' => 'text/html' ), '<html></html>' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$this->resolver( array( $html, $html ) )->txt( '_x.example', 'x' )->outcome
		);
	}

	public function test_non_txt_answer_types_are_ignored(): void {
		$mixed = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode(
				array(
					'Status' => 0,
					'Answer' => array(
						array( 'name' => '_x.example', 'type' => 5, 'TTL' => 300, 'data' => 'cname.example.' ),
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"post-domain-verify=abc"' ),
					),
				)
			)
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$this->resolver( array( $mixed, $mixed ) )->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_all_txt_values_are_examined(): void {
		$many = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode(
				array(
					'Status' => 0,
					'Answer' => array(
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"v=spf1 -all"' ),
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"post-domain-verify=abc"' ),
					),
				)
			)
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$this->resolver( array( $many, $many ) )->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_multi_string_txt_values_are_concatenated(): void {
		$split = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) json_encode(
				array(
					'Status' => 0,
					'Answer' => array(
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"post-domain-" "verify=abc"' ),
					),
				)
			)
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$this->resolver( array( $split, $split ) )->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}
}
