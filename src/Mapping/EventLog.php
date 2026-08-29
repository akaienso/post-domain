<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Support\Schema;

/**
 * Append-only support artifact. Nothing reads this to make a decision, which is
 * why pruning it is always safe.
 */
final class EventLog {

	/**
	 * @param array<string, mixed> $detail
	 */
	public static function record(
		int $domain_id,
		string $host,
		string $type,
		?string $from = null,
		?string $to = null,
		?string $actor = null,
		array $detail = array()
	): bool {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB
			Schema::events_table(),
			array(
				'domain_id'  => $domain_id,
				'host'       => $host,
				'type'       => $type,
				'from_state' => $from,
				'to_state'   => $to,
				'actor'      => $actor,
				'detail'     => array() === $detail ? null : (string) wp_json_encode( $detail ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return false !== $inserted;
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	public static function for_domain( int $domain_id ): array {
		global $wpdb;

		$table = Schema::events_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE domain_id = %d ORDER BY created_at ASC", $domain_id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return $rows;
	}

	public static function prune( int $retention_days ): int {
		global $wpdb;

		$table  = Schema::events_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB
		);
	}
}
