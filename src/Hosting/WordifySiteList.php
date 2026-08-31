<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One page of sites, in the order the provider gave them.
 *
 * Paginated rather than complete, because an account can hold hundreds of
 * sites and a screen that tries to render all of them is a screen that fails
 * on the accounts that need it most.
 */
final class WordifySiteList {

	/**
	 * @param WordifySite[] $sites
	 */
	public function __construct(
		public readonly array $sites,
		public readonly int $page = 1,
		public readonly int $per_page = 0,
		public readonly ?int $total = null,
		public readonly ?int $last_page = null
	) {}

	public function is_empty(): bool {
		return array() === $this->sites;
	}

	public function first(): ?WordifySite {
		return $this->sites[0] ?? null;
	}

	public function has_site( string $site_id ): bool {
		return null !== $this->site( $site_id );
	}

	public function site( string $site_id ): ?WordifySite {
		foreach ( $this->sites as $site ) {
			if ( hash_equals( $site->id, $site_id ) ) {
				return $site;
			}
		}

		return null;
	}

	public function has_more(): bool {
		if ( null !== $this->last_page ) {
			return $this->page < $this->last_page;
		}

		// No pagination metadata: a full page is evidence there may be another.
		return 0 !== $this->per_page && count( $this->sites ) >= $this->per_page;
	}
}
