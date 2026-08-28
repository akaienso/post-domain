<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Contracts\HttpClient;

final class WpHttpClient implements HttpClient {

	/**
	 * @param array<string, mixed> $opts
	 */
	public function request( string $method, string $url, array $opts = array() ): HttpResponse {
		$args = array_merge(
			array(
				'method'      => $method,
				'timeout'     => 10,
				'redirection' => 0,
			),
			$opts
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new HttpResponse( 0, array(), '', $response->get_error_message() );
		}

		$retrieved = wp_remote_retrieve_headers( $response );

		/** @var array<string, string> $headers */
		$headers = is_object( $retrieved ) && method_exists( $retrieved, 'getAll' )
			? $retrieved->getAll()
			: (array) $retrieved;

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers,
			(string) wp_remote_retrieve_body( $response )
		);
	}
}
