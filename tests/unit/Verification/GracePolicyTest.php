<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\VerificationState;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\GracePolicy;

final class GracePolicyTest extends TestCase {

	public function test_a_match_verifies_and_resets_both_counters(): void {
		$after = GracePolicy::apply( VerificationState::PENDING, DnsOutcome::MATCH, 2, 5, 3, false );

		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 0, $after['hard'] );
		$this->assertSame( 0, $after['transient'] );
	}

	public function test_the_first_two_hard_failures_keep_a_verified_mapping(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::NO_RECORD, 0, 0, 3, false );
		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 1, $after['hard'] );

		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::NO_RECORD, 1, 0, 3, false );
		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 2, $after['hard'] );
	}

	public function test_the_third_hard_failure_fails_the_mapping(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::NXDOMAIN, 2, 0, 3, false );

		$this->assertSame( VerificationState::FAILED, $after['state'] );
		$this->assertSame( 3, $after['hard'] );
	}

	public function test_a_transient_never_touches_the_hard_counter(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::TRANSIENT, 2, 0, 3, false );

		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 2, $after['hard'], 'the hard counter is untouched' );
		$this->assertSame( 1, $after['transient'] );
	}

	public function test_a_transient_can_never_fail_a_mapping(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::TRANSIENT, 2, 99, 3, true );

		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
	}

	public function test_a_hard_failure_resets_the_transient_counter(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::MISMATCH, 0, 7, 3, false );

		$this->assertSame( 0, $after['transient'], 'a hard answer proves the resolver is reachable' );
	}

	public function test_pending_stays_pending_until_the_deadline(): void {
		$after = GracePolicy::apply( VerificationState::PENDING, DnsOutcome::NO_RECORD, 5, 0, 3, false );

		$this->assertSame( VerificationState::PENDING, $after['state'] );
	}

	public function test_pending_fails_when_the_deadline_passes(): void {
		$after = GracePolicy::apply( VerificationState::PENDING, DnsOutcome::NO_RECORD, 0, 0, 3, true );

		$this->assertSame( VerificationState::FAILED, $after['state'] );
	}

	public function test_a_failed_mapping_can_reach_verified_only_through_pending(): void {
		$after = GracePolicy::apply( VerificationState::FAILED, DnsOutcome::MATCH, 0, 0, 3, false );

		$this->assertSame(
			VerificationState::FAILED,
			$after['state'],
			'a failed mapping is re-checked only after an explicit reset to pending'
		);
	}
}
