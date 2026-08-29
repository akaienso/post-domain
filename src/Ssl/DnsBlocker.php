<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DnsBlocker {

	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly string $remedy,
		public readonly string $source
	) {}
}
