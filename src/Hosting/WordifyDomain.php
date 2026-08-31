<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One domain record attached to a site.
 *
 * The field names here were observed from live read-only responses against a
 * real account: `dns_status`, `ssl_status`, `dns_checked_at`, `ssl_checked_at`,
 * `is_primary`. `reference` is the record's own `id`, falling back to the
 * hostname, which is still a usable reference.
 *
 * Wordify's own DNS and certificate status is reported, never acted on. It does
 * not stand in for the plugin's Cloudflare state and it does not settle the
 * signed final-origin probe, which is the only thing that proves a mapped
 * hostname reaches this installation.
 */
final class WordifyDomain {

	public function __construct(
		public readonly string $host,
		public readonly bool $is_primary,
		// The names Wordify actually returns, observed from live read-only
		// responses. `ssl_state`/`dns_verified_at` appear in older tooling
		// descriptions and are accepted as aliases, never preferred.
		public readonly ?string $ssl_status,
		public readonly ?string $dns_status,
		public readonly ?string $dns_checked_at,
		public readonly ?string $ssl_checked_at,
		public readonly string $reference
	) {}

	public function is( string $host ): bool {
		return 0 === strcasecmp( $this->host, $host );
	}
}
