<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Proof that the authorization was consumed by the RESERVED -> IN_FLIGHT
 * transition. The constructor is private and issue() refuses any caller other
 * than MutationGate, so no service can fabricate one and skip consumption.
 */
final class ExecutionPermit {

	private function __construct(
		public readonly MutationOperation $operation,
		public readonly int $mapping_id,
		public readonly int $in_flight_revision,
		public readonly string $lease_token,
		public readonly \DateTimeImmutable $expires_at
	) {}

	public static function issue(
		MutationOperation $operation,
		int $mapping_id,
		int $in_flight_revision,
		string $lease_token,
		\DateTimeImmutable $expires_at
	): self {
		$frame  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? array();
		$caller = $frame['class'] ?? '';

		if ( MutationGate::class !== $caller ) {
			throw new \LogicException( 'Execution permits are issued only by MutationGate.' );
		}

		return new self( $operation, $mapping_id, $in_flight_revision, $lease_token, $expires_at );
	}

	public function assert_for( MutationOperation $operation, SslResourceContext $context ): void {
		if ( $this->operation !== $operation ) {
			throw new \InvalidArgumentException(
				sprintf( 'Permit is for %s, not %s.', $this->operation->value, $operation->value )
			);
		}

		if ( $this->mapping_id !== $context->mapping_id ) {
			throw new \InvalidArgumentException( 'Permit and context describe different mappings.' );
		}

		if ( null === $context->lease_token || ! hash_equals( $this->lease_token, $context->lease_token ) ) {
			throw new \InvalidArgumentException( 'Permit and context describe different executions.' );
		}
	}
}
