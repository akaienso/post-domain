<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

/**
 * The one representation of the verification cooldown.
 *
 * The command that enforces it and the screen that displays it must not read
 * different things. The screen used to inspect `_transient_timeout_pd_verify_rate_*`
 * directly, which is WordPress's private bookkeeping and simply does not exist
 * when an external object cache backs transients — so on such a site the button
 * looked enabled, offered no countdown, and was then refused.
 *
 * The expiry is therefore stored *as the value*, not inferred from WordPress's
 * internals, and both sides read it through the Transients API. Whatever backs
 * that API, the two agree.
 */
final class Cooldown {

	public const SECONDS = MINUTE_IN_SECONDS;

	private static function key( int $mapping_id ): string {
		return 'pd_verify_rate_' . $mapping_id;
	}

	/** Starts the cooldown and returns the instant it ends. */
	public static function begin( int $mapping_id ): int {
		$until = time() + self::SECONDS;

		set_transient( self::key( $mapping_id ), $until, self::SECONDS );

		return $until;
	}

	/** The instant the cooldown ends, or null when there is none in force. */
	public static function active_until( int $mapping_id ): ?int {
		$until = get_transient( self::key( $mapping_id ) );

		if ( ! is_numeric( $until ) ) {
			return null;
		}

		$until = (int) $until;

		// A value that has not been reaped yet is still expired. Trusting the
		// stored instant rather than the presence of the row keeps the answer
		// the same on every cache backend.
		return $until > time() ? $until : null;
	}

	public static function in_force( int $mapping_id ): bool {
		return null !== self::active_until( $mapping_id );
	}

	public static function clear( int $mapping_id ): void {
		delete_transient( self::key( $mapping_id ) );
	}
}
