<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DnsRequirementSet {

	/** @param DnsRecordSpec[] $records */
	public function __construct(
		public readonly string $purpose,
		public readonly string $id,
		public readonly string $label,
		public readonly array $records,
		public readonly bool $apex_compatible,
		public readonly string $source,
		public readonly bool $removable_once_active = false
	) {}
}
