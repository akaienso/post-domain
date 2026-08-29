<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DnsRecordSpec {

	public function __construct(
		public readonly string $type,
		public readonly string $name,
		public readonly string $value,
		public readonly int $ttl = 300
	) {}
}
