<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class SchemaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/** @return string[] */
	private function columns( string $table ): array {
		global $wpdb;

		/** @var string[] $names */
		$names = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB

		return $names;
	}

	public function test_both_tables_exist(): void {
		global $wpdb;

		// SHOW TABLES cannot be used here: WP_UnitTestCase rewrites CREATE TABLE
		// to CREATE TEMPORARY TABLE for the duration of a test, and temporary
		// tables are absent from SHOW TABLES. Describing the table is the check
		// that works for both.
		foreach ( array( Schema::domains_table(), Schema::events_table() ) as $table ) {
			$wpdb->last_error = '';
			$columns          = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB

			$this->assertSame( '', $wpdb->last_error, "table {$table} does not exist" );
			$this->assertNotEmpty( $columns, "table {$table} has no columns" );
		}
	}

	public function test_the_domains_table_carries_every_specified_column(): void {
		$columns = $this->columns( Schema::domains_table() );

		foreach (
			array(
				'id', 'host', 'alias_of', 'post_id', 'revision',
				'verification_state', 'activation_state', 'ssl_state', 'integrity_error',
				'challenge', 'challenge_label', 'challenge_rotated_at', 'verified_at',
				'last_checked_at', 'last_outcome', 'hard_failure_count', 'transient_failure_count',
				'verification_deadline', 'verify_next_attempt_at', 'verify_lease_token',
				'verify_lease_expires_at', 'resolver_class',
				'ssl_provider', 'ssl_ref', 'ssl_ownership_origin', 'ssl_owner_installation_id',
				'ssl_adopted_at', 'ssl_adopted_by', 'ssl_method', 'ssl_method_requested_at',
				'ssl_provider_environment', 'ssl_marker_support', 'ssl_checked_at', 'ssl_next_attempt_at',
				'ssl_transient_count', 'ssl_provider_state', 'ssl_error',
				'ssl_mutation_token', 'ssl_mutation_kind', 'ssl_mutation_phase',
				'ssl_mutation_expires_at', 'ssl_mutation_driver', 'ssl_mutation_environment',
				'deletion_requested_at', 'deletion_attempts', 'deletion_next_attempt_at',
				'title', 'favicon_attachment_id', 'created_at', 'updated_at', 'created_by',
			) as $column
		) {
			$this->assertContains( $column, $columns, "missing column {$column}" );
		}
	}

	public function test_host_is_230_bytes_of_ascii_bin(): void {
		global $wpdb;

		// INFORMATION_SCHEMA does not list temporary tables either (see above), so
		// the column metadata comes from SHOW FULL COLUMNS.
		/** @var array<int, array<string, string|null>> $columns */
		$columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . Schema::domains_table(), ARRAY_A ); // phpcs:ignore WordPress.DB
		$host    = null;

		foreach ( $columns as $column ) {
			if ( 'host' === $column['Field'] ) {
				$host = $column;
			}
		}

		$this->assertNotNull( $host, 'the domains table has a host column' );
		$this->assertSame( 'varchar(230)', strtolower( (string) $host['Type'] ) );
		$this->assertSame( 'ascii_bin', (string) $host['Collation'] );
	}

	public function test_host_and_challenge_are_unique(): void {
		global $wpdb;

		/** @var array<int, array<string, string>> $indexes */
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::domains_table(), ARRAY_A ); // phpcs:ignore WordPress.DB
		$unique  = array();

		foreach ( $indexes as $index ) {
			if ( '0' === (string) $index['Non_unique'] ) {
				$unique[] = $index['Key_name'];
			}
		}

		$this->assertContains( 'host', $unique );
		$this->assertContains( 'challenge', $unique );
	}

	public function test_installing_twice_is_idempotent(): void {
		Schema::install();
		Schema::install();

		$this->assertCount( 51, $this->columns( Schema::domains_table() ) );
	}

	public function test_the_schema_version_is_recorded(): void {
		$this->assertSame( Schema::VERSION, (int) get_option( 'pd_schema_version' ) );
	}

	public function test_the_engine_is_recorded(): void {
		$this->assertNotSame( '', Schema::engine() );
	}
}
