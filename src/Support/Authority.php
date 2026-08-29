<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class Authority {

	public function __construct(
		public readonly string $host,
		public readonly ?int $port,
		public readonly bool $is_ipv6_literal,
		public readonly string $bracketed_form
	) {}
}
