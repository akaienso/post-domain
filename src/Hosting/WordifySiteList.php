<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/** The sites a read returned, in the order the provider gave them. */
final class WordifySiteList {

	/**
	 * @param WordifySite[] $sites
	 */
	public function __construct( public readonly array $sites ) {}

	public function is_empty(): bool {
		return array() === $this->sites;
	}

	public function first(): ?WordifySite {
		return $this->sites[0] ?? null;
	}

	public function has_site( string $site_id ): bool {
		foreach ( $this->sites as $site ) {
			if ( $site->id === $site_id ) {
				return true;
			}
		}

		return false;
	}
}
