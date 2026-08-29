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
		/** @var mixed $filtered */
		$filtered = apply_filters(
			'pd_doh_endpoints',
			array( 'https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve' )
		);

		// The filtered list is passed through exactly as given, minus anything that
		// is not a string. It is deliberately NOT topped back up from the defaults
		// when it is short: an operator who narrows this list to one endpoint gets
		// a resolver that can only ever say TRANSIENT (DohResolver enforces the
		// two-distinct-endpoint rule), which is the safe reading of the
		// instruction. Silently restoring a second endpoint would both ignore the
		// filter and manufacture the corroboration the operator removed.
		$endpoints = array_values( array_filter( (array) $filtered, 'is_string' ) );

		$default = new DohResolver( new WpHttpClient(), $endpoints );

		/** @var mixed $resolver */
		$resolver = apply_filters( 'pd_dns_resolver', $default );

		return $resolver instanceof DnsResolver ? $resolver : $default;
	}
}
