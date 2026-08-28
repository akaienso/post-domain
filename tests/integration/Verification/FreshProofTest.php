<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class FreshProofTest extends WP_UnitTestCase {

	private function mapping( string $challenge, VerificationState $state ): Mapping {
		return new Mapping(
			1,
			'example.test',
			null,
			42,
			1,
			$state,
			ActivationState::ACTIVE,
			SslState::ACTIVE,
			null,
			$challenge,
			'_post-domain-challenge'
		);
	}

	private function resolver_expecting( string $expected_value ): DnsResolver {
		return new class( $expected_value ) implements DnsResolver {
			public function __construct( private readonly string $published ) {}

			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult(
					hash_equals( $expected, $this->published ) ? DnsOutcome::MATCH : DnsOutcome::MISMATCH
				);
			}
		};
	}

	public function test_the_current_challenge_proves(): void {
		$challenge = str_repeat( 'a', 32 );
		$proof     = new FreshProof( $this->resolver_expecting( 'post-domain-verify=' . $challenge ) );

		$this->assertSame(
			DnsOutcome::MATCH,
			$proof->prove( $this->mapping( $challenge, VerificationState::VERIFIED ) )
		);
	}

	public function test_a_rotated_challenge_no_longer_proves(): void {
		$published = 'post-domain-verify=' . str_repeat( 'a', 32 );
		$proof     = new FreshProof( $this->resolver_expecting( $published ) );

		$this->assertSame(
			DnsOutcome::MISMATCH,
			$proof->prove( $this->mapping( str_repeat( 'b', 32 ), VerificationState::VERIFIED ) ),
			'a clone rotates challenges, so it cannot prove against the original record'
		);
	}

	public function test_stored_verification_state_does_not_influence_the_result(): void {
		$challenge = str_repeat( 'a', 32 );
		$proof     = new FreshProof( $this->resolver_expecting( 'post-domain-verify=' . $challenge ) );

		foreach ( VerificationState::cases() as $state ) {
			$this->assertSame(
				DnsOutcome::MATCH,
				$proof->prove( $this->mapping( $challenge, $state ) ),
				'the proof is live, not a reading of stored state'
			);
		}
	}

	public function test_it_never_reads_stored_verification_fields(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Verification/FreshProof.php' );

		foreach ( array( 'verification_state', 'verified_at', 'last_outcome' ) as $field ) {
			$this->assertStringNotContainsString( $field, $source );
		}
	}
}
