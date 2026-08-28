<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MethodChangeService;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class MethodChangeTest extends OwnedSessionTestCase {

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

	private function mapping( bool $owned = true ): Mapping {
		global $wpdb;

		$m = $this->repo->save(
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
				// The durable binding moves as one: an unowned mapping has none of
				// the five columns, an owned one has all five.
				$owned ? OwnershipOrigin::CREATED : null,
				$owned ? Environment::installation_id() : null,
				$owned ? 'recording' : null,
				$owned ? 'recording:default' : null,
				$owned ? 'ref-1' : null
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_method' => 'txt' ),
			array( 'id' => $m->id )
		);

		return $this->repo->by_id( $m->id );
	}

	public function test_a_confirmed_change_is_persisted(): void {
		$m = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'http' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $m, 'http' );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'http', $after?->ssl_method );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_an_unconfirmed_change_leaves_the_local_method_alone(): void {
		$m = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'txt' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $m, 'http' );

		$this->assertSame(
			'txt',
			$this->repo->by_id( $m->id )?->ssl_method,
			'local state follows the provider, not the request'
		);
	}

	public function test_a_method_change_against_a_drifted_environment_touches_nothing_at_all(): void {
		$driver = RecordingDriver::confirming_method( 'http' )->in_environment( 'recording:somewhere-else' );
		$m      = $this->mapping();
		$before = $this->repo->by_id( $m->id );

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->change( $m, 'http' );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'provider_environment_changed', $result->refusal?->precondition );

		// Refused before the lease, and therefore before every provider call —
		// not merely before the mutating one.
		$this->assertSame( 0, $driver->identify_calls, 'no identity read against the wrong account' );
		$this->assertSame( 0, $driver->status_calls, 'no status read either' );
		$this->assertSame( 0, $driver->plan_calls, 'and no validation planning' );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertNull( $after?->ssl_mutation_token, 'no lease was ever acquired' );
		$this->assertSame( $before->revision, $after?->revision, 'not even a revision bump' );

		// Every field of the durable binding, and the method, exactly as before.
		$this->assertSame( 'txt', $after?->ssl_method );
		$this->assertSame( $before->ssl_provider, $after?->ssl_provider );
		$this->assertSame( $before->ssl_provider_environment, $after?->ssl_provider_environment );
		$this->assertSame( $before->ssl_ref, $after?->ssl_ref );
		$this->assertSame( $before->ssl_state, $after?->ssl_state );
	}

	public function test_a_method_change_resumes_once_the_environment_is_restored(): void {
		$m = $this->mapping();

		MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' )->in_environment( 'recording:somewhere-else' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $m, 'http' );

		remove_all_filters( 'pd_ssl_drivers' );

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'http' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $this->repo->by_id( $m->id ), 'http' );

		$this->assertSame( 'http', $this->repo->by_id( $m->id )?->ssl_method );
	}

	public function test_an_unsupported_method_is_refused_without_calling_the_provider(): void {
		$driver = RecordingDriver::confirming_method( 'http' );
		$m      = $this->mapping();

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->change( $m, 'email' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'method_unsupported', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_an_invalid_method_is_refused(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'carrier-pigeon' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
	}

	public function test_a_failed_fresh_proof_refuses_and_releases(): void {
		$driver = RecordingDriver::confirming_method( 'http' );
		$m      = $this->mapping();

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::NO_RECORD ) )->change( $m, 'http' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_no_ownership_authority_refuses(): void {
		global $wpdb;

		// A row bound to a resource this installation does not own. An UNBOUND
		// row cannot reach the ownership precondition at all — it is refused
		// earlier at identity_not_confirmed — so it could never exercise this.
		$m = $this->mapping();

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_owner_installation_id' => 'another-installation' ),
			array( 'id' => $m->id )
		);

		$m      = $this->repo->by_id( $m->id );
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $m, 'http' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'no_ownership_authority', $result->refusal?->precondition );
	}

	public function test_a_confirmed_change_reports_a_committed_result(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'http' );

		$this->assertSame( MutationDisposition::COMMITTED, $result->disposition );
	}

	public function test_an_unconfirmed_change_is_reported_as_ambiguous(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'txt' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'http' );

		$this->assertSame(
			MutationDisposition::AMBIGUOUS_RETAINED,
			$result->disposition,
			'the provider did not do what was asked; that is not success'
		);
	}

	public function test_a_fenced_worker_does_not_persist_the_method(): void {
		$m = $this->mapping();

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

		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $m, 'http' );

		$this->assertSame( 'txt', $this->repo->by_id( $m->id )?->ssl_method );
		$this->assertSame( MutationDisposition::FENCED, $result->disposition );
		$this->assertNull( $result->status );

		remove_all_actions( 'pd_test_after_provider_call' );
	}
}
