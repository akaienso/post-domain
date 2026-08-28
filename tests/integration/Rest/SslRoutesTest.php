<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\AdoptionAuthorizer;
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\CreateAuthorizer;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\DeletionService;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MethodChangeAuthorizer;
use PostDomain\Ssl\MethodChangeService;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Verification\FreshProof;
use WP_REST_Request;

/**
 * Every mutation here finalizes through AtomicTransition, which refuses to run
 * inside the harness's ambient transaction.
 */
final class SslRoutesTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A fresh server per test, so no test inherits another's routes.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( 'pd_settings' );
		DriverFactory::reset();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_actions( 'pd_test_after_provider_call' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function boot( RecordingDriver $driver, DnsOutcome $outcome = DnsOutcome::MATCH ): void {
		$this->driver = $driver;

		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$gate  = new MutationGate( $lease, $clock );

		// Installed the way a site would install it, through the one factory.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		$proof = new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);

		$services = new SslServices(
			new CreateService( $this->repo, new CreateAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate ),
			new AdoptionService( $this->repo, new AdoptionAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate ),
			new MethodChangeService( $this->repo, new MethodChangeAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate ),
			new DeletionService( new DeletionAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate, $clock )
		);

		( new ManagementController( $this->repo, $services ) )->register();
	}

	private function mapping( bool $owned = false ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				$owned ? SslState::ACTIVE : SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				$owned ? OwnershipOrigin::CREATED : null,
				$owned ? Environment::installation_id() : null,
				// A mapping that has never provisioned has no provider at all,
				// which is exactly the case the selection has to answer.
				$owned ? 'recording' : null,
				// The binding is five columns that move as one, and the environment
				// must be the one the driver is actually configured for: an
				// ordinary read of a bound row proves that before it speaks.
				$owned ? $this->driver->environment_id() : null,
				$owned ? 'ref-1' : null,
				$owned ? 'txt' : null
			)
		);
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $suffix, Mapping $m, array $body = array(), bool $etag = true ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/post-domain/v1/domains/' . $m->id . $suffix );
		$request->set_body_params( $body );

		if ( $etag ) {
			$request->set_header( 'if_match', Guard::etag( $this->repo->by_id( $m->id ) ) );
		}

		return rest_do_request( $request );
	}

	public function test_the_plan_is_read_only_and_needs_no_precondition(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping( true );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/' . $m->id . '/plan' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'dns', $response->get_data() );
		$this->assertArrayHasKey( 'blockers', $response->get_data() );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_provisioning_without_a_precondition_is_428(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		$this->assertSame( 428, $this->request( 'POST', '/ssl', $m, array(), false )->get_status() );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_provisioning_succeeds_and_reports_the_new_state(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 1, $this->driver->create_calls );
		$this->assertSame( 'ref-1', $this->repo->by_id( $m->id )?->ssl_ref );
	}

	public function test_a_refused_provision_is_reported_with_its_precondition(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ), DnsOutcome::NO_RECORD );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::MUTATION_UNAUTHORIZED, $response->get_data()['code'] );
		$this->assertSame( 'fresh_proof_failed', $response->get_data()['data']['precondition'] );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_a_transient_refusal_is_503_with_no_provider_call(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ), DnsOutcome::TRANSIENT );
		$m = $this->mapping();

		$this->assertSame( 503, $this->request( 'POST', '/ssl', $m )->get_status() );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_changing_the_method_requires_a_supported_method(): void {
		$this->boot( RecordingDriver::confirming_method( 'http' ) );
		$m = $this->mapping( true );

		$response = $this->request( 'PATCH', '/ssl', $m, array( 'method' => 'email' ) );

		$this->assertSame( 400, $response->get_status(), 'spec 15.3: pd_method_unsupported is a 400' );
		$this->assertSame( Errors::METHOD_UNSUPPORTED, $response->get_data()['code'] );
		$this->assertSame( 0, $this->driver->method_calls );
	}

	public function test_a_confirmed_method_change_is_persisted(): void {
		$this->boot( RecordingDriver::confirming_method( 'http' ) );
		$m = $this->mapping( true );

		$this->assertSame( 200, $this->request( 'PATCH', '/ssl', $m, array( 'method' => 'http' ) )->get_status() );
		$this->assertSame( 'http', $this->repo->by_id( $m->id )?->ssl_method );
	}

	public function test_adoption_requires_confirmation(): void {
		$this->boot( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl/adopt', $m );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::CONFIRMATION_REQUIRED, $response->get_data()['code'] );
	}

	public function test_a_confirmed_adoption_records_adopted_provenance(): void {
		$this->boot( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) );
		$m = $this->mapping();

		$this->assertSame( 200, $this->request( 'POST', '/ssl/adopt', $m, array( 'confirm' => true ) )->get_status() );
		$this->assertSame( OwnershipOrigin::ADOPTED, $this->repo->by_id( $m->id )?->ssl_ownership_origin );
	}

	public function test_an_unestablished_removal_never_answers_204(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::REMOVED ) );
		$m = $this->mapping( true );

		// The provider confirms removal; the local COMMIT is what cannot be
		// established. A 204 here would be a success nobody can stand behind.
		add_filter( 'query', $fail = static fn( string $q ): string => 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q );
		$response = $this->request( 'DELETE', '/ssl', $m );
		remove_filter( 'query', $fail );

		$this->assertNotSame( 204, $response->get_status(), 'no success we cannot stand behind' );
		$this->assertSame( Errors::FINALIZATION_FAILED, $response->get_data()['code'] );
	}

	public function test_removing_ssl_returns_202_and_keeps_the_row(): void {
		// PENDING, not REMOVED: a confirmed removal hard-deletes the row by
		// spec 14.15, so the case where the mapping outlives its certificate is
		// precisely the one the provider has not finished yet.
		$this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m = $this->mapping( true );

		$this->assertSame( 202, $this->request( 'DELETE', '/ssl', $m )->get_status() );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'the mapping outlives its certificate' );
	}

	public function test_a_fenced_provision_is_never_reported_as_success(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'ssl_mutation_token' => str_repeat( '7', 32 ),
						'ssl_mutation_phase' => 'recovering',
					),
					array( 'id' => $m->id )
				);
			}
		);

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::FENCED, $response->get_data()['code'] );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_ref );
	}

	public function test_a_fenced_adoption_is_never_reported_as_success(): void {
		$this->boot( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) );
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'ssl_mutation_token' => str_repeat( '7', 32 ),
						'ssl_mutation_phase' => 'recovering',
					),
					array( 'id' => $m->id )
				);
			}
		);

		$response = $this->request( 'POST', '/ssl/adopt', $m, array( 'confirm' => true ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::FENCED, $response->get_data()['code'] );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_ownership_origin );
	}

	public function test_an_ambiguous_create_answers_202_rather_than_200(): void {
		$this->boot( RecordingDriver::ambiguous_then_absent() );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 202, $response->get_status(), 'the truth is still with the provider' );
	}

	public function test_provisioning_without_a_configured_driver_says_so(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		delete_option( 'pd_settings' );
		DriverFactory::reset();

		$response = $this->request( 'POST', '/ssl', $this->mapping() );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::SSL_NOT_CONFIGURED, $response->get_data()['code'] );
		$this->assertSame( 0, $this->driver->create_calls, 'a NullDriver no-op would have looked like success' );
	}

	public function test_the_plan_reports_a_missing_driver_as_configuration(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();
		delete_option( 'pd_settings' );
		DriverFactory::reset();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/' . $m->id . '/plan' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::SSL_NOT_CONFIGURED, $response->get_data()['code'] );
	}

	public function test_no_response_carries_a_lease_token_or_a_credential(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		$encoded = (string) wp_json_encode( $this->request( 'POST', '/ssl', $m )->get_data() );

		foreach ( array( 'api_token', 'ssl_mutation_token', 'lease_token', 'permit' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded );
		}
	}
}
