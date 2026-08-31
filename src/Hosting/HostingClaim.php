<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One row claimed for exactly one attachment attempt.
 *
 * Every field is re-checked by the settling CAS, so a claim is a fencing token
 * as much as a record: holding one is not authority to write, matching one is.
 * A credential rebound to a different team, a clone that inherited the row, or
 * a second worker with its own attempt all fail that comparison.
 */
final class HostingClaim {

	public function __construct(
		public readonly int $mapping_id,
		/** The revision the claim produced, which the settle CAS pins. */
		public readonly int $revision,
		public readonly string $provider,
		public readonly string $environment_id,
		/** Unguessable, and stored in `hosting_ref` until the attempt settles. */
		public readonly string $attempt
	) {}

	/** The same claim, advanced past a write that bumped the revision. */
	public function at_revision( int $revision ): self {
		return new self( $this->mapping_id, $revision, $this->provider, $this->environment_id, $this->attempt );
	}
}
