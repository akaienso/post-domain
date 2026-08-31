<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\Mapping;

/**
 * The one-time challenges a domain test is issued against.
 *
 * Two things were wrong with keeping one per mapping in a transient. Every page
 * render overwrote the previous value, so opening the detail screen in a second
 * tab silently broke the first tab's test for no security reason at all. And
 * consumption was read, verify, delete — three steps, so two requests arriving
 * together could both read the same challenge before either deleted it, and a
 * proof that is single-use by design could be spent twice.
 *
 * Challenges are therefore addressed individually, and claiming one is a single
 * statement. `DELETE ... WHERE option_name = ?` reports how many rows it removed;
 * exactly one caller can be told it removed one, whatever else is happening at
 * the same time and whatever is caching options above it.
 *
 * The raw challenge is never the key. The key is a digest of it, so the value an
 * attacker would need to guess is not sitting in an option name — and a claim
 * for one challenge cannot disturb another.
 */
final class OriginChallenge {

	private const PREFIX = 'pd_origin_challenge_';

	/** Long enough for a page load and a probe, short enough to be worthless later. */
	public const TTL = 300;

	/** How many expired rows one issue may tidy up, so this never becomes a scan. */
	private const SWEEP = 20;

	/**
	 * Issues a fresh challenge for this mapping.
	 *
	 * Independently addressable: issuing another leaves every outstanding one
	 * usable, so a second tab does not invalidate the first.
	 */
	public static function issue( Mapping $mapping ): string {
		self::collect_expired();

		$challenge = bin2hex( random_bytes( 32 ) );

		add_option(
			self::key( $mapping->id, $challenge ),
			array(
				'mapping' => $mapping->id,
				'expires' => time() + self::TTL,
			),
			'',
			false
		);

		return $challenge;
	}

	/**
	 * Claims one challenge, once.
	 *
	 * The delete is the claim. Reading first and deleting afterwards is what let
	 * two overlapping requests both succeed; here the loser is told it removed
	 * nothing and stops. Only the exact challenge presented is touched, so a
	 * failed attempt cannot destroy another tab's.
	 *
	 * @return bool True for the single caller that won the claim.
	 */
	public static function claim( int $mapping_id, string $challenge ): bool {
		global $wpdb;

		if ( '' === $challenge ) {
			return false;
		}

		$key    = self::key( $mapping_id, $challenge );
		$stored = get_option( $key );

		// One statement, and its own answer. Two callers racing here are resolved
		// by the database rather than by the order they happened to read in.
		$claimed = $wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Options are cached above the table, so the cache must be told too or a
		// later read in the same request would still see the row.
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( 1 !== $claimed ) {
			return false;
		}

		if ( ! is_array( $stored ) || ! isset( $stored['mapping'], $stored['expires'] ) ) {
			return false;
		}

		// Checked after the claim, not before: an expired challenge should be
		// consumed and refused rather than left behind to be retried.
		if ( (int) $stored['mapping'] !== $mapping_id ) {
			return false;
		}

		return (int) $stored['expires'] > time();
	}

	/** Whether a challenge is outstanding, without consuming it. */
	public static function is_outstanding( int $mapping_id, string $challenge ): bool {
		$stored = get_option( self::key( $mapping_id, $challenge ) );

		return is_array( $stored )
			&& isset( $stored['expires'] )
			&& (int) $stored['expires'] > time();
	}

	/**
	 * Removes a bounded number of expired challenges.
	 *
	 * Called when one is issued, so the table cannot accumulate them forever
	 * without needing a scheduled job of its own. Bounded so an issue never turns
	 * into a long scan.
	 */
	public static function collect_expired(): int {
		global $wpdb;

		$like = $wpdb->esc_like( self::PREFIX ) . '%';

		/** @var array<int, array<string, string>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$like,
				self::SWEEP
			),
			ARRAY_A
		);

		$removed = 0;

		foreach ( $rows as $row ) {
			$value = maybe_unserialize( $row['option_value'] );

			if ( is_array( $value ) && isset( $value['expires'] ) && (int) $value['expires'] > time() ) {
				continue;
			}

			delete_option( $row['option_name'] );
			++$removed;
		}

		return $removed;
	}

	/** Every challenge, for a clone reset or an uninstall. */
	public static function forget_all(): void {
		global $wpdb;

		$like = $wpdb->esc_like( self::PREFIX ) . '%';

		/** @var string[] $names */
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * The address of a challenge: a digest, never the challenge itself.
	 *
	 * Keeps the secret out of option names, and makes the key a fixed length
	 * whatever the challenge was.
	 */
	private static function key( int $mapping_id, string $challenge ): string {
		return self::PREFIX . hash( 'sha256', $mapping_id . ':' . $challenge );
	}
}
