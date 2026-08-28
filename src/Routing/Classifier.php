<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Raw path only. No conditional tag is called: is_feed() and friends need query
 * vars that do not exist yet at plugins_loaded.
 */
final class Classifier {

	private const MANAGEMENT_NAMESPACE = 'post-domain/v1';

	public function __construct( private readonly string $rest_prefix ) {}

	/**
	 * @param array<string, mixed> $server
	 * @param array<string, mixed> $get
	 */
	public function classify( string $path, array $server, array $get ): EndpointClass {
		if ( 'cli' === ( $server['PD_SAPI'] ?? '' )
			|| ! isset( $server['HTTP_HOST'] )
			|| ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
			return EndpointClass::CLI;
		}

		$path = '/' . ltrim( parse_url( $path, PHP_URL_PATH ) ?? $path, '/' );

		if ( isset( $get['rest_route'] ) ) {
			return $this->rest_class( (string) $get['rest_route'] );
		}

		$prefix = '/' . trim( $this->rest_prefix, '/' ) . '/';

		if ( str_starts_with( $path, $prefix ) ) {
			return $this->rest_class( substr( $path, strlen( $prefix ) - 1 ) );
		}

		if ( '/wp-admin/admin-ajax.php' === $path ) {
			return EndpointClass::AJAX;
		}

		foreach (
			array(
				'/wp-login.php'         => EndpointClass::LOGIN,
				'/wp-comments-post.php' => EndpointClass::COMMENT_POST,
				'/wp-trackback.php'     => EndpointClass::TRACKBACK,
				'/xmlrpc.php'           => EndpointClass::XMLRPC,
				'/wp-cron.php'          => EndpointClass::CRON_HTTP,
				'/wp-signup.php'        => EndpointClass::INFRASTRUCTURE,
				'/wp-activate.php'      => EndpointClass::INFRASTRUCTURE,
				'/wp-links-opml.php'    => EndpointClass::INFRASTRUCTURE,
				'/wp-mail.php'          => EndpointClass::INFRASTRUCTURE,
				'/robots.txt'           => EndpointClass::WELL_KNOWN,
				'/favicon.ico'          => EndpointClass::WELL_KNOWN,
			) as $exact => $class
		) {
			if ( $exact === $path ) {
				return $class;
			}
		}

		if ( str_starts_with( $path, '/.well-known/' ) ) {
			return EndpointClass::WELL_KNOWN;
		}

		if ( str_starts_with( $path, '/wp-content/' ) || str_starts_with( $path, '/wp-includes/' ) ) {
			return EndpointClass::ASSET;
		}

		if ( 1 === preg_match( '~^/(wp-)?sitemap[^/]*\.xml$~', $path ) ) {
			return EndpointClass::SITEMAP;
		}

		if ( str_starts_with( $path, '/wp-admin/' ) ) {
			return EndpointClass::ADMIN;
		}

		return EndpointClass::ROUTED;
	}

	private function rest_class( string $route ): EndpointClass {
		$route = '/' . ltrim( $route, '/' );

		return str_starts_with( $route, '/' . self::MANAGEMENT_NAMESPACE )
			? EndpointClass::REST_MANAGEMENT
			: EndpointClass::REST_CONTENT;
	}
}
