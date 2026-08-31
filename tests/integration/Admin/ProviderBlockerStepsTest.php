<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Step;
use PostDomain\Admin\Workflow;
use PostDomain\Contracts\HttpClient;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Ssl\CloudflareSaasDriver;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Support\HttpResponse;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * Which workflow step a blocker belongs to, decided by the blocker itself.
 *
 * The workflow used to answer that question with `str_contains( $code, $purpose )
 * || str_contains( $message, $purpose )`. That is not a contract. Cloudflare's
 * malformed ownership record is `provider_record_malformed`, whose code and
 * message contain neither purpose string, so step 5 missed it and fell through
 * to DONE: the workflow reported the phase complete while the plan was saying
 * the record was malformed. The same hole swallowed malformed validation records
 * and every global read failure — and any translation of the messages would have
 * broken the rest.
 *
 * So these tests drive the *real* blockers `CloudflareValidationPlan` produces,
 * through the driver, from fake provider responses. A hand-written blocker whose
 * code happens to embed the purpose would prove nothing.
 */
final class ProviderBlockerStepsTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();
	}

	// -- the harness ---------------------------------------------------------

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

	private function context( string $host, ?string $ref ): SslResourceContext {
		return new SslResourceContext(
			41,
			$host,
			'install-a',
			'cloudflare-saas',
			null === $ref ? null : 'cf-zone:zone-1',
			$ref,
			null,
			null,
			'_post-domain-challenge.' . $host,
			'post-domain-verify=abc',
			'abc',
			2
		);
	}

	/** @param HttpResponse[] $responses */
	private function plan(
		array $responses,
		?string $ref = 'cf-resource-id',
		string $host = 'blocker.example.com',
		?ApexCapability $apex = null
	): ValidationPlan {
		return $this->driver( $responses )->validation_plan(
			$this->context( $host, $ref ),
			$apex ?? new ApexCapability( ApexRouting::CNAME_FLATTENING, 'flattening', array(), null, false )
		);
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

	/**
	 * A live, still-pending custom hostname.
	 *
	 * @return array<string, mixed>
	 */
	private function live_payload( string $host = 'blocker.example.com' ): array {
		return array(
			'id'       => 'cf-resource-id',
			'hostname' => $host,
			'status'   => 'pending',
			'ssl'      => array( 'status' => 'pending_validation' ),
		);
	}

	private function mapping( SslState $ssl = SslState::PENDING_VALIDATION ): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"blocker-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				$ssl,
				null,
				// `host` and `challenge` are both UNIQUE, so both vary per fixture.
				str_pad( (string) $this->seq, 32, 'b', STR_PAD_LEFT ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'cloudflare-saas',
				'cloudflare-saas:default',
				'cf-resource-id'
			)
		);
	}

	/** @return array<int, string> */
	private function statuses( Mapping $mapping, ?ValidationPlan $plan ): array {
		$out = array();

		foreach ( Workflow::steps( $mapping, $plan ) as $step ) {
			$out[ $step->number ] = $step->status;
		}

		return $out;
	}

	/** @return string[] */
	private function blocker_codes( ValidationPlan $plan ): array {
		return array_map( static fn( object $blocker ): string => (string) $blocker->code, $plan->blockers );
	}

	/** A domain with nothing left to do but the blocker under test. */
	private function finished_mapping(): Mapping {
		$mapping = $this->mapping( SslState::ACTIVE );

		Workflow::record_origin_confirmed( $mapping );

		return $mapping;
	}

	// -- a blocker that belongs to one phase ---------------------------------

	public function test_an_incomplete_hostname_ownership_record_stops_step_five(): void {
		$payload                           = $this->live_payload();
		$payload['ownership_verification'] = array(
			'type'  => 'txt',
			'name'  => '_cf-custom-hostname.blocker.example.com',
			'value' => '',
		);

		$plan = $this->plan( array( $this->ok( $payload ) ) );

		// The real blocker: its code and message name neither purpose.
		$this->assertSame( array( 'provider_record_malformed' ), $this->blocker_codes( $plan ) );
		$this->assertSame( 'provider_ownership', $plan->blockers[0]->purpose );
		$this->assertStringNotContainsString( 'provider_ownership', $plan->blockers[0]->code );
		$this->assertStringNotContainsString( 'provider_ownership', $plan->blockers[0]->message );

		$statuses = $this->statuses( $this->mapping(), $plan );

		$this->assertSame( Step::FAILED, $statuses[5], 'the malformed record belongs to this phase' );
		$this->assertNotSame( Step::DONE, $statuses[5], 'and a malformed record is never a completed phase' );
	}

	public function test_a_malformed_certificate_validation_record_stops_step_six(): void {
		$payload                              = $this->live_payload();
		$payload['status']                    = 'active';
		$payload['ssl']['validation_records'] = array( array( 'unrecognised' => 'shape' ) );

		$plan = $this->plan( array( $this->ok( $payload ) ) );

		$this->assertSame( array( 'provider_record_malformed' ), $this->blocker_codes( $plan ) );
		$this->assertSame( 'ssl_validation', $plan->blockers[0]->purpose );
		$this->assertStringNotContainsString( 'ssl_validation', $plan->blockers[0]->message );

		$statuses = $this->statuses( $this->mapping(), $plan );

		$this->assertSame( Step::FAILED, $statuses[6] );
		$this->assertSame( Step::DONE, $statuses[5], 'the provider reports hostname ownership active' );
	}

	// -- a failed read is not evidence of completion -------------------------

	/**
	 * @dataProvider global_read_failures
	 *
	 * @param HttpResponse[] $responses
	 */
	public function test_a_failed_provider_read_blocks_both_provider_phases( array $responses, string $code ): void {
		$plan = $this->plan( $responses );

		$this->assertContains( $code, $this->blocker_codes( $plan ) );
		$this->assertNull( $plan->blockers[0]->purpose, 'a failed read belongs to no single phase' );

		$statuses = $this->statuses( $this->mapping(), $plan );

		$this->assertSame( Step::BLOCKED, $statuses[5] );
		$this->assertSame( Step::BLOCKED, $statuses[6] );
	}

	/** @return array<string, array{0: HttpResponse[], 1: string}> */
	public function global_read_failures(): array {
		$body = static fn( int $status, array $headers = array() ): HttpResponse => new HttpResponse(
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

		$ok = static fn( mixed $result ): HttpResponse => new HttpResponse(
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

		return array(
			'rate limited'    => array( array( $body( 429, array( 'retry-after' => '45' ) ) ), 'provider_read_unavailable' ),
			'server error'    => array( array( $body( 503 ) ), 'provider_read_unavailable' ),
			'network failure' => array( array( new HttpResponse( 0, array(), '', 'cURL error 28: timeout' ) ), 'provider_read_unavailable' ),
			'malformed body'  => array( array( $ok( 'not-a-resource' ) ), 'provider_read_malformed' ),
			'missing bound'   => array( array( $body( 404 ) ), 'provider_resource_missing' ),
		);
	}

	public function test_a_blocked_provider_phase_carries_the_blocker_and_its_remedy(): void {
		$plan = $this->plan( array( $this->failure( 503 ) ) );

		foreach ( Workflow::steps( $this->mapping(), $plan ) as $step ) {
			if ( 5 !== $step->number ) {
				continue;
			}

			$this->assertSame( $plan->blockers[0]->message, $step->detail );
			$this->assertSame( $plan->blockers[0]->remedy, $step->because );
		}
	}

	// -- a blocker that belongs elsewhere ------------------------------------

	public function test_an_apex_routing_blocker_does_not_fail_either_certificate_phase(): void {
		$plan = $this->plan(
			array( $this->ok( array() ) ),
			null,
			'blocked-apex.com',
			ApexCapability::unsupported( 'no flattening, no ALIAS, no attested targets' )
		);

		$this->assertSame( array( 'apex_routing_unsupported' ), $this->blocker_codes( $plan ) );
		$this->assertSame( 'routing', $plan->blockers[0]->purpose );

		$statuses = $this->statuses( $this->finished_mapping(), $plan );

		foreach ( array( 5, 6 ) as $number ) {
			$this->assertNotSame( Step::FAILED, $statuses[ $number ] );
			$this->assertNotSame( Step::BLOCKED, $statuses[ $number ] );
		}
	}

	public function test_a_routing_blocker_still_stops_the_summary_claiming_completion(): void {
		$plan = $this->plan(
			array( $this->ok( array() ) ),
			null,
			'blocked-apex-two.com',
			ApexCapability::unsupported( 'no supported apex mechanism' )
		);

		$this->assertStringNotContainsString(
			'set up and tested',
			Workflow::summary( $this->finished_mapping(), $plan )
		);
	}

	public function test_a_global_blocker_stops_the_summary_claiming_completion(): void {
		$mapping = $this->finished_mapping();

		$this->assertStringContainsString(
			'set up and tested',
			Workflow::summary( $mapping, $this->plan( array( $this->ok( array() ) ), null ) ),
			'with a clean plan this domain really is finished'
		);

		$this->assertStringNotContainsString(
			'set up and tested',
			Workflow::summary( $mapping, $this->plan( array( $this->failure( 503 ) ) ) ),
			'a read that failed is not evidence the domain is finished'
		);
	}

	// -- and a genuinely finished phase still reads as finished --------------

	public function test_completed_ownership_followed_by_pending_validation(): void {
		$payload                              = $this->live_payload();
		$payload['status']                    = 'active';
		$payload['ssl']['validation_records'] = array(
			array(
				'txt_name'  => '_acme-challenge.blocker.example.com',
				'txt_value' => 'dcv-value',
			),
		);

		$plan = $this->plan( array( $this->ok( $payload ) ) );

		$this->assertSame( array(), $plan->blockers );

		$statuses = $this->statuses( $this->mapping(), $plan );

		$this->assertSame( Step::DONE, $statuses[5] );
		$this->assertSame( Step::CURRENT, $statuses[6] );
	}

	public function test_an_active_hostname_and_certificate_leave_both_phases_done(): void {
		$payload = array(
			'id'       => 'cf-resource-id',
			'hostname' => 'blocker.example.com',
			'status'   => 'active',
			'ssl'      => array( 'status' => 'active' ),
		);

		$statuses = $this->statuses(
			$this->mapping( SslState::ACTIVE ),
			$this->plan( array( $this->ok( $payload ) ) )
		);

		$this->assertSame( Step::DONE, $statuses[5] );
		$this->assertSame( Step::DONE, $statuses[6] );
	}
}
