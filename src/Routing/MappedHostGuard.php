<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Computed once at init : 99, enforced at parse_request : 0. A missing
 * ServingContext is never a fall-through: every path ends in a named outcome.
 */
final class MappedHostGuard {

	public static function decide(
		HostContext $context,
		?ServingEligibility $eligibility,
		?ServingContext $serving,
		string $unknown_policy
	): Disposition {
		$guard  = new UnknownHostGuard( $unknown_policy );
		$status = $guard->response_for( $context );

		if ( 400 === $status ) {
			return Disposition::MALFORMED_400;
		}

		if ( 421 === $status ) {
			return Disposition::UNKNOWN_421;
		}

		if ( HostKind::MAPPED !== $context->kind ) {
			return Disposition::PRIMARY;
		}

		if ( null === $eligibility || ! $eligibility->is_active ) {
			return Disposition::NOT_SERVING_404;
		}

		if ( null === $serving ) {
			return Disposition::BROKEN_503;
		}

		return Disposition::SERVE;
	}
}
