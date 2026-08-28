<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\SystemClock;
use PostDomain\Support\WpHttpClient;

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
	 * The default resolver is DoH with two independent endpoints. A replacement
	 * installed through `pd_dns_resolver` substitutes the ownership proof
	 * mechanism outright, so anything that is not a `DnsResolver` is ignored.
	 */
	public static function dns_resolver(): DnsResolver {
		/** @var string[] $endpoints */
		$endpoints = (array) apply_filters(
			'pd_doh_endpoints',
			array( 'https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve' )
		);

		$default = new DohResolver( new WpHttpClient(), $endpoints );

		/** @var mixed $resolver */
		$resolver = apply_filters( 'pd_dns_resolver', $default );

		return $resolver instanceof DnsResolver ? $resolver : $default;
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
