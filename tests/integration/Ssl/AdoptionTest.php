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
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class AdoptionTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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
				SslState::FAILED,
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

	public function test_adoption_with_confirmation_and_proof_succeeds(): void {
		$m = $this->mapping();

		AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( OwnershipOrigin::ADOPTED, $after?->ssl_ownership_origin );
		$this->assertSame( Environment::installation_id(), $after?->ssl_owner_installation_id );
		$this->assertSame( 'ref-9', $after?->ssl_ref );
		$this->assertNotNull( $after?->ssl_adopted_at );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_adoption_without_confirmation_is_refused(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array() );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'confirmation_required', $result->refusal?->precondition );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_adoption_without_a_fresh_proof_is_refused(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MISMATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'fresh_proof_failed', $result->refusal?->precondition );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_a_foreign_marker_needs_the_second_key(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_foreign( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'foreign_marker_override_required', $result->refusal?->precondition );
	}

	public function test_a_foreign_marker_can_be_overridden_deliberately(): void {
		$m = $this->mapping();

		AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_foreign( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership(
			$m,
			array(
				'confirm'                 => true,
				'override_foreign_marker' => true,
			)
		);

		$this->assertSame( OwnershipOrigin::ADOPTED, $this->repo->by_id( $m->id )?->ssl_ownership_origin );
	}

	public function test_an_absent_provider_resource_cannot_be_adopted(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_absent(),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'identity_not_confirmed', $result->refusal?->precondition );
	}

	public function test_a_fenced_adoption_claims_nothing(): void {
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

		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( MutationDisposition::FENCED, $result->disposition );
		$this->assertNull( $after?->ssl_ownership_origin, 'ownership is exactly what must not survive a lost CAS' );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'adopted' === $e['to_state']
			)
		);

		remove_all_actions( 'pd_test_after_provider_call' );
	}

	public function test_a_successful_adoption_records_where_the_resource_lives(): void {
		$m = $this->mapping();

		AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( 'recording:default', $this->repo->by_id( $m->id )?->ssl_provider_environment );
	}

	public function test_provisioning_never_adopts(): void {
		$m = $this->mapping();

		CreateService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->provision( $m );

		$this->assertNull(
			$this->repo->by_id( $m->id )?->ssl_ownership_origin,
			'finding a duplicate never adopts it'
		);
	}
}
