<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\LeaseBinding;
use PostDomain\Ssl\LeaseOutcome;
use PostDomain\Ssl\LeaseOwner;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use WP_UnitTestCase;

final class MutationLeaseTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationLease $lease;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo   = new DbRepository();
		$this->lease  = new MutationLease( new SystemClock() );
		$this->driver = RecordingDriver::succeeding( 'ref-1' );
	}

	private function seed( bool $owned = false ): Mapping {
		return $this->seed_host( 'mapped.test', $owned );
	}

	private function seed_host( string $host, bool $owned = false ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, $host, null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, substr( md5( $host ), 0, 32 ), '_post-domain-challenge',
				$owned ? OwnershipOrigin::CREATED : null,
				$owned ? 'install-a' : null,
				$owned ? 'test-driver' : null,
				$owned ? 'test-driver:default' : null,
				$owned ? 'ref-1' : null
			)
		);
	}

	/** @return array{0: string, 1: int} token and revision of a fresh reservation */
	private function reserve( Mapping $m ): array {
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		return array( $lease->token, $lease->revision );
	}

	private function binding( Mapping $m, string $token, int $revision, MutationKind $kind = MutationKind::CREATE ): LeaseBinding {
		return new LeaseBinding(
			$m->id, $revision, $token, $kind, $m->host, $m->ssl_provider, $m->ssl_ref,
			$m->challenge, $m->ssl_method, $m->ssl_ownership_origin, $m->ssl_owner_installation_id,
			$this->driver->id(), $this->driver->environment_id()
		);
	}

	private function force_lease( int $id, MutationPhase $phase, int $offset ): LeaseOwner {
		global $wpdb;

		$token = bin2hex( random_bytes( 16 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => $token,
				'ssl_mutation_kind'        => MutationKind::REMOVE->value,
				'ssl_mutation_phase'       => $phase->value,
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $offset ),
				'ssl_mutation_driver'      => $this->driver->id(),
				'ssl_mutation_environment' => $this->driver->environment_id(),
			),
			array( 'id' => $id )
		);

		return new LeaseOwner(
			$id,
			(int) $this->repo->by_id( $id )?->revision,
			$token,
			MutationKind::REMOVE,
			$phase,
			$this->driver->id(),
			$this->driver->environment_id()
		);
	}

	/** The same owner with exactly one value wrong. */
	private function owner_with( LeaseOwner $owner, string $field, $value ): LeaseOwner {
		return new LeaseOwner(
			$owner->mapping_id,
			'revision' === $field ? $value : $owner->revision,
			'token' === $field ? $value : $owner->token,
			'kind' === $field ? $value : $owner->kind,
			'phase' === $field ? $value : $owner->phase,
			'driver' === $field ? $value : $owner->driver,
			'environment' === $field ? $value : $owner->environment
		);
	}

	public function test_acquiring_on_a_free_row_reserves_it(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		$this->assertNotNull( $lease );
		$this->assertSame( MutationPhase::RESERVED, $this->repo->by_id( $m->id )?->ssl_mutation_phase );
	}

	public function test_acquisition_binds_the_driver_and_environment_before_anything_is_sent(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$after = $this->repo->by_id( $m->id );

		$this->assertSame( $this->driver->id(), $lease->driver );
		$this->assertSame( $this->driver->environment_id(), $lease->environment );
		$this->assertSame( $this->driver->id(), $after?->ssl_mutation_driver );
		$this->assertSame( $this->driver->environment_id(), $after?->ssl_mutation_environment );
		$this->assertNull( $after?->ssl_provider, 'a first create is bound before it has a provider' );
	}

	public function test_consumption_fails_when_the_bound_environment_changed(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		$binding = new LeaseBinding(
			$m->id, $lease->revision, $lease->token, MutationKind::CREATE, $m->host,
			$m->ssl_provider, $m->ssl_ref, $m->challenge, $m->ssl_method,
			$m->ssl_ownership_origin, $m->ssl_owner_installation_id,
			$this->driver->id(), 'zone:somewhere-else'
		);

		$this->assertNull( $this->lease->consume( $binding ) );
	}

	public function test_consumption_fails_when_the_bound_driver_changed(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		$binding = new LeaseBinding(
			$m->id, $lease->revision, $lease->token, MutationKind::CREATE, $m->host,
			$m->ssl_provider, $m->ssl_ref, $m->challenge, $m->ssl_method,
			$m->ssl_ownership_origin, $m->ssl_owner_installation_id,
			'some-other-driver', $this->driver->environment_id()
		);

		$this->assertNull( $this->lease->consume( $binding ) );
	}

	public function test_finalizing_clears_the_binding_and_the_recovery_schedule(): void {
		global $wpdb;

		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$in    = $this->lease->consume( $this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision ) );

		// Pretend a recovery had already scheduled re-reads on this row.
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'ssl_transient_count' => 4 ),
			array( 'id' => $m->id )
		);

		$this->lease->finalize( $in, LeaseOutcome::state( SslState::ACTIVE ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_mutation_driver );
		$this->assertNull( $after?->ssl_mutation_environment );
		$this->assertNull( $after?->ssl_next_attempt_at, 'a finished recovery leaves no schedule behind' );
		$this->assertSame( 0, $after?->ssl_transient_count );
	}

	public function test_finalizing_respects_an_outcome_that_sets_a_schedule_itself(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::REMOVE, $this->driver );
		$in    = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision, MutationKind::REMOVE )
		);

		$this->lease->finalize( $in, LeaseOutcome::attempted( 2, 600 ) );

		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_next_attempt_at );
	}

	public function test_acquiring_against_a_stale_revision_fails(): void {
		$m = $this->seed();

		$this->assertNull( $this->lease->acquire( $m->id, $m->revision + 5, MutationKind::CREATE, $this->driver ) );
	}

	/**
	 * @dataProvider phase_and_expiry
	 */
	public function test_acquisition_fails_against_any_existing_lease( string $phase, int $offset ): void {
		$m = $this->seed();
		$this->force_lease( $m->id, MutationPhase::from( $phase ), $offset );
		$current = $this->repo->by_id( $m->id );

		$this->assertNull(
			$this->lease->acquire( $m->id, (int) $current?->revision, MutationKind::CREATE, $this->driver ),
			'expiry transfers the row to recovery; it does not free it'
		);
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function phase_and_expiry(): array {
		return array(
			'reserved live'      => array( 'reserved', 600 ),
			'reserved expired'   => array( 'reserved', -600 ),
			'in flight live'     => array( 'in_flight', 600 ),
			'in flight expired'  => array( 'in_flight', -600 ),
			'recovering live'    => array( 'recovering', 600 ),
			'recovering expired' => array( 'recovering', -600 ),
		);
	}

	public function test_consuming_moves_reserved_to_in_flight_and_returns_that_revision(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		$in_flight = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision )
		);

		$this->assertSame( $lease->revision + 1, $in_flight?->revision );
		$this->assertSame( MutationPhase::IN_FLIGHT, $this->repo->by_id( $m->id )?->ssl_mutation_phase );
	}

	public function test_consuming_twice_fails_the_second_time(): void {
		$m       = $this->seed();
		$lease   = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$binding = $this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision );

		$this->assertNotNull( $this->lease->consume( $binding ) );
		$this->assertNull( $this->lease->consume( $binding ), 'one execution per authorization' );
	}

	/**
	 * @dataProvider changed_columns
	 * @param array<string, string|null> $change
	 */
	public function test_consuming_fails_when_any_bound_value_changed( array $change ): void {
		global $wpdb;

		$m       = $this->seed( true );
		$lease   = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$binding = $this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision );

		$wpdb->update( Schema::domains_table(), $change, array( 'id' => $m->id ) ); // phpcs:ignore WordPress.DB

		$this->assertNull( $this->lease->consume( $binding ) );
	}

	/** @return array<string, array{0: array<string, string|null>}> */
	public static function changed_columns(): array {
		return array(
			'host'               => array( array( 'host' => 'moved.test' ) ),
			'provider'           => array( array( 'ssl_provider' => 'other-driver' ) ),
			'reference'          => array( array( 'ssl_ref' => 'ref-2' ) ),
			'challenge'          => array( array( 'challenge' => str_repeat( 'z', 32 ) ) ),
			'method'             => array( array( 'ssl_method' => 'http' ) ),
			'ownership origin'   => array( array( 'ssl_ownership_origin' => 'adopted' ) ),
			'owner installation' => array( array( 'ssl_owner_installation_id' => 'someone-else' ) ),
			'ownership cleared'  => array( array( 'ssl_ownership_origin' => null ) ),
			'owner cleared'      => array( array( 'ssl_owner_installation_id' => null ) ),
		);
	}

	public function test_consuming_fails_when_the_lease_expired(): void {
		global $wpdb;

		$m       = $this->seed();
		$lease   = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$binding = $this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $m->id )
		);

		$this->assertNull( $this->lease->consume( $binding ) );
	}

	public function test_releasing_a_reserved_lease_clears_every_lease_column(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		$this->assertTrue( $this->lease->release_reserved( $lease ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_mutation_token );
		$this->assertNull( $after?->ssl_mutation_kind );
		$this->assertNull( $after?->ssl_mutation_phase );
		$this->assertNull( $after?->ssl_mutation_expires_at );
		$this->assertNull( $after?->ssl_mutation_driver );
		$this->assertNull( $after?->ssl_mutation_environment );
	}

	/**
	 * Every owner-pinned CAS, proven to succeed on the exact owner AND to leave a
	 * second row alone.
	 *
	 * Wrong-owner tests cannot catch a shifted placeholder list: a value list one
	 * short makes the revision land in the `id` placeholder, which affects zero
	 * rows and therefore looks exactly like a correctly-refused wrong owner. Only
	 * a success path proves the arguments line up, and only a second row proves
	 * the statement is aimed where it claims.
	 *
	 * @dataProvider owner_pinned_writes
	 */
	public function test_an_owner_pinned_write_hits_its_own_row_and_no_other( string $case ): void {
		$mine      = $this->seed();
		$bystander = $this->seed_host( 'bystander.test' );
		$other     = $this->lease->acquire( $bystander->id, $bystander->revision, MutationKind::CREATE, $this->driver );

		$this->assertNotNull( $other, 'the bystander holds its own lease throughout' );

		switch ( $case ) {
			case 'release_reserved':
				$owner = $this->lease->acquire( $mine->id, $mine->revision, MutationKind::CREATE, $this->driver );

				$this->assertTrue( $this->lease->release_reserved( $owner ) );
				$this->assertNull( $this->repo->by_id( $mine->id )?->ssl_mutation_token );
				break;

			case 'finalize':
				$owner = $this->lease->consume(
					$this->binding( $this->repo->by_id( $mine->id ), ...$this->reserve( $mine ) )
				);

				$this->assertTrue( $this->lease->finalize( $owner, LeaseOutcome::state( SslState::ACTIVE ) ) );
				$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $mine->id )?->ssl_state );
				break;

			case 'delete_row':
				$owner = $this->lease->consume(
					$this->binding( $this->repo->by_id( $mine->id ), ...$this->reserve( $mine ) )
				);

				$this->assertTrue( $this->lease->delete_row( $owner ) );
				$this->assertNull( $this->repo->by_id( $mine->id ) );
				break;

			case 'clear_expired_reserved':
				$owner = $this->force_lease( $mine->id, MutationPhase::RESERVED, -600 );

				$this->assertTrue( $this->lease->clear_expired_reserved( $owner ) );
				$this->assertNull( $this->repo->by_id( $mine->id )?->ssl_mutation_token );
				break;

			case 'claim_recovery':
				$owner = $this->force_lease( $mine->id, MutationPhase::IN_FLIGHT, -600 );

				$this->assertNotNull( $this->lease->claim_recovery( $owner ) );
				$this->assertSame( MutationPhase::RECOVERING, $this->repo->by_id( $mine->id )?->ssl_mutation_phase );
				break;

			case 'extend_recovery':
				$owner = $this->lease->claim_recovery( $this->force_lease( $mine->id, MutationPhase::IN_FLIGHT, -600 ) );

				$this->assertTrue( $this->lease->extend_recovery( $owner, 2 ) );
				$this->assertSame( 2, $this->repo->by_id( $mine->id )?->ssl_transient_count );
				break;
		}

		// The bystander's lease is untouched, in every column, in every case.
		$untouched = $this->repo->by_id( $bystander->id );

		$this->assertSame( $other->token, $untouched?->ssl_mutation_token );
		$this->assertSame( MutationPhase::RESERVED, $untouched?->ssl_mutation_phase );
		$this->assertSame( $other->revision, $untouched?->revision );
		$this->assertSame( $this->driver->environment_id(), $untouched?->ssl_mutation_environment );
		$this->assertSame( 0, $untouched?->ssl_transient_count );
	}

	/** @return array<string, array{0: string}> */
	public static function owner_pinned_writes(): array {
		return array(
			'release_reserved'       => array( 'release_reserved' ),
			'finalize'               => array( 'finalize' ),
			'delete_row'             => array( 'delete_row' ),
			'clear_expired_reserved' => array( 'clear_expired_reserved' ),
			'claim_recovery'         => array( 'claim_recovery' ),
			'extend_recovery'        => array( 'extend_recovery' ),
		);
	}

	public function test_the_owner_predicate_and_its_value_list_stay_the_same_length(): void {
		$owner = new LeaseOwner(
			7,
			3,
			str_repeat( 'a', 32 ),
			MutationKind::CREATE,
			MutationPhase::RESERVED,
			'recording',
			'recording:default'
		);

		$reflection = new \ReflectionClass( MutationLease::class );
		$predicate  = (string) $reflection->getConstant( 'OWNER_PREDICATE' );

		$this->assertSame(
			substr_count( $predicate, '%' ) + 1,
			count( $owner->where_values() ),
			'the +1 is the `id = %d` the constant does not contain — the value everyone forgets'
		);
	}

	/**
	 * @dataProvider wrong_owner_values
	 */
	public function test_release_refuses_any_wrong_owner_value( string $field, $value ): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );

		$this->assertFalse( $this->lease->release_reserved( $this->owner_with( $lease, $field, $value ) ) );
	}

	/** @return array<string, array{0: string, 1: mixed}> */
	public static function wrong_owner_values(): array {
		return array(
			'revision'    => array( 'revision', 999 ),
			'token'       => array( 'token', '00000000000000000000000000000000' ),
			'kind'        => array( 'kind', MutationKind::REMOVE ),
			'phase'       => array( 'phase', MutationPhase::IN_FLIGHT ),
			'driver'      => array( 'driver', 'some-other-driver' ),
			'environment' => array( 'environment', 'zone:somewhere-else' ),
		);
	}

	public function test_release_never_clears_an_in_flight_lease(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$in    = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision )
		);

		$this->assertFalse(
			$this->lease->release_reserved( $in->at( $in->revision, MutationPhase::RESERVED ) ),
			'an in-flight lease may have reached the provider'
		);
	}

	public function test_finalize_applies_an_allowlisted_outcome_and_clears_the_lease(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$in    = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision )
		);

		$this->assertTrue(
			$this->lease->finalize(
				$in,
				LeaseOutcome::bound( SslState::REQUESTED, 'ref-1', 'test-driver', 'test-driver:default', OwnershipOrigin::CREATED, 'install-a' )
			)
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( SslState::REQUESTED, $after?->ssl_state );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_finalize_fails_under_a_replaced_token(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE, $this->driver );
		$in    = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision )
		);

		$this->force_lease( $m->id, MutationPhase::RECOVERING, 600 );

		$this->assertFalse(
			$this->lease->finalize( $in, LeaseOutcome::state( SslState::ACTIVE ) ),
			'a fenced worker cannot apply its result'
		);
	}

	public function test_an_outcome_cannot_carry_an_unapproved_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		LeaseOutcome::raw( array( 'post_id' => 99 ) );
	}

	public function test_an_expired_reserved_lease_clears_without_a_read(): void {
		$m     = $this->seed();
		$owner = $this->force_lease( $m->id, MutationPhase::RESERVED, -600 );

		$this->assertTrue( $this->lease->clear_expired_reserved( $owner ) );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_clearing_refuses_unexpired_reserved_and_any_in_flight(): void {
		$m = $this->seed();

		$live = $this->force_lease( $m->id, MutationPhase::RESERVED, 600 );
		$this->assertFalse( $this->lease->clear_expired_reserved( $live ) );

		$flight = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 );
		$this->assertFalse( $this->lease->clear_expired_reserved( $flight ) );
	}

	public function test_clearing_refuses_a_changed_provider_binding(): void {
		$m     = $this->seed();
		$owner = $this->force_lease( $m->id, MutationPhase::RESERVED, -600 );

		$this->assertFalse(
			$this->lease->clear_expired_reserved( $this->owner_with( $owner, 'environment', 'zone:elsewhere' ) )
		);
		$this->assertNotNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_claiming_recovery_replaces_the_token_and_preserves_the_binding(): void {
		$m   = $this->seed();
		$old = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 );

		$claim = $this->lease->claim_recovery( $old );

		$this->assertNotNull( $claim );
		$this->assertNotSame( $old->token, $claim->token );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( MutationPhase::RECOVERING, $after?->ssl_mutation_phase );
		$this->assertSame( MutationKind::REMOVE, $after?->ssl_mutation_kind );
		$this->assertSame( $after?->revision, $claim->revision );
		$this->assertSame( $old->driver, $after?->ssl_mutation_driver, 'inherited, never rebound' );
		$this->assertSame( $old->environment, $after?->ssl_mutation_environment );
		$this->assertSame( $old->environment, $claim->environment );
	}

	public function test_claiming_recovery_refuses_an_unexpired_lease(): void {
		$m = $this->seed();

		$this->assertNull( $this->lease->claim_recovery( $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, 600 ) ) );
	}

	/**
	 * @dataProvider stale_claim_snapshots
	 */
	public function test_claiming_recovery_refuses_a_snapshot_that_moved( string $field, $value ): void {
		$m   = $this->seed();
		$old = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 );

		$this->assertNull( $this->lease->claim_recovery( $this->owner_with( $old, $field, $value ) ) );
		$this->assertSame(
			$old->token,
			$this->repo->by_id( $m->id )?->ssl_mutation_token,
			'a stale snapshot changes nothing at all'
		);
	}

	/** @return array<string, array{0: string, 1: mixed}> */
	public static function stale_claim_snapshots(): array {
		return array(
			'kind moved'        => array( 'kind', MutationKind::CREATE ),
			'phase moved'       => array( 'phase', MutationPhase::RECOVERING ),
			'token moved'       => array( 'token', '00000000000000000000000000000000' ),
			'driver moved'      => array( 'driver', 'some-other-driver' ),
			'environment moved' => array( 'environment', 'zone:somewhere-else' ),
		);
	}

	public function test_claiming_recovery_from_recovering_takes_over_an_abandoned_worker(): void {
		$m   = $this->seed();
		$old = $this->force_lease( $m->id, MutationPhase::RECOVERING, -600 );

		$claim = $this->lease->claim_recovery( $old );

		$this->assertNotNull( $claim );
		$this->assertNotSame( $old->token, $claim->token );
	}

	public function test_claiming_recovery_from_reserved_is_a_programming_error(): void {
		$m = $this->seed();

		$this->expectException( \LogicException::class );

		$this->lease->claim_recovery( $this->force_lease( $m->id, MutationPhase::RESERVED, -600 ) );
	}

	/**
	 * @dataProvider wrong_owner_values
	 */
	public function test_extending_recovery_refuses_any_wrong_owner_value( string $field, $value ): void {
		$m     = $this->seed();
		$claim = $this->lease->claim_recovery( $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 ) );

		// 'phase' is RECOVERING for a claim, so the shared provider's IN_FLIGHT
		// value is still a wrong value here, as are the other five.
		$this->assertFalse( $this->lease->extend_recovery( $this->owner_with( $claim, $field, $value ), 0 ) );
	}

	public function test_extending_recovery_succeeds_for_the_exact_owner(): void {
		$m     = $this->seed();
		$claim = $this->lease->claim_recovery( $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 ) );

		$this->assertTrue( $this->lease->extend_recovery( $claim, 0 ) );
	}

	public function test_extending_recovery_persists_a_growing_backoff_inside_its_window(): void {
		$m     = $this->seed();
		$claim = $this->lease->claim_recovery( $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 ) );

		$this->lease->extend_recovery( $claim, 0 );
		$first = $this->repo->by_id( $m->id );

		$this->lease->extend_recovery( $claim->at( $first->revision, MutationPhase::RECOVERING ), 3 );
		$second = $this->repo->by_id( $m->id );

		$this->assertSame( 0, $first?->ssl_transient_count );
		$this->assertSame( 3, $second?->ssl_transient_count );
		$this->assertGreaterThan(
			strtotime( (string) $first?->ssl_next_attempt_at ),
			strtotime( (string) $second?->ssl_next_attempt_at ),
			'each inconclusive read is spaced further out than the last'
		);

		foreach ( array( $first, $second ) as $row ) {
			$this->assertLessThan(
				strtotime( (string) $row?->ssl_mutation_expires_at ),
				strtotime( (string) $row?->ssl_next_attempt_at ),
				'the scheduled re-read must fall inside the window its owner holds'
			);
		}
	}

	public function test_extending_recovery_on_a_stale_revision_affects_nothing(): void {
		$m     = $this->seed();
		$claim = $this->lease->claim_recovery( $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 ) );

		$this->lease->extend_recovery( $claim, 0 );

		$this->assertFalse(
			$this->lease->extend_recovery( $claim, 1 ),
			'the revision moved; this worker no longer speaks for the row'
		);
	}

	public function test_delete_row_requires_the_exact_owner(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::REMOVE, $this->driver );
		$in    = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease->token, $lease->revision, MutationKind::REMOVE )
		);

		$this->assertFalse(
			$this->lease->delete_row( $this->owner_with( $in, 'token', str_repeat( '0', 32 ) ) )
		);
		$this->assertNotNull( $this->repo->by_id( $m->id ) );

		$this->assertTrue(
			$this->lease->delete_row( $in )
		);
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}
}
