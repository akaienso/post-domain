<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

/**
 * A held per-mapping verification lease (spec §13.5).
 *
 * The token alone is not enough to apply a result safely. The lease CAS bumps
 * the revision as it takes the lease, and the result CAS has to bind *that*
 * revision — the one the row holds the instant the lease was won — so that any
 * writer who touched the row in between is detected even when it left the
 * challenge and the lease token alone.
 *
 * `revision` is therefore derived from the winning CAS, never re-read: a re-read
 * would return whatever the interloper wrote and defeat the whole point.
 */
final class VerificationLease {

	public function __construct(
		public readonly string $token,
		public readonly int $revision
	) {}
}
