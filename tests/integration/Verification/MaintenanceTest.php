<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Support\Schema;
use PostDomain\Verification\Maintenance;
use WP_UnitTestCase;

/**
 * `pd_maintenance` and its bounded duties (spec §13.6).
 */
final class MaintenanceTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	private function seed( string $host, ?int $post_id, ?int $alias_of = null ): int {
		return ( new DbRepository() )->save(
			new Mapping(
				0,
				$host,
				$alias_of,
				$post_id,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				substr( md5( $host ), 0, 32 ),
				'_post-domain-challenge'
			)
		)->id;
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	private function maintenance_events( int $domain_id ): array {
		return array_values(
			array_filter(
				EventLog::for_domain( $domain_id ),
				static fn( array $event ): bool => 'maintenance' === $event['type']
			)
		);
	}

	public function test_the_hook_has_a_listener(): void {
		Plugin::boot();

		$this->assertNotFalse(
			has_action( 'pd_maintenance' ),
			'pd_maintenance is scheduled, so something has to answer it'
		);
	}

	public function test_expired_events_are_pruned_and_recent_ones_are_kept(): void {
		global $wpdb;

		$id = $this->seed( 'prune.test', self::factory()->post->create() );

		EventLog::record( $id, 'prune.test', 'verification', null, 'pending', 'cron' );
		EventLog::record( $id, 'prune.test', 'verification', null, 'verified', 'cron' );

		$events = Schema::events_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $events is Schema::events_table(), never caller input.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$events} SET created_at = %s WHERE to_state = 'pending'",
				gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$summary = Maintenance::run();

		$this->assertSame( 1, $summary['pruned'] );

		$states = array_map(
			static fn( array $event ): ?string => $event['to_state'],
			EventLog::for_domain( $id )
		);

		$this->assertNotContains( 'pending', $states, 'the aged event is gone' );
		$this->assertContains( 'verified', $states, 'the recent event is kept' );
	}

	public function test_an_orphan_alias_is_reported_and_not_repaired(): void {
		global $wpdb;

		$target = $this->seed( 'target.test', self::factory()->post->create() );
		$alias  = $this->seed( 'alias.test', null, $target );

		// Remove the target behind the repository's back, which is exactly the
		// stray the check exists to find.
		$wpdb->delete( Schema::domains_table(), array( 'id' => $target ) ); // phpcs:ignore WordPress.DB

		$summary = Maintenance::run();

		$this->assertSame( 1, $summary['orphan_aliases'] );
		$this->assertNotNull(
			( new DbRepository() )->by_id( $alias ),
			'a diagnostic must not delete the row it found'
		);

		$events = $this->maintenance_events( $alias );

		$this->assertCount( 1, $events );
		$this->assertSame(
			'orphan_alias',
			json_decode( (string) $events[0]['detail'], true )['integrity'] ?? null
		);
	}

	public function test_a_dangling_target_is_reported_and_not_repaired(): void {
		$post = self::factory()->post->create();
		$id   = $this->seed( 'dangling.test', $post );

		wp_delete_post( $post, true );

		$summary = Maintenance::run();

		$this->assertSame( 1, $summary['dangling_targets'] );
		$this->assertNotNull(
			( new DbRepository() )->by_id( $id ),
			'an unservable mapping is not a disposable one'
		);

		$events = $this->maintenance_events( $id );

		$this->assertCount( 1, $events );
		$this->assertSame(
			'dangling_target',
			json_decode( (string) $events[0]['detail'], true )['integrity'] ?? null
		);
	}

	public function test_a_healthy_table_reports_nothing_and_reconciles(): void {
		$this->seed( 'healthy.test', self::factory()->post->create() );

		$summary = Maintenance::run();

		$this->assertSame( 0, $summary['orphan_aliases'] );
		$this->assertSame( 0, $summary['dangling_targets'] );
		$this->assertArrayHasKey( 'updated', $summary['reconciled'] );
		$this->assertArrayHasKey( 'divergences', $summary['reconciled'] );

		// The whole pass is summarized on the installation-wide row.
		$this->assertNotEmpty( $this->maintenance_events( 0 ) );
	}

	public function test_the_scans_are_bounded(): void {
		global $wpdb;

		$target = $this->seed( 'bounded-target.test', self::factory()->post->create() );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->seed( "bounded-{$i}.test", null, $target );
		}

		$wpdb->delete( Schema::domains_table(), array( 'id' => $target ) ); // phpcs:ignore WordPress.DB

		$cap     = static fn(): int => 2;
		add_filter( 'pd_maintenance_scan_limit', $cap );
		$summary = Maintenance::run();
		remove_filter( 'pd_maintenance_scan_limit', $cap );

		$this->assertSame( 2, $summary['orphan_aliases'], 'the scan stops at the cap' );
	}
}
