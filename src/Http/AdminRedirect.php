<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;

/**
 * Default policy, not an invariant. What is invariant is the cookie boundary
 * underneath: auth cookies bind to COOKIE_DOMAIN and are never shared.
 */
final class AdminRedirect {

	public function __construct(
		private readonly string $primary_origin,
		private readonly bool $enabled
	) {}

	/**
	 * @return array{url: string, status: int}|null
	 */
	public function redirect_for( HostContext $context, string $request_uri ): ?array {
		if ( ! $this->enabled || HostKind::PRIMARY === $context->kind ) {
			return null;
		}

		if ( ! in_array( $context->endpoint, array( EndpointClass::ADMIN, EndpointClass::LOGIN ), true ) ) {
			return null;
		}

		$idempotent = in_array( $context->method, array( 'GET', 'HEAD' ), true );

		return array(
			'url'    => rtrim( $this->primary_origin, '/' ) . '/' . ltrim( $request_uri, '/' ),
			'status' => $idempotent ? 302 : 307,
		);
	}
}
