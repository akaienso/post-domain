<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\Mapping;

final class QueryVarPolicy {

	public const DEFAULTS = array( 'paged', 'page', 'cpage', 'replytocom', 'feed', 'embed' );

	/** Never reachable through the filter: these steer WP_Query itself. */
	public const RESERVED = array(
		'p',
		'page_id',
		'name',
		'pagename',
		'post_type',
		'attachment',
		'attachment_id',
		'static',
		'error',
		'preview',
		'preview_id',
		'preview_nonce',
		'post_status',
		'rest_route',
	);

	/** @return string[] */
	public static function preserved( Mapping $mapping ): array {
		/** @var string[] $vars */
		$vars = (array) apply_filters( 'pd_preserved_query_vars', self::DEFAULTS, $mapping );

		$vars = array_filter(
			$vars,
			static fn( $name ): bool => is_string( $name ) && 1 === preg_match( '/^[a-z0-9_]{1,32}$/', $name )
		);

		$vars = array_diff( $vars, self::RESERVED );

		return array_values( array_unique( $vars ) );
	}
}
