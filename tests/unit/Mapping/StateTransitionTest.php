<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationPhase;

final class StateTransitionTest extends TestCase {

	public function test_verification_values(): void {
		$this->assertSame(
			array( 'unverified', 'pending', 'verified', 'failed' ),
			array_map( static fn( VerificationState $s ): string => $s->value, VerificationState::cases() )
		);
	}

	public function test_verification_legal_transitions(): void {
		$this->assertTrue( VerificationState::UNVERIFIED->can_transition_to( VerificationState::PENDING ) );
		$this->assertTrue( VerificationState::PENDING->can_transition_to( VerificationState::VERIFIED ) );
		$this->assertTrue( VerificationState::PENDING->can_transition_to( VerificationState::FAILED ) );
		$this->assertTrue( VerificationState::VERIFIED->can_transition_to( VerificationState::FAILED ) );
		$this->assertTrue( VerificationState::FAILED->can_transition_to( VerificationState::PENDING ) );
	}

	public function test_rotation_can_reach_unverified_from_anywhere(): void {
		foreach ( VerificationState::cases() as $state ) {
			$this->assertTrue(
				$state->can_transition_to( VerificationState::UNVERIFIED ),
				"rotation must reset {$state->value} to unverified"
			);
		}
	}

	public function test_verification_illegal_transitions(): void {
		$this->assertFalse( VerificationState::UNVERIFIED->can_transition_to( VerificationState::VERIFIED ) );
		$this->assertFalse( VerificationState::FAILED->can_transition_to( VerificationState::VERIFIED ) );
	}

	public function test_activation_toggles_both_ways(): void {
		$this->assertTrue( ActivationState::INACTIVE->can_transition_to( ActivationState::ACTIVE ) );
		$this->assertTrue( ActivationState::ACTIVE->can_transition_to( ActivationState::INACTIVE ) );
	}

	public function test_ssl_states_include_pending_removal(): void {
		$this->assertSame(
			array( 'none', 'requested', 'pending_validation', 'active', 'failed', 'pending_removal', 'revoked' ),
			array_map( static fn( SslState $s ): string => $s->value, SslState::cases() )
		);
	}

	public function test_ssl_legal_transitions(): void {
		$this->assertTrue( SslState::NONE->can_transition_to( SslState::REQUESTED ) );
		$this->assertTrue( SslState::NONE->can_transition_to( SslState::ACTIVE ) );
		$this->assertTrue( SslState::REQUESTED->can_transition_to( SslState::PENDING_VALIDATION ) );
		$this->assertTrue( SslState::PENDING_VALIDATION->can_transition_to( SslState::ACTIVE ) );
		$this->assertTrue( SslState::ACTIVE->can_transition_to( SslState::PENDING_REMOVAL ) );
		$this->assertTrue( SslState::PENDING_REMOVAL->can_transition_to( SslState::REVOKED ) );
		$this->assertTrue( SslState::REVOKED->can_transition_to( SslState::REQUESTED ) );
	}

	public function test_revoked_is_only_reachable_from_pending_removal(): void {
		foreach ( SslState::cases() as $state ) {
			if ( SslState::PENDING_REMOVAL === $state || SslState::REVOKED === $state ) {
				continue;
			}

			$this->assertFalse(
				$state->can_transition_to( SslState::REVOKED ),
				"{$state->value} must not reach revoked directly"
			);
		}
	}

	public function test_supporting_enum_values(): void {
		$this->assertSame( array( 'created', 'adopted' ), array_map(
			static fn( OwnershipOrigin $o ): string => $o->value,
			OwnershipOrigin::cases()
		) );
		$this->assertSame( array( 'create', 'adopt', 'method', 'remove' ), array_map(
			static fn( MutationKind $k ): string => $k->value,
			MutationKind::cases()
		) );
		$this->assertSame( array( 'reserved', 'in_flight', 'recovering' ), array_map(
			static fn( MutationPhase $p ): string => $p->value,
			MutationPhase::cases()
		) );
	}
}
