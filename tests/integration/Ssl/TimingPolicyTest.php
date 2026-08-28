<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Ssl\TimingPolicy;
use WP_UnitTestCase;

final class TimingPolicyTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pd_mutation_lease_ttl' );
		remove_all_filters( 'pd_recovery_grace_seconds' );
		remove_all_filters( 'pd_authorization_ttl' );
		parent::tear_down();
	}

	public function test_the_floor_is_the_provider_timeout_plus_the_margin(): void {
		$this->assertSame(
			TimingPolicy::PROVIDER_TIMEOUT_SECONDS + TimingPolicy::SAFETY_MARGIN_SECONDS,
			TimingPolicy::floor()
		);
	}

	public function test_the_minimum_is_strictly_above_the_floor(): void {
		$this->assertGreaterThan(
			TimingPolicy::floor(),
			TimingPolicy::minimum(),
			'the specification says exceed, not equal'
		);
	}

	public function test_the_defaults_exceed_the_floor(): void {
		$this->assertGreaterThan( TimingPolicy::floor(), TimingPolicy::lease_ttl() );
		$this->assertGreaterThan( TimingPolicy::floor(), TimingPolicy::recovery_grace() );
	}

	public function test_a_lease_filter_below_the_floor_is_raised_to_the_minimum(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): int => 5 );

		$this->assertSame(
			TimingPolicy::minimum(),
			TimingPolicy::lease_ttl(),
			'recovery must never begin while the original request is still in flight'
		);
		$this->assertGreaterThan( TimingPolicy::floor(), TimingPolicy::lease_ttl() );
	}

	public function test_a_lease_filter_exactly_at_the_floor_is_still_raised(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): int => TimingPolicy::floor() );

		$this->assertGreaterThan(
			TimingPolicy::floor(),
			TimingPolicy::lease_ttl(),
			'equality leaves exactly the race the margin exists to prevent'
		);
	}

	public function test_a_recovery_filter_below_the_floor_is_raised_to_the_minimum(): void {
		add_filter( 'pd_recovery_grace_seconds', static fn(): int => 1 );

		$this->assertSame( TimingPolicy::minimum(), TimingPolicy::recovery_grace() );
	}

	public function test_a_recovery_filter_exactly_at_the_floor_is_still_raised(): void {
		add_filter( 'pd_recovery_grace_seconds', static fn(): int => TimingPolicy::floor() );

		$this->assertGreaterThan( TimingPolicy::floor(), TimingPolicy::recovery_grace() );
	}

	public function test_a_filter_above_the_ceiling_is_clamped(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): int => 99999 );

		$this->assertSame( TimingPolicy::MAX_TTL, TimingPolicy::lease_ttl() );
	}

	public function test_a_non_numeric_filter_falls_back_to_the_default(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): string => 'soon' );

		$this->assertSame( TimingPolicy::DEFAULT_LEASE_TTL, TimingPolicy::lease_ttl() );
	}

	public function test_the_authorization_never_outlives_its_lease(): void {
		add_filter( 'pd_authorization_ttl', static fn(): int => 300 );

		$this->assertLessThanOrEqual( 60, TimingPolicy::authorization_ttl( 60 ) );
		$this->assertLessThanOrEqual( 120, TimingPolicy::authorization_ttl( 120 ) );
	}

	public function test_recovery_backoff_grows_and_is_capped(): void {
		$this->assertLessThan( TimingPolicy::recovery_backoff( 5 ), TimingPolicy::recovery_backoff( 3 ) );
		$this->assertSame( TimingPolicy::MAX_RECOVERY_BACKOFF, TimingPolicy::recovery_backoff( 99 ) );
	}

	public function test_recovery_backoff_never_outruns_the_recovery_window(): void {
		// A scheduled re-read that fell outside the fencing window would hand the
		// row to a takeover instead of to the worker that scheduled it.
		$this->assertLessThan( TimingPolicy::MAX_TTL, TimingPolicy::MAX_RECOVERY_BACKOFF );
	}

	public function test_attempt_backoff_grows_and_is_capped_separately(): void {
		// Durable retry schedules (deletion) are not held under a lease, so they
		// may back off much further than an in-lease re-read.
		$this->assertLessThan( TimingPolicy::attempt_backoff( 5 ), TimingPolicy::attempt_backoff( 3 ) );
		$this->assertSame( TimingPolicy::MAX_BACKOFF, TimingPolicy::attempt_backoff( 99 ) );
		$this->assertGreaterThan( TimingPolicy::MAX_RECOVERY_BACKOFF, TimingPolicy::MAX_BACKOFF );
	}
}
