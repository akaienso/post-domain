<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Support\HttpResponse;

interface HttpClient {

	/**
	 * @param array<string, mixed> $opts
	 */
	public function request( string $method, string $url, array $opts = array() ): HttpResponse;
}
