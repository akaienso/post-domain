<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

/**
 * Thrown instead of `exit` when a test drives an admin action.
 *
 * `exit` is correct in a request and impossible to assert against, so the
 * redirect is expressed as a control-flow exception the harness can catch. The
 * filter that enables it defaults to off, so nothing in a real request changes.
 */
final class RedirectedAway extends \RuntimeException {

	public function __construct( public readonly string $url ) {
		parent::__construct( 'Redirected to ' . $url );
	}
}
