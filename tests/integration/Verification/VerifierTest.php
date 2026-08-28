<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Verification\Verifier;

final class VerifierTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new DbRepository();
	}

	private function stub( DnsOutcome $outcome ): DnsResolver {
		return new class( $outcome ) implements DnsResolver {
			public function __construct( private readonly DnsOutcome $outcome ) {}

			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult( $this->outcome );
			}
		};
	}

	private function seed( VerificationState $state, int $hard = 0 ): Mapping {
		$mapping = $this->repo->save(
			new Mapping(
				0,
				'example.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'verification_state' => $state->value,
				'hard_failure_count' => $hard,
			),
			array( 'id' => $mapping->id )
		);

		return $this->repo->by_id( $mapping->id ) ?? $mapping;
	}

	public function test_a_match_verifies_the_mapping(): void {
		$mapping  = $this->seed( VerificationState::PENDING );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::MATCH ), new SystemClock() );

		$this->assertSame( DnsOutcome::MATCH, $verifier->verify( $mapping ) );
		$this->assertSame( VerificationState::VERIFIED, $this->repo->by_id( $mapping->id )?->verification_state );
	}

	public function test_the_third_hard_failure_fails_a_verified_mapping(): void {
		$mapping  = $this->seed( VerificationState::VERIFIED, 2 );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::NO_RECORD ), new SystemClock() );

		$verifier->verify( $mapping );

		$this->assertSame( VerificationState::FAILED, $this->repo->by_id( $mapping->id )?->verification_state );
	}

	public function test_a_transient_leaves_a_verified_mapping_verified(): void {
		$mapping  = $this->seed( VerificationState::VERIFIED, 2 );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::TRANSIENT ), new SystemClock() );

		$verifier->verify( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( VerificationState::VERIFIED, $after?->verification_state );
	}

	public function test_the_resolver_class_is_recorded(): void {
		$mapping  = $this->seed( VerificationState::PENDING );
		$resolver = $this->stub( DnsOutcome::MATCH );

		( new Verifier( $this->repo, $resolver, new SystemClock() ) )->verify( $mapping );

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$recorded = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT resolver_class FROM ' . Schema::domains_table() . ' WHERE id = %d',
				$mapping->id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$this->assertSame( $resolver::class, $recorded );
	}

	public function test_an_event_is_written(): void {
		$mapping = $this->seed( VerificationState::PENDING );

		( new Verifier( $this->repo, $this->stub( DnsOutcome::MATCH ), new SystemClock() ) )->verify( $mapping );

		$events = EventLog::for_domain( $mapping->id );

		$this->assertNotEmpty( $events );
		$this->assertSame( 'verification', $events[0]['type'] );
	}

	public function test_a_result_is_discarded_when_the_row_changed_underneath(): void {
		$mapping = $this->seed( VerificationState::PENDING );

		$racing = new class( $this->repo, $mapping ) implements DnsResolver {
			public function __construct(
				private readonly DbRepository $repo,
				private readonly Mapping $mapping
			) {}

			public function txt( string $name, string $expected ): DnsResult {
				// Rotate the challenge while the query is in flight.
				global $wpdb;
				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'challenge' => str_repeat( 'z', 32 ),
						'revision'  => 99,
					),
					array( 'id' => $this->mapping->id )
				);

				return new DnsResult( DnsOutcome::MATCH );
			}
		};

		( new Verifier( $this->repo, $racing, new SystemClock() ) )->verify( $mapping );

		$this->assertNotSame(
			VerificationState::VERIFIED,
			$this->repo->by_id( $mapping->id )?->verification_state,
			'the result answered a question that is no longer being asked'
		);
	}

	public function test_a_corrupt_challenge_label_sets_the_integrity_error(): void {
		$mapping = $this->seed( VerificationState::PENDING );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'challenge_label' => 'has.a.dot' ),
			array( 'id' => $mapping->id )
		);

		$reloaded = $this->repo->by_id( $mapping->id );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::MATCH ), new SystemClock() );

		$verifier->verify( $reloaded );

		$this->assertNotNull( $this->repo->by_id( $mapping->id )?->integrity_error );
	}
}
