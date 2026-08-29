<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\GateResult;
use PostDomain\Ssl\LeaseBinding;
use PostDomain\Ssl\MutationAuthorization;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use WP_UnitTestCase;

final class MutationGateTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationLease $lease;

	private MutationGate $gate;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo   = new DbRepository();
		$this->lease  = new MutationLease( new SystemClock() );
		$this->gate   = new MutationGate( $this->lease, new SystemClock() );
		$this->driver = RecordingDriver::succeeding( 'ref-1' );
	}

	private function seed(): Mapping {
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
				'_post-domain-challenge'
			)
		);
	}

	/** @return array{auth: MutationAuthorization, context: SslResourceContext, token: string, revision: int} */
	private function reserved( Mapping $m, MutationOperation $op ): array {
		$lease  = $this->lease->acquire( $m->id, $m->revision, $op->kind(), $this->driver );
		$leased = $this->repo->by_id( $m->id );

		$binding = new LeaseBinding(
			$leased->id,
			$lease->revision,
			$lease->token,
			$op->kind(),
			$leased->host,
			$leased->ssl_provider,
			$leased->ssl_ref,
			$leased->challenge,
			$leased->ssl_method,
			$leased->ssl_ownership_origin,
			$leased->ssl_owner_installation_id,
			$lease->driver,
			$lease->environment
		);

		return array(
			'auth'     => new MutationAuthorization(
				$op,
				$binding,
				false,
				new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
			),
			'context'  => SslResourceContext::from_mapping(
				$leased,
				'install-a',
				'_x.mapped.test',
				$this->driver->id(),
				$lease->token
			),
			'token'    => $lease->token,
			'revision' => $lease->revision,
		);
	}

	public function test_the_phase_is_already_in_flight_when_the_driver_runs(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );

		$result = $this->gate->execute( $driver, $setup['context'], $setup['auth'] );

		$this->assertInstanceOf( GateResult::class, $result );
		$this->assertSame( 1, $driver->create_calls );
		$this->assertSame(
			array( 'in_flight' ),
			$driver->phases_observed,
			'consumption happens before the driver is entered'
		);
	}

	public function test_the_gate_returns_the_in_flight_revision_rather_than_a_guess(): void {
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );
		$result = $this->gate->execute( RecordingDriver::succeeding( 'ref-1' ), $setup['context'], $setup['auth'] );

		$this->assertSame( $setup['revision'] + 1, $result->owner->revision );
		$this->assertSame( MutationPhase::IN_FLIGHT, $result->owner->phase );
		$this->assertSame( $setup['auth']->binding->mutation_environment, $result->owner->environment );
	}

	public function test_a_provider_environment_change_stops_the_call_before_it_is_sent(): void {
		$setup = $this->reserved( $this->seed(), MutationOperation::CREATE );

		// Same driver id, different account: nothing has left yet, so refuse.
		$moved  = $this->driver->in_environment( 'zone:somewhere-else' );
		$result = $this->gate->execute( $moved, $setup['context'], $setup['auth'] );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'provider_environment_changed', $result->precondition );
		$this->assertSame( 0, $moved->create_calls );
		$this->assertNull(
			$this->repo->by_id( $setup['auth']->binding->mapping_id )?->ssl_mutation_token,
			'the reservation is released'
		);
	}

	public function test_a_stale_authorization_never_reaches_the_driver(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );
		$b      = $setup['auth']->binding;

		$stale = new MutationAuthorization(
			MutationOperation::CREATE,
			new LeaseBinding(
				$b->mapping_id,
				$b->revision + 7,
				$b->token,
				$b->kind,
				$b->host,
				$b->provider_id,
				$b->provider_ref,
				$b->challenge,
				$b->requested_method,
				$b->ownership_origin,
				$b->owner_installation_id,
				$b->mutation_driver,
				$b->mutation_environment
			),
			false,
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->gate->execute( $driver, $setup['context'], $stale );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 0, $driver->create_calls );
	}

	public function test_the_same_authorization_cannot_begin_a_second_execution(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );

		$this->gate->execute( $driver, $setup['context'], $setup['auth'] );
		$second = $this->gate->execute( $driver, $setup['context'], $setup['auth'] );

		$this->assertInstanceOf( MutationRefusal::class, $second );
		$this->assertSame( 1, $driver->create_calls );
	}

	public function test_an_expired_authorization_is_refused_and_releases_the_reservation(): void {
		$m      = $this->seed();
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $m, MutationOperation::CREATE );

		$expired = new MutationAuthorization(
			MutationOperation::CREATE,
			$setup['auth']->binding,
			false,
			new \DateTimeImmutable( '-1 second', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->gate->execute( $driver, $setup['context'], $expired );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'authorization_expired', $result->precondition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assertNull(
			$this->repo->by_id( $m->id )?->ssl_mutation_token,
			'a pre-consumption refusal releases the reservation'
		);
	}

	/**
	 * @dataProvider operations
	 */
	public function test_each_operation_dispatches_to_its_own_driver_method( string $operation, string $counter ): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$op     = MutationOperation::from( $operation );
		$setup  = $this->reserved( $this->seed(), $op );

		$this->gate->execute( $driver, $setup['context'], $setup['auth'], 'txt' );

		$this->assertSame( 1, $driver->{$counter} );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function operations(): array {
		return array(
			'create' => array( 'create', 'create_calls' ),
			'adopt'  => array( 'adopt', 'adopt_calls' ),
			'method' => array( 'change_method', 'method_calls' ),
			'remove' => array( 'remove', 'remove_calls' ),
		);
	}

	public function test_only_the_gate_lexically_calls_a_mutating_driver_method(): void {
		$mutating  = array( 'create', 'adopt', 'change_validation_method', 'remove' );
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() || 'MutationGate.php' === $file->getFilename() ) {
				continue;
			}

			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];

				if ( ! is_array( $token )
					|| ! in_array( $token[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					continue;
				}

				$j = $i + 1;

				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}

				if ( ! is_array( $tokens[ $j ] ?? null ) || T_STRING !== $tokens[ $j ][0] ) {
					continue;
				}

				if ( ! in_array( $tokens[ $j ][1], $mutating, true ) ) {
					continue;
				}

				$k = $j + 1;

				while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					++$k;
				}

				if ( '(' === ( $tokens[ $k ] ?? null ) ) {
					$offenders[] = $file->getFilename() . ':' . $tokens[ $j ][2] . ' ->' . $tokens[ $j ][1] . '()';
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a mutating driver method may be called only from MutationGate'
		);
	}
}
