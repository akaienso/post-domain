<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * Which hosting account and site a registration belongs to.
 *
 * The hosting equivalent of the SSL subsystem's provider environment: two
 * installations pointed at different Wordify teams are not interchangeable, and
 * a stored registration is only meaningful alongside the environment it was
 * made in. A clone that inherits the row must not act on it.
 */
final class HostingEnvironment {

	public function __construct(
		public readonly string $provider,
		public readonly string $team_id,
		public readonly string $site_id
	) {}

	/** A stable, readable identity for the account and site a row is bound to. */
	public function id(): string {
		return $this->provider . ':' . $this->team_id . ':' . $this->site_id;
	}

	public function matches( ?string $environment_id ): bool {
		return null !== $environment_id && hash_equals( $this->id(), $environment_id );
	}
}
