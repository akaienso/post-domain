<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The Wordify operations this plugin uses, and no others.
 *
 * An interface rather than a concrete class so the provider can be tested
 * against a scripted client with no network at all. Every method answers with a
 * typed value object or a `WordifyFailure`; none of them ever hands back a raw
 * decoded response.
 */
interface WordifyClient {

	/** Whether the client has everything it needs to make a request at all. */
	public function is_ready(): bool;

	/** @return WordifyAccount|WordifyFailure */
	public function me();

	/**
	 * @param array<string, string> $filters Verified filters only, e.g. `domain`.
	 * @return WordifySiteList|WordifyFailure
	 */
	public function sites( array $filters = array() );

	/** @return WordifySite|WordifyFailure */
	public function site( string $site_id );

	/** @return WordifyDomainList|WordifyFailure */
	public function domains( string $site_id );

	/**
	 * Attaches a hostname to a site. Primary promotion is always off.
	 *
	 * @return WordifyDomain|WordifyFailure
	 */
	public function attach_domain( string $site_id, string $host );

	/**
	 * Refreshes DNS and SSL state for every domain on a site.
	 *
	 * Rate limited and side-effecting: it issues live DNS queries and can trigger
	 * a fresh Let's Encrypt issuance request, which is rate limited per
	 * registered domain per week. Never called from a registration or an
	 * identification path.
	 *
	 * @return WordifyDomainList|WordifyFailure
	 */
	public function recheck( string $site_id );
}
