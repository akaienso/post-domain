<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class TransitionResult {

	public function __construct(
		public readonly TransitionOutcome $outcome,
		public readonly string $detail
	) {}

	public function committed(): bool {
		return TransitionOutcome::COMMITTED === $this->outcome;
	}

	/** True only for the one cause that means another owner holds the row. */
	public function cas_lost(): bool {
		return TransitionOutcome::CAS_LOST === $this->outcome;
	}
}
