<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Ssl\Reconciler;
use PostDomain\Support\Schema;

/**
 * The daily `pd_maintenance` pass (spec §13.6): event pruning, orphan-alias
 * check, dangling-target scan, and a full `Reconciler` pass.
 *
 * Three of the four are **diagnostics**. They read, count, and record an event;
 * they never delete or repair a row on the strength of what they found. A scan
 * that finds an alias pointing at a missing target has found either a real
 * stray or a row someone is mid-way through writing, and it cannot tell which —
 * so it reports, and a human (or the repository's own delete path, which owns
 * orphan cleanup) acts. Only pruning deletes, and only the event log, which is
 * never a decision input (§12.3).
 *
 * Every scan is bounded by `pd_maintenance_scan_limit` so a large table cannot
 * turn the daily tick into a timeout.
 *
 * It lives under Verification because that is where the cron topology for these
 * hooks lives; the duties themselves span subsystems.
 */
final class Maintenance {

	private const DEFAULT_SCAN_LIMIT = 500;

	private const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * @return array{pruned: int, orphan_aliases: int, dangling_targets: int, reconciled: array{updated: int, divergences: int, skipped: int, drifted: int}}
	 */
	public static function run(): array {
		$pruned    = self::prune_events();
		$orphans   = self::orphan_aliases();
		$dangling  = self::dangling_targets();
		$reconcile = Reconciler::run( ( new DbRepository() )->all() );

		$summary = array(
			'pruned'           => $pruned,
			'orphan_aliases'   => count( $orphans ),
			'dangling_targets' => count( $dangling ),
			'reconciled'       => $reconcile,
		);

		EventLog::record( 0, '', 'maintenance', null, null, 'cron', $summary );

		return $summary;
	}

	private static function scan_limit(): int {
		$limit = (int) apply_filters( 'pd_maintenance_scan_limit', self::DEFAULT_SCAN_LIMIT );

		return max( 1, min( 5000, $limit ) );
	}

	private static function prune_events(): int {
		$days = (int) apply_filters( 'pd_event_retention_days', self::DEFAULT_RETENTION_DAYS );

		return EventLog::prune( max( 1, $days ) );
	}

	/**
	 * Aliases whose `alias_of` names a row that is not there.
	 *
	 * `alias_of` is a self-reference with no foreign key — dbDelta cannot express
	 * one portably (§12.1) — so nothing at the database level stops a stray. This
	 * finds them and says so.
	 *
	 * @return array<int, array{id: int, host: string, alias_of: int}>
	 */
	private static function orphan_aliases(): array {
		global $wpdb;

		$table = Schema::domains_table();

		// Two queries rather than a self-join: the WordPress test harness rewrites
		// plugin tables to TEMPORARY, which MySQL cannot open twice in one
		// statement. The bound is on the candidate rows scanned.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, host, alias_of
				   FROM {$table}
				  WHERE alias_of IS NOT NULL
				  ORDER BY id ASC
				  LIMIT %d",
				self::scan_limit()
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$targets = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['alias_of'], $rows ) ) );

		if ( array() === $targets ) {
			return array();
		}

		// Only the targets the candidates actually name, so the second read is
		// bounded by the first.
		$placeholders = implode( ',', array_fill( 0, count( $targets ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table() and $placeholders is a generated %d list, never caller input.
		/** @var array<int, string> $ids */
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$placeholders})", $targets ) // phpcs:ignore WordPress.DB
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$present = array_flip( array_map( 'intval', $ids ) );

		$rows = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => ! isset( $present[ (int) $row['alias_of'] ] )
			)
		);

		$found = array();

		foreach ( $rows as $row ) {
			$entry = array(
				'id'       => (int) $row['id'],
				'host'     => (string) $row['host'],
				'alias_of' => (int) $row['alias_of'],
			);

			$found[] = $entry;

			// Reported, never repaired: deleting an alias because its target was
			// missing at read time would destroy a mapping on the strength of a
			// diagnostic.
			EventLog::record(
				$entry['id'],
				$entry['host'],
				'maintenance',
				null,
				null,
				'cron',
				array(
					'integrity'     => 'orphan_alias',
					'alias_of'      => $entry['alias_of'],
					'auto_repaired' => false,
				)
			);
		}

		return $found;
	}

	/**
	 * Non-alias mappings whose `post_id` names a post that no longer exists.
	 *
	 * @return array<int, array{id: int, host: string, post_id: int}>
	 */
	private static function dangling_targets(): array {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT d.id, d.host, d.post_id
				   FROM {$table} d
				   LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
				  WHERE d.post_id IS NOT NULL AND p.ID IS NULL
				  ORDER BY d.id ASC
				  LIMIT %d",
				self::scan_limit()
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$found = array();

		foreach ( $rows as $row ) {
			$entry = array(
				'id'      => (int) $row['id'],
				'host'    => (string) $row['host'],
				'post_id' => (int) $row['post_id'],
			);

			$found[] = $entry;

			// A missing target makes the mapping unservable, which the routing
			// layer already handles. It does not make the mapping disposable: the
			// post may be restored from the trash, and the host, challenge, and
			// ownership provenance on this row are not reproducible.
			EventLog::record(
				$entry['id'],
				$entry['host'],
				'maintenance',
				null,
				null,
				'cron',
				array(
					'integrity'     => 'dangling_target',
					'post_id'       => $entry['post_id'],
					'auto_repaired' => false,
				)
			);
		}

		return $found;
	}
}
