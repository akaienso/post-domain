<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\HttpClient;
use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Ssl\CloudflareSaasDriver;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Support\HttpResponse;
use WP_UnitTestCase;

/**
 * The provider read that feeds validation planning is a typed outcome, not a
 * bare payload. An empty payload once meant "no resource exists", "the provider
 * did not answer", "the answer was unreadable" and "the resource we hold a
 * reference for is gone" all at once, and every one of those rendered as an
 * empty plan: no requirements, no wait, no blocker. Only the first may.
 */
final class CloudflareValidationReadTest extends WP_UnitTestCase {

	private const HOST = 'ssl-read.example.com';

	/** @param HttpResponse[] $responses */
	private function driver( array $responses ): CloudflareSaasDriver {
		$client = new class( $responses ) implements HttpClient {
			/** @param HttpResponse[] $responses */
			public function __construct( private array $responses ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};

		return new CloudflareSaasDriver( $client, 'token', 'zone-1', 'saas.example.net' );
	}

	private function context( ?string $ref ): SslResourceContext {
		return new SslResourceContext(
			41,
			self::HOST,
			'install-a',
			'cloudflare-saas',
			null === $ref ? null : 'cf-zone:zone-1',
			$ref,
			null,
			null,
			'_post-domain-challenge.' . self::HOST,
			'post-domain-verify=abc',
			'abc',
			2
		);
	}

	private function apex(): ApexCapability {
		return new ApexCapability( ApexRouting::CNAME_FLATTENING, 'flattening', array(), null, false );
	}

	/** @param HttpResponse[] $responses */
	private function plan( array $responses, ?string $ref = null ): ValidationPlan {
		return $this->driver( $responses )->validation_plan( $this->context( $ref ), $this->apex() );
	}

	/** @return string[] */
	private function blocker_codes( ValidationPlan $plan ): array {
		return array_map( static fn( object $blocker ): string => (string) $blocker->code, $plan->blockers );
	}

	/** @return string[] */
	private function pending_purposes( ValidationPlan $plan ): array {
		return array_map( static fn( object $pending ): string => (string) $pending->purpose, $plan->pending );
	}

	private function ok( mixed $result ): HttpResponse {
		return new HttpResponse(
			200,
			array( 'content-type' => 'application/json' ),
			(string) wp_json_encode(
				array(
					'success' => true,
					'result'  => $result,
					'errors'  => array(),
				)
			)
		);
	}

	/** @param array<string, string> $headers */
	private function failure( int $status, array $headers = array() ): HttpResponse {
		return new HttpResponse(
			$status,
			$headers,
			(string) wp_json_encode(
				array(
					'success' => false,
					'result'  => null,
					'errors'  => array( array( 'code' => 1436 ) ),
				)
			)
		);
	}

	/** @return array<string, mixed> */
	private function live_payload(): array {
		return array(
			'id'       => 'cf-resource-id',
			'hostname' => self::HOST,
			'status'   => 'pending',
			'ssl'      => array( 'status' => 'pending_validation' ),
		);
	}

	public function test_an_absent_unbound_resource_is_the_only_silent_outcome(): void {
		$plan = $this->plan( array( $this->ok( array() ) ) );

		$this->assertSame( array(), $plan->pending );
		$this->assertSame( array(), $plan->blockers );
		$this->assertArrayHasKey( 'routing', $plan->dns );
	}

	public function test_a_confirmed_removal_leaves_no_false_provider_wait(): void {
		// The row is unbound after a confirmed removal and the provider answers
		// 404. Nothing is outstanding, and nothing may claim otherwise.
		$plan = $this->plan( array( $this->failure( 404 ) ) );

		$this->assertSame( array(), $plan->pending );
		$this->assertSame( array(), $plan->blockers );
	}

	public function test_a_404_against_a_persisted_reference_is_a_visible_anomaly(): void {
		$plan = $this->plan( array( $this->failure( 404 ) ), 'cf-resource-id' );

		$this->assertContains( 'provider_resource_missing', $this->blocker_codes( $plan ) );
	}

	public function test_a_rate_limited_read_is_visible_and_carries_the_retry_hint(): void {
		$plan = $this->plan( array( $this->failure( 429, array( 'retry-after' => '45' ) ) ), 'cf-resource-id' );

		$this->assertContains( 'provider_read_unavailable', $this->blocker_codes( $plan ) );
		$this->assertContains( 'provider_read', $this->pending_purposes( $plan ) );
		$this->assertSame( 45, $plan->pending[0]->retry_after );
	}

	public function test_a_server_error_read_is_visible(): void {
		$plan = $this->plan( array( $this->failure( 503 ) ), 'cf-resource-id' );

		$this->assertContains( 'provider_read_unavailable', $this->blocker_codes( $plan ) );
	}

	public function test_a_network_failure_is_visible(): void {
		$plan = $this->plan( array( new HttpResponse( 0, array(), '', 'cURL error 28: timeout' ) ), 'cf-resource-id' );

		$this->assertContains( 'provider_read_unavailable', $this->blocker_codes( $plan ) );
	}

	public function test_a_malformed_but_successful_body_is_visible(): void {
		$plan = $this->plan( array( $this->ok( 'not-a-resource' ) ), 'cf-resource-id' );

		$this->assertContains( 'provider_read_malformed', $this->blocker_codes( $plan ) );
	}

	public function test_a_definitive_provider_rejection_is_visible(): void {
		$plan = $this->plan( array( $this->failure( 403 ) ), 'cf-resource-id' );

		$this->assertContains( 'provider_read_malformed', $this->blocker_codes( $plan ) );
	}

	public function test_a_present_resource_without_records_is_a_legitimate_wait(): void {
		$plan = $this->plan( array( $this->ok( $this->live_payload() ) ), 'cf-resource-id' );

		$this->assertSame( array( 'provider_ownership', 'ssl_validation' ), $this->pending_purposes( $plan ) );
		$this->assertSame( array(), $plan->blockers );
	}

	public function test_a_present_resource_still_yields_its_issued_records(): void {
		$payload                              = $this->live_payload();
		$payload['ssl']['validation_records'] = array(
			array(
				'txt_name'  => '_acme-challenge.' . self::HOST,
				'txt_value' => 'dcv-value',
			),
		);

		$plan = $this->plan( array( $this->ok( $payload ) ), 'cf-resource-id' );

		$this->assertSame( 'cf-dcv-txt', $plan->dns['ssl_validation'][0]->id );
		$this->assertSame( array(), $plan->blockers );
	}
}
