<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Mapping\DbRepository;

/**
 * The production caller for `HostingReconciler`.
 *
 * An ambiguous attachment is a write whose fate is unknown, and the only safe
 * way to learn it is to read. This runs on the existing SSL sweep — the same
 * quarter-hourly tick, at a later priority — and for each outstanding row asks
 * the provider what exists. It never attaches, never rechecks DNS, and never
 * repeats a mutation.
 *
 * Bounded three ways: a fixed number of rows per pass, a fixed number of reads
 * per row before it becomes a state a person is asked to look at, and a CAS on
 * every write so two workers racing cannot both settle the same claim.
 *
 * @package PostDomain
 */
final class HostingRecoveryService {

	/** Runs on the existing sweep rather than adding a schedule of its own. */
	public const HOOK = 'pd_ssl_sweep';

	/** Later than SSL recovery and ordinary SSL work, which are unrelated. */
	public const PRIORITY = 30;

	/** Rows examined per pass. The rest wait for the next tick. */
	public const BATCH = 20;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'sweep' ), self::PRIORITY );
	}

	public static function sweep(): void {
		$provider = HostingProviderFactory::for_new_mapping();

		if ( $provider instanceof HostingProviderUnavailable ) {
			// No credential, no binding, or a rebound one. Nothing is settled by
			// guessing, and an unreachable provider is not evidence of anything.
			return;
		}

		$environment = $provider->environment();

		if ( null === $environment ) {
			return;
		}

		$repo        = new DbRepository();
		$coordinator = new HostingRegistrationCoordinator();

		foreach ( ( new HostingTransitions() )->outstanding( $environment->id(), self::BATCH ) as $row ) {
			$mapping = $repo->by_id( (int) $row['id'] );

			if ( null === $mapping ) {
				continue;
			}

			// Re-resolved per row: `for_mapping()` refuses a row whose stored
			// environment no longer matches the live one, which is what stops a
			// rebound credential settling a registration it never made.
			$coordinator->recover(
				$mapping,
				HostingProviderFactory::for_mapping( $mapping ),
				(int) ( $row['hosting_attempts'] ?? 0 )
			);
		}
	}
}
