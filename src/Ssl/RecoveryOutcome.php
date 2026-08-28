<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class RecoveryOutcome {

	private function __construct(
		public readonly bool $conclusive,
		public readonly ?LeaseOutcome $apply,
		public readonly bool $delete_row,
		public readonly string $note
	) {}

	public static function inconclusive( string $note ): self {
		return new self( false, null, false, $note );
	}

	public static function apply( LeaseOutcome $outcome, string $note ): self {
		return new self( true, $outcome, false, $note );
	}

	public static function delete( string $note ): self {
		return new self( true, null, true, $note );
	}
}
