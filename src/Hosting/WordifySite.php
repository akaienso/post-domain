<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One hosting site.
 *
 * `id` is the 26-character base32 ULID the verified tool schemas describe;
 * `provisioning_status` is one of the six values they enumerate. Nothing else
 * about the record shape is verified, so nothing else is modelled.
 */
final class WordifySite {

	public function __construct(
		public readonly string $id,
		public readonly ?string $provisioning_status
	) {}
}
