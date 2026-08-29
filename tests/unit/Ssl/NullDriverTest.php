<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\SslResourceContext;

final class NullDriverTest extends TestCase {

	private const TOKEN = '11111111111111111111111111111111';

	private function context( string $token = self::TOKEN ): SslResourceContext {
		return new SslResourceContext(
			12,
			'mapped.test',
			'install-a',
			'null',
			null,
			null,
			null,
			null,
			'_post-domain-challenge.mapped.test',
			'post-domain-verify=abc',
			'abc',
			4,
			$token
		);
	}

	private function permit( MutationOperation $operation ): ExecutionPermit {
		$reflection  = new \ReflectionClass( ExecutionPermit::class );
		$permit      = $reflection->newInstanceWithoutConstructor();
		$constructor = $reflection->getConstructor();
		$constructor?->invoke(
			$permit,
			$operation,
			12,
			5,
			self::TOKEN,
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);

		return $permit;
	}

	public function test_the_id_and_capabilities_are_stable(): void {
		$driver = new NullDriver();

		$this->assertSame( 'null', $driver->id() );
		$this->assertFalse( $driver->capabilities()->supports_markers );
		$this->assertSame( array(), $driver->capabilities()->validation_methods );
	}

	public function test_status_says_certificates_are_handled_elsewhere(): void {
		$status = ( new NullDriver() )->status( $this->context() );

		$this->assertSame( SslState::NONE, $status->state );
		$this->assertStringContainsString( 'outside', (string) $status->message );
	}

	public function test_identity_is_absent_complete_and_not_transient(): void {
		$identity = ( new NullDriver() )->identify( $this->context() );

		$this->assertSame( IdentityVerdict::ABSENT, $identity->verdict );
		$this->assertTrue( $identity->read_complete );
		$this->assertFalse( $identity->transient );
	}

	public function test_create_changes_nothing(): void {
		$status = ( new NullDriver() )->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertSame( SslState::NONE, $status->state );
		$this->assertNull( $status->ref );
	}

	public function test_removal_reports_removed_because_nothing_exists(): void {
		$result = ( new NullDriver() )->remove( $this->context(), $this->permit( MutationOperation::REMOVE ) );

		$this->assertSame( RemovalOutcome::REMOVED, $result->outcome );
	}

	public function test_a_permit_for_the_wrong_operation_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new NullDriver() )->remove( $this->context(), $this->permit( MutationOperation::CREATE ) );
	}

	public function test_a_permit_for_a_different_execution_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new NullDriver() )->create(
			$this->context( '22222222222222222222222222222222' ),
			$this->permit( MutationOperation::CREATE )
		);
	}

	public function test_reconcile_reports_a_complete_empty_snapshot(): void {
		$report = ( new NullDriver() )->reconcile( array( $this->context() ) );

		$this->assertTrue( $report->snapshot_complete );

		// `statuses` is declared `iterable`, so it may be an array or a Traversable.
		// iterator_to_array() only accepts a plain array from PHP 8.2; the plugin
		// supports 8.1, where that is a TypeError.
		$statuses = is_array( $report->statuses )
			? $report->statuses
			: iterator_to_array( $report->statuses );

		$this->assertSame( array(), $statuses );
	}

	public function test_the_validation_plan_contributes_no_provider_records(): void {
		$plan = ( new NullDriver() )->validation_plan( $this->context(), null );

		$this->assertSame( array(), $plan->http );
		$this->assertSame( array(), $plan->blockers );
	}
}
