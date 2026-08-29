<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Mapping\Mapping;

final class Guard {

	/** @return true|\WP_Error */
	public static function may_manage( \WP_REST_Request $request ) {
		$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', (string) $request->get_route() );
		$capability = '' === $capability ? 'manage_options' : $capability;

		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new \WP_Error(
			Errors::FORBIDDEN,
			__( 'You are not allowed to manage domain mappings.', 'post-domain' ),
			array( 'status' => 403 )
		);
	}

	public static function etag( Mapping $mapping ): string {
		return sprintf( '"%d-%d"', $mapping->id, $mapping->revision );
	}

	/** @return true|\WP_Error */
	public static function check_precondition( \WP_REST_Request $request, Mapping $mapping ) {
		$header = trim( (string) $request->get_header( 'if_match' ) );

		if ( '' === $header ) {
			return new \WP_Error(
				Errors::PRECONDITION_REQUIRED,
				__( 'This request requires an If-Match header carrying the current ETag.', 'post-domain' ),
				array( 'status' => 428 )
			);
		}

		if ( self::etag( $mapping ) !== $header ) {
			return new \WP_Error(
				Errors::PRECONDITION_FAILED,
				__( 'The mapping changed since you read it.', 'post-domain' ),
				array( 'status' => 412 )
			);
		}

		return true;
	}
}
