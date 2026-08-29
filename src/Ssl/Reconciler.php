<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;
use PostDomain\Verification\Challenge;

/**
 * Adopts provider truth for state only: it never deletes at the provider, never
 * adopts ownership, never auto-patches a divergent method, and skips leased rows.
 */
final class Reconciler {

	/**
	 * @param Mapping[] $mappings
	 * @return array{updated: int, divergences: int, skipped: int, drifted: int}
	 */
	public static function run( array $mappings ): array {
		$totals = array(
			'updated'     => 0,
			'divergences' => 0,
			'skipped'     => 0,
			'drifted'     => 0,
		);

		/** @var array<string, array{driver: SslDriver, mappings: Mapping[]}> $groups */
		$groups = array();

		foreach ( $mappings as $mapping ) {
			// A leased row belongs to whoever holds the lease. Reconciliation
			// never writes through one, so it does not even read one here.
			if ( null !== $mapping->ssl_mutation_token ) {
				++$totals['skipped'];

				continue;
			}

			// BoundResource, not DriverFactory: a bound row whose driver now points
			// at a different account must not be asked about, and the wrong
			// account's answer must not become local state.
			$driver = BoundResource::driver_for( $mapping );

			if ( $driver instanceof DriverUnavailable ) {
				++$totals['skipped'];

				if ( 'provider_environment_changed' === $driver->reason ) {
					++$totals['drifted'];
				}

				continue;
			}

			$groups[ $driver->id() ]['driver']     = $driver;
			$groups[ $driver->id() ]['mappings'][] = $mapping;
		}

		foreach ( $groups as $group ) {
			$result = self::reconcile_group( $group['driver'], $group['mappings'] );

			$totals['updated']     += $result['updated'];
			$totals['divergences'] += $result['divergences'];
			$totals['skipped']     += $result['skipped'];
		}

		return $totals;
	}

	/**
	 * @param Mapping[] $mappings
	 * @return array{updated: int, divergences: int, skipped: int}
	 */
	private static function reconcile_group( SslDriver $driver, array $mappings ): array {
		global $wpdb;

		$table = Schema::domains_table();

		$contexts = array();
		$by_host  = array();

		foreach ( $mappings as $mapping ) {
			$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

			if ( null === $name ) {
				continue;
			}

			$contexts[]                = SslResourceContext::from_mapping(
				$mapping,
				Environment::installation_id(),
				$name,
				$driver->id()
			);
			$by_host[ $mapping->host ] = $mapping;
		}

		if ( array() === $contexts ) {
			return array(
				'updated'     => 0,
				'divergences' => 0,
				'skipped'     => 0,
			);
		}

		$report      = $driver->reconcile( $contexts );
		$updated     = 0;
		$divergences = 0;
		$skipped     = 0;

		foreach ( $report->statuses as $host => $status ) {
			$mapping = $by_host[ $host ] ?? null;

			if ( null === $mapping || $status->transient ) {
				continue;
			}

			if ( null !== $status->confirmed_method && $status->confirmed_method !== $mapping->ssl_method ) {
				++$divergences;

				// Reported, never patched: the local method is an operator
				// decision, and this is a read.
				EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$mapping->ssl_method,
					$status->confirmed_method,
					'cron',
					array(
						'divergence'   => 'validation_method',
						'auto_patched' => false,
					)
				);
			}

			if ( $status->state === $mapping->ssl_state ) {
				continue;
			}

			// The CAS result decides whether this counts. A row whose revision
			// moved, or which acquired a lease since the snapshot, was changed by
			// someone with better information than a batch read.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
			$applied = AtomicTransition::commit(
				static fn (): bool => 1 === $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						    SET ssl_state = %s, ssl_checked_at = %s, revision = revision + 1, updated_at = %s
						  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
						$status->state->value,
						gmdate( 'Y-m-d H:i:s' ),
						gmdate( 'Y-m-d H:i:s' ),
						$mapping->id,
						$mapping->revision
					)
				),
				static fn (): bool => EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$mapping->ssl_state->value,
					$status->state->value,
					'cron',
					array( 'source' => 'reconciliation' )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// committed() alone is enough here: every non-committed outcome means
			// this batch read did not change the row, so it is not counted and
			// nothing is logged. A reconciliation pass has nothing else to do
			// about a lost CAS that it would not also do about a failed write.
			if ( $applied->committed() ) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		if ( ! $report->snapshot_complete ) {
			EventLog::record( 0, '', 'ssl', null, null, 'cron', array( 'snapshot_incomplete' => $report->incomplete_reason ) );
		}

		return array(
			'updated'     => $updated,
			'divergences' => $divergences,
			'skipped'     => $skipped,
		);
	}
}
