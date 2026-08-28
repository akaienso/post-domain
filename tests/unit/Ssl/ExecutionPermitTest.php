<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\SslResourceContext;

final class ExecutionPermitTest extends TestCase {

	private const TOKEN = '11111111111111111111111111111111';

	private function context( string $token, int $mapping_id = 12 ): SslResourceContext {
		return new SslResourceContext(
			$mapping_id, 'mapped.test', 'install-a', 'test-driver', 'test-driver:default', 'ref-1', null, null,
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 'abc', 4, $token, 'txt'
		);
	}

	/** Builds a permit the way MutationGate does, bypassing the caller check for unit isolation. */
	private function issued( string $token, MutationOperation $operation = MutationOperation::CREATE ): ExecutionPermit {
		$reflection  = new \ReflectionClass( ExecutionPermit::class );
		$permit      = $reflection->newInstanceWithoutConstructor();
		$constructor = $reflection->getConstructor();
		$constructor?->invoke(
			$permit,
			$operation,
			12,
			5,
			$token,
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);

		return $permit;
	}

	public function test_the_constructor_is_private(): void {
		$reflection = new \ReflectionClass( ExecutionPermit::class );

		$this->assertTrue(
			(bool) $reflection->getConstructor()?->isPrivate(),
			'a freely constructible permit would let a service bypass consumption'
		);
	}

	public function test_issuing_from_outside_the_gate_throws(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'MutationGate' );

		ExecutionPermit::issue(
			MutationOperation::CREATE,
			12,
			5,
			self::TOKEN,
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_each_operation_maps_to_a_mutation_kind(): void {
		$this->assertSame( MutationKind::CREATE, MutationOperation::CREATE->kind() );
		$this->assertSame( MutationKind::ADOPT, MutationOperation::ADOPT->kind() );
		$this->assertSame( MutationKind::METHOD, MutationOperation::CHANGE_METHOD->kind() );
		$this->assertSame( MutationKind::REMOVE, MutationOperation::REMOVE->kind() );
	}

	public function test_assert_for_rejects_a_mismatched_operation(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for( MutationOperation::REMOVE, $this->context( self::TOKEN ) );
	}

	public function test_assert_for_rejects_a_mismatched_mapping(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for( MutationOperation::CREATE, $this->context( self::TOKEN, 99 ) );
	}

	public function test_assert_for_rejects_a_mismatched_token(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for(
			MutationOperation::CREATE,
			$this->context( '22222222222222222222222222222222' )
		);
	}

	public function test_assert_for_rejects_a_context_with_no_token(): void {
		$context = new SslResourceContext(
			12, 'mapped.test', 'install-a', 'test-driver', 'test-driver:default', 'ref-1', null, null,
			'_x', 'v', 'abc', 4, null, 'txt'
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for( MutationOperation::CREATE, $context );
	}

	public function test_assert_for_accepts_a_matching_permit(): void {
		$permit = $this->issued( self::TOKEN );
		$permit->assert_for( MutationOperation::CREATE, $this->context( self::TOKEN ) );

		$this->assertSame( 12, $permit->mapping_id );
		$this->assertSame( 5, $permit->in_flight_revision );
	}
}
