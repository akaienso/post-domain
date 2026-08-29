<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Runs at plugins_loaded : 1, before the admin redirect. Its position is not
 * filterable; only the allowlist is data.
 */
final class UnknownHostGuard {

	public const POLICIES = array( '421', 'passthrough' );

	private string $policy;

	public function __construct( string $policy ) {
		$this->policy = in_array( $policy, self::POLICIES, true ) ? $policy : '421';
	}

	public function response_for( HostContext $context ): ?int {
		if ( EndpointClass::CLI === $context->endpoint || EndpointClass::CRON === $context->endpoint ) {
			return null;
		}

		if ( HostKind::MALFORMED === $context->kind ) {
			return 400;
		}

		if ( HostKind::UNKNOWN === $context->kind && '421' === $this->policy ) {
			return 421;
		}

		return null;
	}
}
