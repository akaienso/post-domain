<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One domain record attached to a site.
 *
 * `ssl_state`, `dns_verified_at` and `is_primary` are the field names the
 * verified tool schemas state. `reference` is whatever id the record carries,
 * falling back to the hostname: the record's id field name is not verified, and
 * a stored reference that is merely the hostname is still a usable one.
 */
final class WordifyDomain {

	public function __construct(
		public readonly string $host,
		public readonly bool $is_primary,
		public readonly ?string $ssl_state,
		public readonly ?string $dns_verified_at,
		public readonly string $reference
	) {}

	public function is( string $host ): bool {
		return 0 === strcasecmp( $this->host, $host );
	}
}
