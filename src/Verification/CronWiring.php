<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\SystemClock;

/**
 * The cron topology for the verification subsystem.
 *
 * Plan 06 Task 7 places these registrations and methods inside `Plugin::boot()`.
 * They live here instead so that `Plugin` has one line to add rather than a
 * subsystem to absorb: `CronWiring::register()`.
 */
final class CronWiring {

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_cron' ), 100 );
		add_action( 'pd_verify_pending', array( self::class, 'sweep_pending' ) );
		add_action( 'pd_verify_established', array( self::class, 'sweep_established' ) );
		add_action( 'pd_verify_now', array( self::class, 'verify_one' ) );
	}

	public static function register_cron(): void {
		Schedule::register_cron();
	}

	public static function sweep_pending(): void {
		self::sweep( Schedule::due_pending( 50 ), 'pd_verify_pending' );
	}

	public static function sweep_established(): void {
		self::sweep( Schedule::due_established( 50 ), 'pd_verify_established' );
	}

	/**
	 * One place reads `pd_doh_endpoints` and `pd_dns_resolver`, so cron and REST
	 * cannot end up proving ownership by different means.
	 */
	public static function dns_resolver(): DnsResolver {
		return ResolverFactory::from_filters();
	}

	/**
	 * The on-demand probe behind `POST /domains/{id}/verify`. The REST request
	 * schedules it rather than running it, so the response reports state instead
	 * of blocking on DNS (spec §15.2).
	 */
	public static function verify_one( int $mapping_id ): void {
		$repo    = new DbRepository();
		$mapping = $repo->by_id( $mapping_id );

		if ( null === $mapping ) {
			return;
		}

		( new Verifier( $repo, self::dns_resolver(), new SystemClock() ) )->verify( $mapping );
	}

	/** @param Mapping[] $rows */
	private static function sweep( array $rows, string $hook ): void {
		$budget = (int) apply_filters( 'pd_sweep_budget_seconds', 20 );
		$budget = max( 1, min( 300, $budget ) );

		$verifier = new Verifier( new DbRepository(), self::dns_resolver(), new SystemClock() );

		Schedule::run_sweep(
			$rows,
			$budget,
			static function ( Mapping $mapping ) use ( $verifier ): void {
				$verifier->verify( $mapping );
			},
			$hook
		);
	}
}
