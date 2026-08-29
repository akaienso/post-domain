<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
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

	/**
	 * Rows due for work whose persisted scope this build cannot read.
	 *
	 * Reported once per sweep and otherwise untouched: no provider call, no local
	 * change, no attempted repair. The intent behind a corrupted scope is not
	 * recoverable from the row, and the event log is a record of what happened,
	 * never an input to what happens next (§12.3).
	 */
	private static function report_undecidable( \PostDomain\Contracts\Clock $clock ): void {
		foreach ( DeletionSchedule::undecidable( 50, $clock ) as $mapping ) {
			EventLog::record(
				$mapping->id,
				$mapping->host,
				'integrity',
				null,
				null,
				'cron',
				array(
					'unreadable_removal_scope' => (string) $mapping->ssl_removal_scope,
					'auto_repaired'            => false,
				)
			);
		}
	}

	/** Recovery holds the default priority; ordinary due work follows it. */
	public const SWEEP_PRIORITY = 20;

	public static function register(): void {
		add_action( 'pd_ssl_sweep', array( self::class, 'sweep_deletions' ), self::SWEEP_PRIORITY );
	}

	/**
	 * Rows with an outstanding provider removal, of either scope.
	 *
	 * Each row goes through the full §14.15 workflow — authorizer, gate, permit,
	 * finalize CAS — and no shortcut exists from here to a driver. Which of the
	 * two services runs is decided by the row's own persisted scope, never
	 * inferred: handing an SSL-only removal to mapping deletion would hard-delete
	 * a domain whose operator asked only for its certificate to go.
	 */
	public static function sweep_deletions(): void {
		$clock = new SystemClock();

		self::report_undecidable( $clock );

		$due = DeletionSchedule::due( 50, $clock );

		if ( array() === $due ) {
			return;
		}

		$budget = (int) apply_filters( 'pd_sweep_budget_seconds', 20 );
		$budget = max( 1, min( 300, $budget ) );

		$lease      = new MutationLease( $clock );
		$repo       = new DbRepository();
		$proof      = new FreshProof( ResolverFactory::from_filters() );
		$authorizer = new DeletionAuthorizer( $repo, $proof, $lease, $clock );
		$gate       = new MutationGate( $lease, $clock );

		// Both services share the same authorizer, lease and gate instances, so
		// there is one authorization path however a removal was requested.
		$mapping_deletion = new DeletionService( $authorizer, $lease, $gate, $clock );
		$resource_removal = new SslResourceRemoval( $authorizer, $lease, $gate, $clock );

		// The batch, the time budget, and the single bounded continuation event
		// are the verification sweep's, unchanged: one scheduler, one set of
		// limits, so a slow provider cannot starve the run in a new way.
		Schedule::run_sweep(
			$due,
			$budget,
			static function ( Mapping $mapping ) use ( $mapping_deletion, $resource_removal ): void {
				// Each scope is dispatched explicitly, and anything else is
				// dispatched nowhere. A `default` that fell through to mapping
				// deletion would turn an unreadable byte in one column into a
				// deleted domain.
				match ( RemovalScope::from_row( $mapping->ssl_removal_scope ) ) {
					RemovalScope::RESOURCE => $resource_removal->process( $mapping ),
					RemovalScope::MAPPING  => $mapping_deletion->process( $mapping ),
					null                   => null,
				};
			},
			'pd_ssl_sweep'
		);
	}
}
