<?php
/**
 * Plugin Name: post-domain
 * Description: Maps a domain name to a single post, resolved rather than redirected.
 * x-release-please-start-version
 * Version:     1.0.1
 * x-release-please-end
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// 1. The autoloader, so the activation guard below can be resolved.
require_once __DIR__ . '/vendor/autoload.php';

// 2. The activation hook, registered BEFORE the multisite return so that the
//    refusal exists on exactly the installs it has to refuse.
register_activation_hook( __FILE__, array( \PostDomain\Support\Activation::class, 'guard' ) );

// 3. Inert on multisite: no hooks at all, one notice.
if ( is_multisite() ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html( 'post-domain is inactive: it does not support multisite networks.' )
				. '</p></div>';
		}
	);

	return;
}

$pd_blocker = \PostDomain\Support\Environment::blocker( PHP_VERSION, get_bloginfo( 'version' ), false );

if ( null !== $pd_blocker ) {
	add_action(
		'admin_notices',
		static function () use ( $pd_blocker ): void {
			echo '<div class="notice notice-error"><p>' . esc_html( $pd_blocker ) . '</p></div>';
		}
	);

	return;
}

// 4. The composition root: hooks are registered only past every refusal above.
\PostDomain\Plugin::boot();

if ( ! function_exists( 'pd_with_mapping' ) ) {
	/**
	 * Runs a callback with a mapping's serving context in scope.
	 *
	 * @param int      $mapping_id The mapping to borrow.
	 * @param callable $callback         The callback.
	 * @return mixed The callback's return value.
	 */
	function pd_with_mapping( int $mapping_id, callable $callback ) {
		return \PostDomain\Support\BackgroundContext::run( $mapping_id, $callback );
	}
}
