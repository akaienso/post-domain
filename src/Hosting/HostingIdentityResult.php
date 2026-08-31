<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * What the provider says exists for a hostname, right now.
 *
 * `read_complete` is the load-bearing field. A read that failed is not evidence
 * of absence, and an ambiguous write is resolved by reading — never by
 * repeating the write — so a provider that could not answer must say so rather
 * than return an empty result that looks like "nothing is there".
 */
final class HostingIdentityResult {

	private function __construct(
		public readonly bool $read_complete,
		public readonly bool $attached,
		public readonly ?string $attached_site_id,
		public readonly bool $is_primary,
		public readonly ?string $reference,
		public readonly ?string $reason
	) {}

	public static function attached( string $site_id, ?string $reference, bool $is_primary = false ): self {
		return new self( true, true, $site_id, $is_primary, $reference, null );
	}

	public static function absent(): self {
		return new self( true, false, null, false, null, null );
	}

	/** The provider could not be read. Says nothing about what exists. */
	public static function unknown( string $reason ): self {
		return new self( false, false, null, false, null, $reason );
	}
}
