<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Everything a caller must prove to write through a lease it holds: the row, the
 * revision it saw, the token, the kind, the phase, and the durable binding to the
 * driver and provider environment the mutation began against.
 *
 * It exists so "pin every value the caller possesses" is structural rather than a
 * discipline each CAS has to remember separately.
 */
final class LeaseOwner {

	public function __construct(
		public readonly int $mapping_id,
		public readonly int $revision,
		public readonly string $token,
		public readonly MutationKind $kind,
		public readonly MutationPhase $phase,
		public readonly string $driver,
		public readonly string $environment
	) {}

	/** The same owner, one phase along, at the revision that transition produced. */
	public function at( int $revision, MutationPhase $phase ): self {
		return new self(
			$this->mapping_id,
			$revision,
			$this->token,
			$this->kind,
			$phase,
			$this->driver,
			$this->environment
		);
	}

	/**
	 * Values for MutationLease::OWNER_PREDICATE, in its placeholder order.
	 *
	 * The mapping id is deliberately absent: it belongs to the `id = %d` that
	 * precedes the constant, so every caller passes `$owner->mapping_id` first.
	 * `where_values()` below does that for you and is what callers should use.
	 *
	 * @return array<int, int|string>
	 */
	public function predicate_values(): array {
		return array(
			$this->revision,
			$this->token,
			$this->kind->value,
			$this->phase->value,
			$this->driver,
			$this->environment,
		);
	}

	/**
	 * The complete value list for `WHERE id = %d ` . OWNER_PREDICATE, in order.
	 *
	 * @param array<int, int|string> $leading Values for placeholders before the WHERE clause.
	 * @return array<int, int|string>
	 */
	public function where_values( array $leading = array() ): array {
		return array_merge( $leading, array( $this->mapping_id ), $this->predicate_values() );
	}
}
