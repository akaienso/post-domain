<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\HttpClient;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\CloudflareSaasDriver;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\HttpResponse;
use WP_UnitTestCase;

final class CloudflareSaasDriverTest extends WP_UnitTestCase {

	/** @var array<int, array{method: string, url: string, body: string}> */
	private array $sent = array();

	private function client( array $responses ): HttpClient {
		$sent = &$this->sent;

		return new class( $responses, $sent ) implements HttpClient {
			/** @param array<int, HttpResponse> $responses */
			public function __construct( private array $responses, private array &$sent ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				$this->sent[] = array(
					'method' => $method,
					'url'    => $url,
					'body'   => (string) ( $opts['body'] ?? '' ),
				);

				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};
	}

	private function ok( array $result ): HttpResponse {
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

	private function error( int $code, int $status = 400 ): HttpResponse {
		return new HttpResponse(
			$status,
			array( 'content-type' => 'application/json' ),
			(string) wp_json_encode(
				array(
					'success' => false,
					'result'  => null,
					'errors'  => array(
						array(
							'code'    => $code,
							'message' => 'e',
						),
					),
				)
			)
		);
	}

	private function context( ?string $ref = null ): SslResourceContext {
		return new SslResourceContext(
			12,
			'mapped.test',
			'install-a',
			'cloudflare-saas',
			null === $ref ? null : 'cf-zone:zone-1',
			$ref,
			null,
			null,
			'_post-domain-challenge.mapped.test',
			'post-domain-verify=abc',
			'abc',
			3,
			str_repeat( '1', 32 ),
			'txt'
		);
	}

	/**
	 * The permit constructor is private: only MutationGate may issue one. A test
	 * that needs to call a driver directly reflects one into being rather than
	 * loosening that rule.
	 */
	private function permit( MutationOperation $operation ): ExecutionPermit {
		$reflection  = new \ReflectionClass( ExecutionPermit::class );
		$permit      = $reflection->newInstanceWithoutConstructor();
		$constructor = $reflection->getConstructor();
		$constructor?->invoke(
			$permit,
			$operation,
			12,
			4,
			str_repeat( '1', 32 ),
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);

		return $permit;
	}

	private function driver( array $responses ): CloudflareSaasDriver {
		return new CloudflareSaasDriver( $this->client( $responses ), 'token', 'zone-1', 'saas.example.net' );
	}

	public function test_create_posts_the_hostname_and_the_configured_method(): void {
		$driver = $this->driver(
			array(
				$this->ok(
					array(
						'id'       => 'ref-1',
						'hostname' => 'mapped.test',
						'status'   => 'pending',
					)
				),
			)
		);

		$status = $driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertSame( 'ref-1', $status->ref );
		$this->assertStringContainsString( '"method":"txt"', $this->sent[0]['body'] );
		$this->assertStringContainsString( '"type":"dv"', $this->sent[0]['body'] );
	}

	public function test_create_never_requests_a_wildcard(): void {
		$driver = $this->driver(
			array(
				$this->ok(
					array(
						'id'       => 'ref-1',
						'hostname' => 'mapped.test',
						'status'   => 'pending',
					)
				),
			)
		);

		$driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertStringNotContainsString( '"wildcard":true', $this->sent[0]['body'] );
	}

	public function test_error_1413_retries_once_without_custom_metadata(): void {
		$driver = $this->driver(
			array(
				$this->error( 1413 ),
				$this->ok(
					array(
						'id'       => 'ref-1',
						'hostname' => 'mapped.test',
						'status'   => 'pending',
					)
				),
			)
		);

		$status = $driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertCount( 2, $this->sent );
		$this->assertStringContainsString( 'custom_metadata', $this->sent[0]['body'] );
		$this->assertStringNotContainsString( 'custom_metadata', $this->sent[1]['body'] );
		$this->assertSame( 'ref-1', $status->ref );
		$this->assertSame( MarkerSupport::UNAVAILABLE, $driver->marker_support() );
	}

	public function test_error_1413_is_not_transient(): void {
		$driver = $this->driver( array( $this->error( 1413 ), $this->error( 1413 ) ) );

		$status = $driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertFalse( $status->transient, '1413 reports a definitive rejection' );
		$this->assertCount( 2, $this->sent, 'exactly one retry, never a loop' );
	}

	public function test_a_timeout_grants_no_retry(): void {
		$driver = $this->driver( array( new HttpResponse( 0, array(), '', 'timeout' ) ) );

		$status = $driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertTrue( $status->transient );
		$this->assertCount( 1, $this->sent, 'an ambiguous failure is resolved by reading, not repeating' );
	}

	public function test_a_5xx_grants_no_retry(): void {
		$driver = $this->driver( array( new HttpResponse( 503, array( 'content-type' => 'application/json' ), '{}' ) ) );

		$driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertCount( 1, $this->sent );
	}

	public function test_a_duplicate_record_error_routes_to_identify(): void {
		$driver = $this->driver(
			array(
				$this->error( 1406 ),
				$this->ok(
					array(
						array(
							'id'       => 'ref-9',
							'hostname' => 'mapped.test',
							'status'   => 'pending',
						),
					)
				),
			)
		);

		$status = $driver->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertSame( 'GET', $this->sent[1]['method'] );
		$this->assertNull( $status->ref, 'a duplicate is never bound without identification' );
	}

	public function test_identify_reports_the_exact_hostname_and_reference(): void {
		$driver = $this->driver(
			array(
				$this->ok(
					array(
						'id'       => 'ref-1',
						'hostname' => 'mapped.test',
						'status'   => 'active',
					)
				),
			)
		);

		$identity = $driver->identify( $this->context( 'ref-1' ) );

		$this->assertSame( 'ref-1', $identity->observed_ref );
		$this->assertSame( 'mapped.test', $identity->observed_hostname );
		$this->assertTrue( $identity->read_complete );
	}

	public function test_status_combines_both_axes(): void {
		$driver = $this->driver(
			array(
				$this->ok(
					array(
						'id'       => 'ref-1',
						'hostname' => 'mapped.test',
						'status'   => 'active',
						'ssl'      => array(
							'status' => 'active',
							'method' => 'txt',
						),
					)
				),
			)
		);

		$status = $driver->status( $this->context( 'ref-1' ) );

		$this->assertSame( SslState::ACTIVE, $status->state );
		$this->assertSame( 'txt', $status->confirmed_method );
	}

	public function test_a_404_on_delete_counts_as_removed(): void {
		$driver = $this->driver( array( new HttpResponse( 404, array( 'content-type' => 'application/json' ), '{}' ) ) );

		$result = $driver->remove( $this->context( 'ref-1' ), $this->permit( MutationOperation::REMOVE ) );

		$this->assertSame( \PostDomain\Ssl\RemovalOutcome::REMOVED, $result->outcome );
	}

	public function test_a_429_sets_a_cooldown_from_retry_after(): void {
		$driver = $this->driver(
			array(
				new HttpResponse(
					429,
					array(
						'retry-after'  => '90',
						'content-type' => 'application/json',
					),
					'{}'
				),
			)
		);

		$result = $driver->remove( $this->context( 'ref-1' ), $this->permit( MutationOperation::REMOVE ) );

		$this->assertSame( \PostDomain\Ssl\RemovalOutcome::TRANSIENT, $result->outcome );
		$this->assertSame( 90, $result->retry_after );
		$this->assertTrue( \PostDomain\Ssl\Cooldown::active_for( 'cloudflare-saas' ) );
	}

	public function test_credentials_never_appear_in_a_status(): void {
		$driver = $this->driver( array( $this->error( 1000, 403 ) ) );

		$status = $driver->status( $this->context( 'ref-1' ) );

		$this->assertStringNotContainsString( 'token', (string) $status->message );
	}
}
