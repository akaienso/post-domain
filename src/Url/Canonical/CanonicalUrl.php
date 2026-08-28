<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical;

final class CanonicalUrl {

	public function __construct( public readonly string $url ) {}
}
