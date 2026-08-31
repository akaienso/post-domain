<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/** Every domain a site read reported, primary first then newest. */
final class WordifyDomainList {

	/**
	 * @param WordifyDomain[] $domains
	 */
	public function __construct( public readonly array $domains ) {}

	public function find( string $host ): ?WordifyDomain {
		foreach ( $this->domains as $domain ) {
			if ( $domain->is( $host ) ) {
				return $domain;
			}
		}

		return null;
	}
}
