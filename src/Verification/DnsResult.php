<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

final class DnsResult {

	/** @param string[] $values */
	public function __construct(
		public readonly DnsOutcome $outcome,
		public readonly array $values = array(),
		public readonly ?string $error = null
	) {}
}
