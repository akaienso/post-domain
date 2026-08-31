<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class Schema {

	public const VERSION = 3;

	public static function domains_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pd_domains';
	}

	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pd_domain_events';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$domains = self::domains_table();
		$events  = self::events_table();

		dbDelta(
			"CREATE TABLE {$domains} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				host varchar(230) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				alias_of bigint(20) unsigned NULL,
				post_id bigint(20) unsigned NULL,
				revision int(10) unsigned NOT NULL DEFAULT 1,
				verification_state varchar(20) NOT NULL DEFAULT 'unverified',
				activation_state varchar(20) NOT NULL DEFAULT 'inactive',
				ssl_state varchar(20) NOT NULL DEFAULT 'none',
				integrity_error varchar(60) NULL,
				challenge char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				challenge_label varchar(63) NOT NULL,
				challenge_rotated_at datetime NULL,
				verified_at datetime NULL,
				last_checked_at datetime NULL,
				last_outcome varchar(20) NULL,
				hard_failure_count smallint(5) unsigned NOT NULL DEFAULT 0,
				transient_failure_count smallint(5) unsigned NOT NULL DEFAULT 0,
				verification_deadline datetime NULL,
				verify_next_attempt_at datetime NULL,
				verify_lease_token char(32) NULL,
				verify_lease_expires_at datetime NULL,
				resolver_class varchar(191) NULL,
				ssl_provider varchar(60) NULL,
				ssl_ref varchar(191) NULL,
				ssl_ownership_origin varchar(10) NULL,
				ssl_owner_installation_id char(36) NULL,
				ssl_adopted_at datetime NULL,
				ssl_adopted_by bigint(20) unsigned NULL,
				ssl_method varchar(10) NULL,
				ssl_method_requested_at datetime NULL,
				ssl_marker_support varchar(20) NULL,
				ssl_checked_at datetime NULL,
				ssl_next_attempt_at datetime NULL,
				ssl_transient_count smallint(5) unsigned NOT NULL DEFAULT 0,
				ssl_provider_state text NULL,
				ssl_error text NULL,
				ssl_mutation_token char(32) NULL,
				ssl_mutation_kind varchar(20) NULL,
				ssl_mutation_phase varchar(20) NULL,
				ssl_mutation_expires_at datetime NULL,
				ssl_provider_environment varchar(190) NULL,
				ssl_mutation_driver varchar(60) NULL,
				ssl_mutation_environment varchar(190) NULL,
				deletion_requested_at datetime NULL,
				ssl_removal_scope varchar(20) NULL,
				hosting_provider varchar(60) NULL,
				hosting_environment varchar(190) NULL,
				hosting_ref varchar(190) NULL,
				hosting_state varchar(40) NULL,
				hosting_registered_at datetime NULL,
				deletion_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				deletion_next_attempt_at datetime NULL,
				title varchar(255) NULL,
				favicon_attachment_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				created_by bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY host (host),
				UNIQUE KEY challenge (challenge),
				KEY post_id (post_id),
				KEY alias_of (alias_of),
				KEY verify_due (verification_state, verify_next_attempt_at),
				KEY ssl_due (ssl_state, ssl_next_attempt_at),
				KEY deletion_due (deletion_next_attempt_at),
				KEY ssl_lease (ssl_mutation_expires_at)
			) {$charset} ENGINE=InnoDB"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				domain_id bigint(20) unsigned NOT NULL,
				host varchar(230) NOT NULL,
				type varchar(40) NOT NULL,
				from_state varchar(20) NULL,
				to_state varchar(20) NULL,
				actor varchar(60) NULL,
				detail longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY domain_created (domain_id, created_at)
			) {$charset} ENGINE=InnoDB"
		);

		update_option( 'pd_schema_version', self::VERSION, false );
		update_option( 'pd_schema_engine', self::probe_engine(), false );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'pd_schema_version', 0 ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	public static function engine(): string {
		return (string) get_option( 'pd_schema_engine', '' );
	}

	/**
	 * Which engine the domains table actually ended up on.
	 *
	 * INFORMATION_SCHEMA is asked first, then SHOW CREATE TABLE. The fallback is
	 * not redundant: INFORMATION_SCHEMA lists neither temporary tables nor, on
	 * some restricted hosts, tables this user lacks privileges to see, and
	 * answering 'unknown' there would silently drop the event-atomicity guarantee
	 * on a database that in fact supports it (spec §12.3).
	 */
	private static function probe_engine(): string {
		global $wpdb;

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				self::domains_table()
			)
		);

		if ( null !== $engine && '' !== $engine ) {
			return (string) $engine;
		}

		$created = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::domains_table(), ARRAY_N ); // phpcs:ignore WordPress.DB

		if ( is_array( $created ) && isset( $created[1] ) && 1 === preg_match( '/ENGINE=(\w+)/i', (string) $created[1], $m ) ) {
			return $m[1];
		}

		return 'unknown';
	}
}
