<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\NativeDnsResolver;

final class NativeDnsResolverTest extends TestCase {

	public function test_a_matching_record_is_a_match(): void {
		$resolver = new NativeDnsResolver(
			static fn(): array => array( array( 'type' => 'TXT', 'txt' => 'post-domain-verify=abc' ) )
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_a_present_but_different_record_is_a_mismatch(): void {
		$resolver = new NativeDnsResolver(
			static fn(): array => array( array( 'type' => 'TXT', 'txt' => 'post-domain-verify=zzz' ) )
		);

		$this->assertSame(
			DnsOutcome::MISMATCH,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_an_empty_result_is_transient_not_no_record(): void {
		$resolver = new NativeDnsResolver( static fn(): array => array() );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome,
			'dns_get_record cannot tell an absent record from a failed lookup'
		);
	}

	public function test_a_failed_lookup_is_transient(): void {
		$resolver = new NativeDnsResolver( static fn(): bool => false );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_it_can_never_emit_a_hard_absence(): void {
		foreach ( array( array(), false, null ) as $lookup_result ) {
			$resolver = new NativeDnsResolver( static fn() => $lookup_result );
			$outcome  = $resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome;

			$this->assertNotSame( DnsOutcome::NO_RECORD, $outcome );
			$this->assertNotSame( DnsOutcome::NXDOMAIN, $outcome );
		}
	}
}
