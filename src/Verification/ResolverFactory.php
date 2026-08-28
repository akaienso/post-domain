<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Support\WpHttpClient;

/**
 * The one place the configured DNS resolver is built.
 *
 * The default is DoH with two independent endpoints. A replacement installed
 * through `pd_dns_resolver` substitutes the ownership proof mechanism outright
 * (spec §13.3), so anything that is not a `DnsResolver` is ignored rather than
 * trusted. REST and cron resolve DNS through here so the two cannot disagree
 * about what counts as proof.
 */
final class ResolverFactory {

	public static function from_filters(): DnsResolver {
		/** @var string[] $endpoints */
		$endpoints = (array) apply_filters(
			'pd_doh_endpoints',
			array( 'https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve' )
		);

		$default = new DohResolver( new WpHttpClient(), $endpoints );

		/** @var mixed $resolver */
		$resolver = apply_filters( 'pd_dns_resolver', $default );

		return $resolver instanceof DnsResolver ? $resolver : $default;
	}
}
