<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class GateResult {

	/** @param SslStatus|RemovalResult $result */
	public function __construct(
		public readonly object $result,
		public readonly LeaseOwner $owner,
		public readonly MutationOperation $operation
	) {}
}
