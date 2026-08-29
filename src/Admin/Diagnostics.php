<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\DbRepository;
use PostDomain\Routing\AmbiguousPath;
use PostDomain\Ssl\Credentials;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;

final class Diagnostics {

	/** @return array<string, array{status: string, detail: string}> */
	public static function checks(): array {
		return array(
			'verification_backlog' => self::backlog(),
			'wp_cron_health'       => self::cron(),
			'path_collisions'      => self::collisions(),
			'round_trip_failures'  => self::round_trips(),
			'stale_leases'         => self::stale_leases(),
			'ssl_ownership'        => self::ownership(),
			'apex_configuration'   => self::apex(),
			'marker_support'       => self::markers(),
			'environment'          => self::environment(),
			'ssl_driver'           => self::ssl_driver(),
			'long_recoveries'      => self::long_recoveries(),
			'blocked_recoveries'   => self::blocked_recoveries(),
			'drifted_resources'    => self::drifted_resources(),
		);
	}

	/**
	 * Certificates that exist in an account this site is no longer pointed at.
	 * They are not fenced and nothing is wrong with them — they simply cannot be
	 * read or changed from here, and their last known state is now frozen.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function drifted_resources(): array {
		$drifted = array();

		foreach ( ( new DbRepository() )->all() as $mapping ) {
			if ( null === $mapping->ssl_ref ) {
				continue;
			}

			$driver = \PostDomain\Ssl\BoundResource::driver_for( $mapping );

			if ( ! $driver instanceof \PostDomain\Ssl\DriverUnavailable ) {
				continue;
			}

			// Each identifier from its own field, correctly labelled. The
			// environment comes from the mapping rather than from the refusal:
			// `driver_not_registered` carries no environment at all, and the
			// operator still has to be told which account the resource lives in.
			$drifted[] = sprintf(
				'%s [%s] in %s: %s',
				$mapping->host,
				$driver->reason,
				(string) $mapping->ssl_provider_environment,
				$driver->detail()
			);
		}

		if ( array() === $drifted ) {
			return array(
				'status' => 'ok',
				'detail' => __( 'Every bound certificate is readable from the configured provider.', 'post-domain' ),
			);
		}

		return array(
			'status' => 'warning',
			'detail' => implode( '; ', $drifted ),
		);
	}

	/**
	 * A mutation cannot be resolved while the driver or provider account it began
	 * against is unavailable. Nothing is queried in that state, so the only way an
	 * operator learns of it is here — naming exactly what to restore.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function blocked_recoveries(): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT host, ssl_mutation_kind, ssl_mutation_driver, ssl_mutation_environment
				   FROM {$table}
				  WHERE ssl_mutation_phase = %s AND ssl_mutation_driver IS NOT NULL
				  LIMIT 50",
				'recovering'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$blocked  = array();
		$registry = \PostDomain\Ssl\DriverFactory::registry();

		foreach ( $rows as $row ) {
			$driver = $registry->get( (string) $row['ssl_mutation_driver'] );

			if ( null !== $driver && $driver->environment_id() === $row['ssl_mutation_environment'] ) {
				continue;
			}

			$blocked[] = sprintf(
				'%s (%s) needs driver "%s" configured for "%s"',
				(string) $row['host'],
				(string) $row['ssl_mutation_kind'],
				(string) $row['ssl_mutation_driver'],
				(string) $row['ssl_mutation_environment']
			);
		}

		if ( array() === $blocked ) {
			return array(
				'status' => 'ok',
				'detail' => __( 'No recovery is blocked on configuration.', 'post-domain' ),
			);
		}

		return array(
			'status' => 'error',
			'detail' => implode( '; ', $blocked ),
		);
	}

	/**
	 * Silence is the failure mode this catches: with no provider selected the
	 * plugin works perfectly and never requests a certificate.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function ssl_driver(): array {
		$selected   = \PostDomain\Ssl\DriverFactory::selected_driver_id();
		$registered = \PostDomain\Ssl\DriverFactory::registry()->ids();

		if ( \PostDomain\Ssl\DriverFactory::NULL_DRIVER === $selected ) {
			return array(
				'status' => 'warning',
				'detail' => __( 'No certificate provider is selected, so a certificate will never be requested.', 'post-domain' ),
			);
		}

		if ( ! in_array( $selected, $registered, true ) ) {
			return array(
				'status' => 'error',
				'detail' => sprintf(
					/* translators: 1: configured driver id, 2: comma-separated registered ids. */
					__( 'The configured provider "%1$s" is not registered. Registered: %2$s.', 'post-domain' ),
					$selected,
					implode( ', ', $registered )
				),
			);
		}

		return array(
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: %s: the selected driver id. */
				__( 'Certificates are provisioned through "%s".', 'post-domain' ),
				$selected
			),
		);
	}

	/**
	 * A recovery that keeps reading without reaching a conclusion is bounded by
	 * backoff, not by a give-up rule, so it has to be visible.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function long_recoveries(): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT host, ssl_mutation_kind, ssl_transient_count, ssl_next_attempt_at
				   FROM {$table}
				  WHERE ssl_mutation_phase = %s AND ssl_transient_count >= %d
				  ORDER BY ssl_transient_count DESC
				  LIMIT 20",
				'recovering',
				5
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( array() === $rows ) {
			return array(
				'status' => 'ok',
				'detail' => __( 'No long-running recoveries.', 'post-domain' ),
			);
		}

		$lines = array();

		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'%s (%s): %d reads, next %s',
				(string) $row['host'],
				(string) $row['ssl_mutation_kind'],
				(int) $row['ssl_transient_count'],
				(string) $row['ssl_next_attempt_at']
			);
		}

		return array(
			'status' => 'warning',
			'detail' => implode( '; ', $lines ),
		);
	}

	/** The iframe that runs the CORS probe on the mapped origin. */
	public static function probe_iframe( string $mapped_host, string $asset_url ): string {
		return sprintf(
			'<iframe class="pd-probe" hidden src="%s"></iframe>',
			esc_url(
				'https://' . $mapped_host . '/.well-known/post-domain-probe?'
				. http_build_query(
					array(
						'asset'  => $asset_url,
						'parent' => home_url(),
					)
				)
			)
		);
	}

	/** @return array{status: string, detail: string} */
	private static function backlog(): array {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT MIN(verify_next_attempt_at) FROM {$table}
				  WHERE verify_next_attempt_at IS NOT NULL AND verify_next_attempt_at <= %s",
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $oldest ) {
			return array(
				'status' => 'ok',
				'detail' => 'No verification work is overdue.',
			);
		}

		return array(
			'status' => strtotime( (string) $oldest ) < time() - HOUR_IN_SECONDS ? 'warning' : 'ok',
			'detail' => 'The oldest overdue verification is due since ' . (string) $oldest . ' UTC.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function cron(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) ) {
			return array(
				'status' => 'ok',
				'detail' => 'WP-Cron is disabled; a system cron must run `wp cron event run --due-now`.',
			);
		}

		return array(
			'status' => 'ok',
			'detail' => 'WP-Cron is enabled. On a low-traffic site, prefer a system cron.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function collisions(): array {
		$collisions = AmbiguousPath::all();

		return array(
			'status' => array() === $collisions ? 'ok' : 'warning',
			'detail' => array() === $collisions
				? 'No ambiguous path segments were seen this request.'
				: count( $collisions ) . ' ambiguous segment(s); those posts fall back to primary-host permalinks.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function round_trips(): array {
		return array(
			'status' => 'ok',
			'detail' => 'Round-trip verification runs on every emitted link; failures fall back to the primary permalink.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function stale_leases(): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string>> $rows */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT host, ssl_mutation_kind, ssl_mutation_phase FROM {$table}
				  WHERE ssl_mutation_token IS NOT NULL AND ssl_mutation_expires_at <= %s",
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( array() === $rows ) {
			return array(
				'status' => 'ok',
				'detail' => 'No expired provider-mutation leases.',
			);
		}

		$detail = array();

		foreach ( $rows as $row ) {
			$detail[] = $row['host'] . ' (' . $row['ssl_mutation_kind'] . ':' . $row['ssl_mutation_phase'] . ')';
		}

		return array(
			'status' => 'warning',
			'detail' => 'Awaiting lease recovery: ' . implode( ', ', $detail ),
		);
	}

	/** @return array{status: string, detail: string} */
	private static function ownership(): array {
		$unowned = 0;

		foreach ( ( new DbRepository() )->all() as $mapping ) {
			if ( null !== $mapping->ssl_ref && null === $mapping->ssl_ownership_origin ) {
				++$unowned;
			}
		}

		return array(
			'status' => 0 === $unowned ? 'ok' : 'warning',
			'detail' => 0 === $unowned
				? 'Every bound certificate has recorded ownership provenance.'
				: $unowned . ' certificate reference(s) have no ownership provenance; adopt them explicitly.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function apex(): array {
		$targets = Credentials::apex_targets();

		if ( array() === $targets ) {
			return array(
				'status' => 'ok',
				'detail' => 'No Apex Proxying targets configured; apex domains rely on CNAME flattening, ALIAS, or ANAME.',
			);
		}

		return array(
			'status' => null === Credentials::apex_provenance() ? 'warning' : 'ok',
			'detail' => null === Credentials::apex_provenance()
				? 'Apex targets are configured without a declared provenance. They must be Cloudflare-assigned '
					. 'Static IP prefixes or BYOIP addresses, never ordinary origin addresses.'
				: 'Apex targets declared as ' . (string) Credentials::apex_provenance() . '.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function markers(): array {
		return array(
			'status' => 'ok',
			'detail' => 'Provider marker support is recorded per mapping; without it, identity rests on the '
				. 'reference-plus-hostname binding and the permanent DNS challenge.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function environment(): array {
		return array(
			'status' => Environment::is_blocked() ? 'error' : 'ok',
			'detail' => Environment::is_blocked()
				? 'The primary host changed. Provider mutations are blocked until you choose restore or clone.'
				: 'Installation identity matches the recorded primary host.',
		);
	}
}
