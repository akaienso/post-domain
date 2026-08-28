<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\VerificationState;

final class GracePolicy {

	/**
	 * @return array{state: VerificationState, hard: int, transient: int}
	 */
	public static function apply(
		VerificationState $state,
		DnsOutcome $outcome,
		int $hard,
		int $transient,
		int $limit,
		bool $deadline_passed
	): array {
		if ( DnsOutcome::TRANSIENT === $outcome ) {
			// The hard counter is untouched, and no transient result deactivates.
			return array(
				'state'     => $state,
				'hard'      => $hard,
				'transient' => $transient + 1,
			);
		}

		if ( DnsOutcome::MATCH === $outcome ) {
			return array(
				'state'     => VerificationState::FAILED === $state
					? VerificationState::FAILED
					: VerificationState::VERIFIED,
				'hard'      => 0,
				'transient' => 0,
			);
		}

		// A hard answer proves the resolver is reachable.
		++$hard;

		if ( VerificationState::PENDING === $state ) {
			return array(
				'state'     => $deadline_passed ? VerificationState::FAILED : VerificationState::PENDING,
				'hard'      => $hard,
				'transient' => 0,
			);
		}

		if ( VerificationState::VERIFIED === $state ) {
			return array(
				'state'     => $hard >= $limit ? VerificationState::FAILED : VerificationState::VERIFIED,
				'hard'      => $hard,
				'transient' => 0,
			);
		}

		return array( 'state' => $state, 'hard' => $hard, 'transient' => 0 );
	}
}
