<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * One source of truth for every lease and recovery duration. The floor exists
 * because a lease shorter than the provider timeout plus a margin would let
 * recovery fence a request that is still legitimately in flight.
 */
final class TimingPolicy {

	public const PROVIDER_TIMEOUT_SECONDS = 10;

	public const SAFETY_MARGIN_SECONDS = 30;

	public const DEFAULT_LEASE_TTL = 120;

	public const DEFAULT_RECOVERY_GRACE = 180;

	public const MAX_TTL = 600;

	/** The largest interval a durable retry schedule may reach. */
	public const MAX_BACKOFF = 21600;

	/**
	 * The largest interval a *recovery* re-read may be pushed out by. It stays
	 * below MAX_TTL so a scheduled re-read always falls inside the fencing
	 * window the same worker holds.
	 */
	public const MAX_RECOVERY_BACKOFF = 300;

	/** The largest FORBIDDEN duration: values must exceed this, not equal it. */
	public static function floor(): int {
		return self::PROVIDER_TIMEOUT_SECONDS + self::SAFETY_MARGIN_SECONDS;
	}

	/** The smallest permitted duration — strictly greater than the floor. */
	public static function minimum(): int {
		return self::floor() + 1;
	}

	public static function lease_ttl(): int {
		return self::clamp(
			apply_filters( 'pd_mutation_lease_ttl', self::DEFAULT_LEASE_TTL ),
			self::DEFAULT_LEASE_TTL
		);
	}

	public static function recovery_grace(): int {
		return self::clamp(
			apply_filters( 'pd_recovery_grace_seconds', self::DEFAULT_RECOVERY_GRACE ),
			self::DEFAULT_RECOVERY_GRACE
		);
	}

	public static function authorization_ttl( int $lease_ttl ): int {
		$requested = apply_filters( 'pd_authorization_ttl', self::DEFAULT_LEASE_TTL );
		$requested = is_numeric( $requested ) ? (int) $requested : self::DEFAULT_LEASE_TTL;

		return max( 30, min( $lease_ttl, min( 300, $requested ) ) );
	}

	/** In-lease re-read spacing: bounded so the re-read stays inside the window. */
	public static function recovery_backoff( int $attempt ): int {
		return min( self::MAX_RECOVERY_BACKOFF, 30 * ( 2 ** max( 0, $attempt ) ) );
	}

	/** Durable retry spacing for work that holds no lease between attempts. */
	public static function attempt_backoff( int $attempt ): int {
		return min( self::MAX_BACKOFF, 300 * ( 2 ** max( 0, $attempt ) ) );
	}

	/** @param mixed $value */
	private static function clamp( $value, int $default ): int {
		$seconds = is_numeric( $value ) ? (int) $value : $default;

		// Raised to the minimum rather than rejected: a short lease is a
		// misconfiguration, not a reason to stop working. The minimum is
		// strictly above the floor because the specification says exceed.
		return max( self::minimum(), min( self::MAX_TTL, $seconds ) );
	}
}
