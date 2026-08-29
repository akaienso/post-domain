<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DriverCapabilities {

	/** @param string[] $validation_methods */
	public function __construct(
		public readonly bool $supports_markers,
		public readonly array $validation_methods,
		public readonly bool $supports_apex_proxy_targets
	) {}
}
