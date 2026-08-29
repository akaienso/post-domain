<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\Mapping;

/**
 * The single return type of every SSL service operation.
 *
 * A provider call that succeeded is not the same fact as a mutation that took
 * effect. Returning the provider's SslStatus after a lost finalization would let
 * REST answer 200 for work that was discarded, so a fenced or unpersisted
 * attempt carries no status at all.
 */
final class MutationResult {

	private function __construct(
		public readonly MutationDisposition $disposition,
		public readonly ?SslStatus $status,
		public readonly ?MutationRefusal $refusal,
		public readonly string $note
	) {}

	public static function committed( SslStatus $status, string $note = '' ): self {
		return new self( MutationDisposition::COMMITTED, $status, null, $note );
	}

	public static function refused( MutationRefusal $refusal ): self {
		return new self( MutationDisposition::REFUSED, null, $refusal, $refusal->detail ?? $refusal->precondition );
	}

	public static function ambiguous( string $note ): self {
		return new self( MutationDisposition::AMBIGUOUS_RETAINED, null, null, $note );
	}

	public static function fenced( string $note = 'recovery owns this row now' ): self {
		return new self( MutationDisposition::FENCED, null, null, $note );
	}

	public static function not_persisted( string $note = 'the provider confirmed; the local result could not be established here' ): self {
		return new self( MutationDisposition::CONFIRMED_NOT_PERSISTED, null, null, $note );
	}

	/**
	 * Reads a zero-row finalization for what it was. Both cases discard the local
	 * result; only one means somebody else is now responsible for the row, so the
	 * row itself is what settles it.
	 */
	public static function lost( ?Mapping $row, string $expected_token ): self {
		if ( null === $row
			|| $row->ssl_mutation_token !== $expected_token
			|| MutationPhase::RECOVERING === $row->ssl_mutation_phase ) {
			return self::fenced();
		}

		return self::not_persisted();
	}

	public function succeeded(): bool {
		return MutationDisposition::COMMITTED === $this->disposition;
	}
}
