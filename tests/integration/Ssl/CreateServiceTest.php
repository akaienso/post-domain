<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class CreateServiceTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
	}

	private function proof( DnsOutcome $outcome ): FreshProof {
		return new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);
	}

	private function mapping(): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				// An unprovisioned mapping has NO provider: the durable binding is
				// one fact in five columns, and the driver comes from the
				// configured selection until a create or adoption binds it.
				null,
				null,
				null,
				null,
				null
			)
		);
	}

	private function assert_released( int $id ): void {
		$this->assertNull( $this->repo->by_id( $id )?->ssl_mutation_token );
	}

	public function test_a_successful_create_binds_the_reference_and_records_provenance(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( SslState::REQUESTED, $after?->ssl_state );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertSame( OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( Environment::installation_id(), $after?->ssl_owner_installation_id );
		$this->assertSame( 'recording', $after?->ssl_provider );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_environment_unresolved_refuses_without_calling_the_provider(): void {
		update_option(
			'pd_environment_mismatch',
			array(
				'stored'  => 'a',
				'current' => 'b',
			),
			false
		);

		$driver = RecordingDriver::succeeding( 'ref-1' );
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assert_released( $m->id );
	}

	public function test_a_failed_fresh_proof_refuses_and_releases(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::NO_RECORD ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'fresh_proof_failed', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->create_calls, 'cached verification is not a fresh proof' );
		$this->assert_released( $m->id );
	}

	public function test_a_transient_proof_refuses_transiently(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::TRANSIENT ) )
			->provision( $this->mapping() );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertTrue( $result->refusal?->transient );
		$this->assertSame( 0, $driver->create_calls );
	}

	public function test_an_incomplete_identity_read_refuses(): void {
		$driver = RecordingDriver::with_incomplete_identity();
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assert_released( $m->id );
	}

	public function test_a_conflicting_marker_refuses(): void {
		$driver = RecordingDriver::with_foreign_marker();
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'conflicting_marker', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->create_calls );
	}

	public function test_a_second_concurrent_provision_sends_no_second_post(): void {
		$driver  = RecordingDriver::succeeding( 'ref-1' );
		$service = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) );
		$m       = $this->mapping();

		$service->provision( $m );
		$second = $service->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $second->disposition );
		$this->assertSame( 1, $driver->create_calls, 'exactly one POST' );
	}

	public function test_an_ambiguous_create_with_a_matching_marker_binds_without_adoption(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_marked( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'ref-9', $after?->ssl_ref );
		$this->assertSame(
			OwnershipOrigin::CREATED,
			$after?->ssl_ownership_origin,
			'a recovered create is created, not adopted'
		);
		$this->assertNull( $after?->ssl_adopted_at );
	}

	public function test_an_ambiguous_create_without_markers_requires_adoption(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( SslState::FAILED, $after?->ssl_state );
		$this->assertNull( $after?->ssl_ref, 'nothing is bound without an explicit adoption' );
		$this->assertStringContainsString( 'provider_create_ambiguous', (string) $after?->ssl_error );
	}

	public function test_a_foreign_marker_refuses_before_anything_is_sent(): void {
		// A driver that reports a foreign marker reports it on the PRE-FLIGHT
		// identity read too, so the create is refused by the precondition set and
		// never reaches the ambiguous-create path at all. That is the stronger
		// behaviour: nothing is sent rather than sent and then disowned.
		//
		// The §14.6 unowned-resource branch is a RECOVERY decision — a marker that
		// appears only after a create times out — and is covered by
		// CreateRecoveryTest::test_case_f_a_foreign_marker_is_unowned().
		$driver = RecordingDriver::ambiguous_then_foreign( 'ref-9' );
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'conflicting_marker', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assertNull( $after?->ssl_ref );
		$this->assertNull( $after?->ssl_mutation_token, 'the reservation is released' );
	}

	public function test_a_conclusive_absence_leaves_the_mapping_retryable(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_absent(), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_mutation_token, 'the lease is released so a retry can acquire' );
		$this->assertNull( $after?->ssl_ref );
	}

	public function test_the_post_is_never_repeated_after_an_ambiguous_outcome(): void {
		$driver = RecordingDriver::ambiguous_then_absent();

		CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $this->mapping() );

		$this->assertSame( 1, $driver->create_calls );
		$this->assertGreaterThanOrEqual( 2, $driver->identify_calls, 'a read precedes any retry' );
	}

	public function test_a_successful_create_reports_a_committed_result(): void {
		$result = CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $this->mapping() );

		$this->assertSame( MutationDisposition::COMMITTED, $result->disposition );
		$this->assertNotNull( $result->status );
	}

	public function test_an_ambiguous_create_is_reported_as_retained_not_successful(): void {
		$result = CreateService::for_tests( RecordingDriver::ambiguous_then_absent(), $this->proof( DnsOutcome::MATCH ) )
			->provision( $this->mapping() );

		$this->assertSame( MutationDisposition::AMBIGUOUS_RETAINED, $result->disposition );
		$this->assertNull( $result->status, 'nothing here is confirmed enough to report as a status' );
	}

	public function test_a_refusal_carries_no_status(): void {
		$result = CreateService::for_tests(
			RecordingDriver::succeeding( 'ref-1' ),
			$this->proof( DnsOutcome::NO_RECORD )
		)->provision( $this->mapping() );

		$this->assertNull( $result->status );
		$this->assertFalse( $result->succeeded() );
	}

	public function test_a_successful_create_records_where_the_resource_lives(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame(
			'recording:default',
			$after?->ssl_provider_environment,
			'promoted from the lease, in the same CAS as the reference'
		);
		$this->assertNull( $after?->ssl_mutation_environment, 'the mutation binding is over' );
	}

	public function test_a_recovered_create_promotes_the_lease_environment(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_marked( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$this->assertSame( 'recording:default', $this->repo->by_id( $m->id )?->ssl_provider_environment );
	}

	public function test_a_fenced_worker_writes_nothing(): void {
		global $wpdb;

		$m      = $this->mapping();
		$driver = RecordingDriver::succeeding( 'ref-1' );

		// Fence the worker mid-flight by replacing the lease token as the driver runs.
		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array( 'ssl_mutation_token' => str_repeat( '7', 32 ) ),
					array( 'id' => $m->id )
				);
			}
		);

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_ref, 'a fenced worker must not apply its result' );
		$this->assertNull( $after?->ssl_ownership_origin );

		// The provider succeeded. The mutation did not. Those are different facts
		// and the caller has to be able to tell them apart.
		$this->assertSame( MutationDisposition::FENCED, $result->disposition );
		$this->assertNull( $result->status );
		$this->assertFalse( $result->succeeded() );

		remove_all_actions( 'pd_test_after_provider_call' );
		unset( $wpdb );
	}

	public function test_a_fenced_worker_records_no_success_event(): void {
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

		CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'created' === $e['to_state']
			),
			'no history for work that was discarded'
		);

		remove_all_actions( 'pd_test_after_provider_call' );
	}
}
