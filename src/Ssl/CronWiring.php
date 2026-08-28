<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\ResolverFactory;
use PostDomain\Verification\FreshProof;
use PostDomain\Verification\Schedule;

/**
 * The SSL subsystem's own cron topology, so `Plugin` gains one line rather than
 * a subsystem — the same arrangement as `Verification\CronWiring`.
 *
 * It registers the *ordinary* half of `pd_ssl_sweep` (§13.6: "two selectors").
 * `Plugin::sweep_ssl()` runs `LeaseRecovery` at the default priority; this runs
 * afterwards, at 20, and that ordering is load-bearing rather than incidental: a
 * fenced or unresolved mutation is not yet a fact, and ordinary work must not act
 * on a row whose outcome recovery has not established. `DeletionSchedule` also
 * refuses every leased row outright, so the two are belt and braces.
 */
final class CronWiring {

	/** Recovery holds the default priority; ordinary due work follows it. */
	public const SWEEP_PRIORITY = 20;

	public static function register(): void {
		add_action( 'pd_ssl_sweep', array( self::class, 'sweep_deletions' ), self::SWEEP_PRIORITY );
	}

	/**
	 * Rows whose provider resource still has to be removed before the mapping
	 * can go. Each one goes through the full §14.15 workflow — authorizer, gate,
	 * permit, finalize CAS — and no shortcut exists from here to a driver.
	 */
	public static function sweep_deletions(): void {
		$clock = new SystemClock();
		$due   = DeletionSchedule::due( 50, $clock );

		if ( array() === $due ) {
			return;
		}

		$budget = (int) apply_filters( 'pd_sweep_budget_seconds', 20 );
		$budget = max( 1, min( 300, $budget ) );

		$lease   = new MutationLease( $clock );
		$repo    = new DbRepository();
		$proof   = new FreshProof( ResolverFactory::from_filters() );
		$service = new DeletionService(
			new DeletionAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock ),
			$clock
		);

		// The batch, the time budget, and the single bounded continuation event
		// are the verification sweep's, unchanged: one scheduler, one set of
		// limits, so a slow provider cannot starve the run in a new way.
		Schedule::run_sweep(
			$due,
			$budget,
			static function ( Mapping $mapping ) use ( $service ): void {
				$service->process( $mapping );
			},
			'pd_ssl_sweep'
		);
	}
}
