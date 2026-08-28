<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Verification\DnsResult;

interface DnsResolver {

	public function txt( string $name, string $expected ): DnsResult;
}
