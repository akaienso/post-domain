<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

use PostDomain\Plugin;
use PostDomain\Routing\ContextHolder;

/**
 * Serves the CORS probe page on a mapped host. It exists so the probe executes
 * with the mapped Origin: the server cannot produce that Origin itself, which is
 * the whole reason there is no server-side fetch.
 */
final class ProbeEndpoint {

	public const PATH = '/.well-known/post-domain-probe';

	public function __construct( private readonly ContextHolder $context ) {}

	/**
	 * Plan 11 Task 3 places this inside `Plugin::boot()`. It lives here so that
	 * `Plugin` gains no knowledge of the probe: `Admin\Wiring::register()` is the
	 * single line that reaches it.
	 */
	public static function boot(): void {
		add_action(
			'plugins_loaded',
			static function (): void {
				( new self( Plugin::instance()->context() ) )->register();
			},
			12
		);
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 2 );
	}

	public function maybe_serve(): void {
		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( self::PATH !== rtrim( $path, '/' ) || null === $this->context->serving() ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo self::page(); // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	/**
	 * A standalone document served outside the template stack: there is no
	 * `wp_head`, so `wp_enqueue_script()` has nothing to print into. The tag is
	 * written directly, and it is the only thing on the page.
	 */
	public static function page(): string {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
		return '<!doctype html><meta charset="utf-8"><title>post-domain probe</title>'
			. '<script src="' . esc_url( plugins_url( 'assets/probe.js', dirname( __DIR__, 2 ) . '/post-domain.php' ) )
			. '" defer></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}
}
