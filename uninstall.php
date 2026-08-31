<?php
/**
 * Removes the plugin's own data and nothing else.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

$pd_tables = array(
	\PostDomain\Support\Schema::domains_table(),
	\PostDomain\Support\Schema::events_table(),
);

foreach ( $pd_tables as $pd_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$pd_table}" ); // phpcs:ignore WordPress.DB
}

$pd_options = array(
	'pd_schema_version',
	'pd_schema_engine',
	'pd_settings',
	'pd_ssl_credentials',
	'pd_installation_id',
	'pd_installation_primary_host',
	'pd_environment_mismatch',
	'pd_provider_cooldowns',
);

foreach ( $pd_options as $pd_option ) {
	delete_option( $pd_option );
}

// One option per mapping that was tested, so they are removed by pattern rather
// than by name. Leaving them would outlive the tables they describe.
\PostDomain\Admin\OriginConfirmation::forget_all();

$pd_hooks = array(
	'pd_verify_pending',
	'pd_verify_established',
	'pd_ssl_sweep',
	'pd_maintenance',
);

foreach ( $pd_hooks as $pd_hook ) {
	wp_clear_scheduled_hook( $pd_hook );
}
