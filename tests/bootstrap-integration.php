<?php
declare( strict_types = 1 );

$pd_tests_env = getenv( 'WP_TESTS_DIR' );
$pd_tests_dir = is_string( $pd_tests_env ) && '' !== $pd_tests_env ? $pd_tests_env : '/wordpress-phpunit';

require_once __DIR__ . '/../vendor/autoload.php';
require_once $pd_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/post-domain.php';
	}
);

require $pd_tests_dir . '/includes/bootstrap.php';

/**
 * The plugin boots at `muplugins_loaded`, before WP_UnitTestCase installs the
 * filter that makes CREATE TABLE temporary — so a schema upgrade during boot
 * leaves PERSISTENT plugin tables and options behind, and the next run starts
 * dirty. Drop them once, here, so every run starts from the same state.
 */
global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pd_domains' ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pd_domain_events' ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pd\_%'" ); // phpcs:ignore WordPress.DB
