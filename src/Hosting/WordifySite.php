<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One hosting site.
 *
 * `id` is the 26-character base32 ULID observed in real responses, and is
 * treated as an opaque string throughout. The display fields exist so an
 * operator choosing between hundreds of sites can recognise theirs; none of
 * them is ever used to decide anything.
 */
final class WordifySite {

	public function __construct(
		public readonly string $id,
		public readonly ?string $provisioning_status,
		public readonly ?string $display_name = null,
		public readonly ?string $name = null,
		public readonly ?string $domain = null,
		public readonly ?bool $is_staging = null
	) {}

	/** What to call this site on screen. Never the id alone if anything better exists. */
	public function label(): string {
		foreach ( array( $this->display_name, $this->name, $this->domain ) as $candidate ) {
			if ( null !== $candidate && '' !== $candidate ) {
				return $candidate;
			}
		}

		return $this->id;
	}
}
