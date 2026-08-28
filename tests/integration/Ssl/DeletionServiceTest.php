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
use PostDomain\Ssl\DeletionService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\ForceLocalDelete;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class DeletionServiceTest extends OwnedSessionTestCase {

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

	private function owned(): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	private function force_lease( int $id, string $phase, int $offset ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '5', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => $phase,
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
			),
			array( 'id' => $id )
		);
	}

	public function test_requesting_deletion_stops_serving_under_a_cas(): void {
		$m = $this->owned();

		$this->assertTrue(
			DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) )
				->request( $m )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( ActivationState::INACTIVE, $after?->activation_state );
		$this->assertSame( SslState::PENDING_REMOVAL, $after?->ssl_state );
		$this->assertNotNull( $after?->deletion_requested_at );
	}

	public function test_requesting_deletion_on_a_stale_revision_fails(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		$this->assertFalse( $service->request( $m ), 'the second request carries a stale revision' );
	}

	public function test_the_local_row_survives_until_the_provider_confirms(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::PENDING ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$this->assertNotNull( $this->repo->by_id( $m->id ), 'not deleted until cleanup succeeds' );
	}

	public function test_a_confirmed_removal_deletes_the_row_and_keeps_the_event(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$this->assertSame( 'removed', $service->process( $this->repo->by_id( $m->id ) ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );

		$events = EventLog::for_domain( $m->id );

		$this->assertNotEmpty( $events );
		$this->assertSame( 'mapped.test', end( $events )['host'] );
	}

	public function test_a_transient_removal_does_not_increment_attempts(): void {
		global $wpdb;

		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::TRANSIENT ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$this->assertSame(
			0,
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from Schema, the id is placeheld.
				$wpdb->prepare( 'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d', $m->id )
			)
		);
	}

	public function test_a_failed_removal_increments_attempts_and_keeps_the_row(): void {
		global $wpdb;

		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::FAILED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$this->assertSame(
			1,
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from Schema, the id is placeheld.
				$wpdb->prepare( 'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d', $m->id )
			)
		);
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}

	public function test_an_unconfirmed_commit_never_reports_removed_even_when_the_row_looks_gone(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		// The delete runs and the transaction stays open, so THIS connection sees
		// its own uncommitted delete: the row looks gone and may still roll back.
		add_filter( 'query', $fail = static fn( string $q ): string => 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q );

		$outcome = $service->process( $this->repo->by_id( $m->id ) );
		$looks   = $this->repo->by_id( $m->id );

		remove_filter( 'query', $fail );

		$this->assertSame(
			'deferred',
			$outcome,
			'a same-connection view of an unresolved transaction is not proof of anything'
		);
		$this->assertNull( $looks, 'the row does appear gone from here — which is exactly the trap' );
	}

	public function test_a_rollback_after_an_unconfirmed_commit_restores_the_row(): void {
		global $wpdb;

		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		add_filter( 'query', $fail = static fn( string $q ): string => 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q );
		$outcome = $service->process( $this->repo->by_id( $m->id ) );
		remove_filter( 'query', $fail );

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 'deferred', $outcome );
		$this->assertNotNull(
			$this->repo->by_id( $m->id ),
			'reporting removed would have been a lie about a row that came back'
		);
	}

	public function test_a_commit_that_actually_succeeded_is_settled_by_a_later_pass(): void {
		global $wpdb;

		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		add_filter( 'query', $fail = static fn( string $q ): string => 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q );
		$outcome = $service->process( $this->repo->by_id( $m->id ) );
		remove_filter( 'query', $fail );

		// The server did apply it; a later request commits what is outstanding.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 'deferred', $outcome, 'still no success claimed at the time' );
		$this->assertNull( $this->repo->by_id( $m->id ), 'and the next pass simply finds nothing to do' );
	}

	public function test_an_unconfirmed_commit_never_re_issues_the_provider_deletion(): void {
		$m       = $this->owned();
		$driver  = RecordingDriver::removing( RemovalOutcome::REMOVED );
		$service = DeletionService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		add_filter( 'query', $fail = static fn( string $q ): string => 'COMMIT' === $q ? 'SELECT bad_syntax FROM' : $q );
		$service->process( $this->repo->by_id( $m->id ) );
		remove_filter( 'query', $fail );

		$this->assertSame( 1, $driver->remove_calls, 'the provider already confirmed; asking again proves nothing' );
	}

	public function test_a_deletion_against_a_drifted_environment_touches_nothing_at_all(): void {
		$driver  = RecordingDriver::removing( RemovalOutcome::REMOVED )->in_environment( 'recording:somewhere-else' );
		$m       = $this->owned();
		$service = DeletionService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		$before  = $this->repo->by_id( $m->id );
		$outcome = $service->process( $before );
		$after   = $this->repo->by_id( $m->id );

		$this->assertSame( 'refused', $outcome );

		// Refused before the lease, and therefore before every provider call.
		$this->assertSame( 0, $driver->identify_calls, 'no identity read against the wrong account' );
		$this->assertSame( 0, $driver->status_calls );
		$this->assertSame( 0, $driver->plan_calls );
		$this->assertSame( 0, $driver->remove_calls, 'zone B has no certificate of ours to delete' );
		$this->assertNull( $after?->ssl_mutation_token, 'no lease was ever acquired' );

		$this->assertNotNull( $after );
		$this->assertSame( $before->ssl_provider, $after?->ssl_provider );
		$this->assertSame( $before->ssl_provider_environment, $after?->ssl_provider_environment );
		$this->assertSame( $before->ssl_ref, $after?->ssl_ref );
		$this->assertSame( $before->ssl_ownership_origin, $after?->ssl_ownership_origin );
	}

	public function test_deletion_resumes_once_the_environment_is_restored(): void {
		$m       = $this->owned();
		$drifted = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::REMOVED )->in_environment( 'recording:somewhere-else' ),
			$this->proof( DnsOutcome::MATCH )
		);

		$drifted->request( $m );
		$drifted->process( $this->repo->by_id( $m->id ) );

		remove_all_filters( 'pd_ssl_drivers' );

		$restored = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::REMOVED ),
			$this->proof( DnsOutcome::MATCH )
		);

		$this->assertSame( 'removed', $restored->process( $this->repo->by_id( $m->id ) ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}

	public function test_a_fenced_worker_does_not_hard_delete(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					Schema::domains_table(),
					array( 'ssl_mutation_token' => str_repeat( '9', 32 ) ),
					array( 'id' => $m->id )
				);
			}
		);

		$outcome = $service->process( $this->repo->by_id( $m->id ) );

		$this->assertSame( 'fenced', $outcome );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'recovery owns this row now' );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'deleted' === $e['to_state']
			),
			'no deletion event for a deletion that did not happen'
		);

		remove_all_actions( 'pd_test_after_provider_call' );
	}

	public function test_a_confirmed_removal_records_its_event_only_with_the_delete(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$deleted = array_filter(
			EventLog::for_domain( $m->id ),
			static fn( array $e ): bool => 'deleted' === $e['to_state']
		);

		$this->assertCount( 1, $deleted );
		$this->assertSame( 'mapped.test', reset( $deleted )['host'], 'the host snapshot outlives the row' );
	}

	public function test_a_local_delete_that_loses_its_cas_records_nothing(): void {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0,
				'plain.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'e', 32 ),
				'_post-domain-challenge'
			)
		);

		$stale = $this->repo->by_id( $m->id );

		// Someone bumps the revision between our read and our acquire.
		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array( 'revision' => $stale->revision + 3 ),
			array( 'id' => $m->id )
		);

		$service = DeletionService::for_tests( new NullDriver(), $this->proof( DnsOutcome::MATCH ) );

		$this->assertFalse( $service->request( $stale ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
		$this->assertSame( array(), EventLog::for_domain( $m->id ) );
	}

	public function test_a_mapping_with_no_provider_resource_deletes_under_its_own_lease(): void {
		$m = $this->repo->save(
			new Mapping(
				0,
				'plain.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);

		$service = DeletionService::for_tests( new NullDriver(), $this->proof( DnsOutcome::MATCH ) );

		$this->assertTrue( $service->request( $m ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}

	/**
	 * @dataProvider lease_states
	 */
	public function test_a_local_delete_cannot_race_a_prepared_mutation( string $phase, int $offset ): void {
		$m = $this->repo->save(
			new Mapping(
				0,
				'plain.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'd', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->force_lease( $m->id, $phase, $offset );

		$service = DeletionService::for_tests( new NullDriver(), $this->proof( DnsOutcome::MATCH ) );

		$this->assertFalse( $service->request( $this->repo->by_id( $m->id ) ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}

	public function test_force_local_delete_removes_the_row_and_records_the_orphan(): void {
		$m = $this->owned();

		$this->assertTrue( ForceLocalDelete::run( $m ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );

		$events = EventLog::for_domain( $m->id );

		$this->assertStringContainsString( 'provider_resource_may_remain', (string) end( $events )['detail'] );
	}

	public function test_force_local_delete_issues_no_provider_deletion(): void {
		$driver = RecordingDriver::removing( RemovalOutcome::REMOVED );

		ForceLocalDelete::run( $this->owned() );

		$this->assertSame( 0, $driver->remove_calls );
	}

	/**
	 * @dataProvider lease_states
	 */
	public function test_force_local_delete_cannot_overwrite_any_lease( string $phase, int $offset ): void {
		$m = $this->owned();
		$this->force_lease( $m->id, $phase, $offset );

		$this->assertFalse( ForceLocalDelete::run( $this->repo->by_id( $m->id ) ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function lease_states(): array {
		return array(
			'reserved unexpired'   => array( 'reserved', 600 ),
			'reserved expired'     => array( 'reserved', -600 ),
			'in flight unexpired'  => array( 'in_flight', 600 ),
			'in flight expired'    => array( 'in_flight', -600 ),
			'recovering unexpired' => array( 'recovering', 600 ),
			'recovering expired'   => array( 'recovering', -600 ),
		);
	}
}
