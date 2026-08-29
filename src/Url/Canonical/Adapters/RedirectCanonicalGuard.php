<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical\Adapters;

use PostDomain\Routing\ContextHolder;

/**
 * Filters core's proposal rather than removing the action, so trailing-slash,
 * pagination, and case corrections keep working.
 */
final class RedirectCanonicalGuard {

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		add_filter( 'redirect_canonical', array( $this, 'filter_proposal' ), 10, 2 );
	}

	/** @return string|false */
	public function filter_proposal( string $proposed, string $requested ) {
		$serving = $this->context->serving();

		if ( null === $serving ) {
			return $proposed;
		}

		$primary_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$proposed_host = (string) wp_parse_url( $proposed, PHP_URL_HOST );

		if ( $proposed_host === $primary_host ) {
			$proposed = (string) preg_replace(
				'~^(https?://)' . preg_quote( $primary_host, '~' ) . '~',
				'$1' . $serving->requested_host,
				$proposed
			);
		}

		return untrailingslashit( $proposed ) === untrailingslashit( $requested ) ? false : $proposed;
	}
}
