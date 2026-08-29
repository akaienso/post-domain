<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Mapping;

use PostDomain\Mapping\EventLog;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class EventLogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	public function test_an_event_records_and_reads_back(): void {
		EventLog::record( 7, 'example.test', 'verification', 'pending', 'verified', 'cron', array( 'outcome' => 'match' ) );

		$events = EventLog::for_domain( 7 );

		$this->assertCount( 1, $events );
		$this->assertSame( 'verification', $events[0]['type'] );
		$this->assertSame( 'example.test', $events[0]['host'] );
		$this->assertSame( 'match', json_decode( (string) $events[0]['detail'], true )['outcome'] );
	}

	public function test_recording_reports_whether_the_row_was_inserted(): void {
		$this->assertTrue( EventLog::record( 7, 'example.test', 'ssl', null, 'active', 'cron' ) );
	}

	public function test_the_host_snapshot_survives_the_row_it_describes(): void {
		EventLog::record( 8, 'gone.test', 'ssl', 'pending_removal', 'revoked', 'cron' );

		$this->assertSame( 'gone.test', EventLog::for_domain( 8 )[0]['host'] );
	}

	public function test_pruning_removes_only_events_past_retention(): void {
		global $wpdb;

		EventLog::record( 9, 'example.test', 'admin', null, null, 'admin:1' );

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE ' . Schema::events_table() . ' SET created_at = %s WHERE domain_id = 9', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS )
			)
		);

		EventLog::record( 10, 'fresh.test', 'admin', null, null, 'admin:1' );

		$this->assertSame( 1, EventLog::prune( 90 ) );
		$this->assertCount( 0, EventLog::for_domain( 9 ) );
		$this->assertCount( 1, EventLog::for_domain( 10 ) );
	}

	public function test_no_source_file_outside_the_event_log_reads_the_events_table(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			// Schema.php declares the accessor; EventLog.php is the only reader.
			if ( 'php' !== $file->getExtension()
				|| in_array( $file->getFilename(), array( 'EventLog.php', 'Schema.php' ), true ) ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $source, 'events_table()' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'events are audit-only: no decision may read them'
		);
	}
}
