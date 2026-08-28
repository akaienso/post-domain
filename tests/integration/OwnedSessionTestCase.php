<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Support\Schema;
use WP_UnitTestCase;

/**
 * A test case for behaviour that opens its own database transaction.
 *
 * `WP_UnitTestCase` wraps every test in a transaction so it can roll the test
 * back. `AtomicTransition` refuses to run inside a transaction it did not open
 * (spec §12.3) — which is correct in production, where the transaction would
 * belong to another plugin, and fatal here, where it belongs to the harness and
 * the test wants the write to happen.
 *
 * So this base class ends the harness transaction and cleans up by hand. The
 * production rule is unchanged and is asserted directly by
 * `AtomicTransitionTest::test_an_ambient_transaction_refuses_before_the_transition_runs()`.
 *
 * @package PostDomain
 */
abstract class OwnedSessionTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		// Hand the session back: from here the plugin owns its own transactions.
		// The suite also sets autocommit = 0, under which a transaction is always
		// implicitly open — so restoring autocommit is what actually makes the
		// session look like the production one this behaviour is written for.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'SET autocommit = 1' ); // phpcs:ignore WordPress.DB

		Schema::install();
		$this->truncate_plugin_tables();
	}

	public function tear_down(): void {
		global $wpdb;

		// Anything the test left open is not the harness's to roll back.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

		$this->truncate_plugin_tables();

		// Put the harness's own session settings back before it tears down.
		$wpdb->query( 'SET autocommit = 0' ); // phpcs:ignore WordPress.DB

		parent::tear_down();
	}

	protected function truncate_plugin_tables(): void {
		global $wpdb;

		foreach ( array( Schema::domains_table(), Schema::events_table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
