<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class UnmatchedPolicy {

	public const MODES = array( 'redirect', '404', 'passthrough' );

	private string $mode;

	public function __construct( string $mode, private readonly string $primary_origin ) {
		$this->mode = in_array( $mode, self::MODES, true ) ? $mode : 'redirect';
	}

	/**
	 * @return array{url?: string, status: int}|null
	 */
	public function response_for( string $method, string $request_uri ): ?array {
		if ( 'passthrough' === $this->mode ) {
			return null;
		}

		if ( '404' === $this->mode ) {
			return array( 'status' => 404 );
		}

		if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			// A POST is never bounced across hosts.
			return array( 'status' => 404 );
		}

		return array(
			'url'    => rtrim( $this->primary_origin, '/' ) . '/' . ltrim( $request_uri, '/' ),
			'status' => 302,
		);
	}
}
