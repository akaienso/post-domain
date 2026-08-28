<?php
declare( strict_types = 1 );

$pd_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once __DIR__ . '/../vendor/autoload.php';
require_once $pd_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/post-domain.php';
	}
);

require $pd_tests_dir . '/includes/bootstrap.php';
