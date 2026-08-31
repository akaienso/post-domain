<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\OriginConfirmation;
use PostDomain\Admin\OriginProbe;
use PostDomain\Admin\OriginProof;
use PostDomain\Admin\Step;
use PostDomain\Admin\Workflow;
use PostDomain\Application\MappingCommands;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * A test result is about a configuration, not about a domain forever.
 *
 * The first version stored a timestamp and honoured it indefinitely, so a
 * domain stayed "tested" after serving stopped and restarted, after the
 * certificate was removed and reissued, after the target changed, and after the
 * mapping was deleted — at which point the record waited to be inherited by
 * whatever next took that id.
 */
final class OriginConfirmationTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		OriginConfirmation::forget_all();
		parent::tear_down();
	}

	private function tested(): Mapping {
		++$this->seq;

		$mapping = $this->repo->save(
			new Mapping(
				0,
				"origin-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_pad( (string) $this->seq, 32, 'o', STR_PAD_LEFT ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);

		OriginConfirmation::record( $mapping );

		return $mapping;
	}

	private function reread( Mapping $mapping ): Mapping {
		$fresh = $this->repo->by_id( $mapping->id );

		self::assertNotNull( $fresh );

		return $fresh;
	}

	public function test_a_confirmation_holds_while_nothing_changes(): void {
		$mapping = $this->tested();

		$this->assertNotNull( OriginConfirmation::confirmed_at( $mapping ) );
		$this->assertSame( Step::DONE, $this->step_seven( $mapping ) );
	}

	public function test_stopping_serving_retires_the_confirmation(): void {
		$mapping = $this->tested();

		MappingCommands::production( $this->repo )->set_activation( $mapping, ActivationState::INACTIVE );

		$this->assertNull(
			OriginConfirmation::confirmed_at( $this->reread( $mapping ) ),
			'a domain that stopped serving has not been tested in that state'
		);
	}

	public function test_starting_serving_again_does_not_restore_it(): void {
		$mapping  = $this->tested();
		$commands = MappingCommands::production( $this->repo );

		$commands->set_activation( $mapping, ActivationState::INACTIVE );
		$commands->set_activation( $this->reread( $mapping ), ActivationState::ACTIVE );

		$this->assertNull( OriginConfirmation::confirmed_at( $this->reread( $mapping ) ) );
	}

	public function test_a_certificate_change_retires_the_confirmation(): void {
		global $wpdb;

		$mapping = $this->tested();

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'ssl_state' => SslState::REVOKED->value,
				'revision'  => $mapping->revision + 1,
			),
			array( 'id' => $mapping->id )
		);

		$this->assertNull( OriginConfirmation::confirmed_at( $this->reread( $mapping ) ) );
	}

	public function test_changing_the_target_retires_the_confirmation(): void {
		$mapping = $this->tested();

		MappingCommands::production( $this->repo )->set_activation(
			$mapping,
			ActivationState::ACTIVE,
			self::factory()->post->create( array( 'post_status' => 'publish' ) )
		);

		$this->assertNull(
			OriginConfirmation::confirmed_at( $this->reread( $mapping ) ),
			'the domain now shows something else'
		);
	}

	public function test_rotating_the_challenge_retires_the_confirmation(): void {
		$mapping = $this->tested();

		MappingCommands::production( $this->repo )->rotate_challenge( $mapping );

		$this->assertNull( OriginConfirmation::confirmed_at( $this->reread( $mapping ) ) );
	}

	public function test_deleting_the_mapping_removes_the_record_entirely(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0,
				'deleted-origin.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'q', 32 ),
				'_post-domain-challenge'
			)
		);

		OriginConfirmation::record( $mapping );

		MappingCommands::production( $this->repo )->delete( $mapping );

		$this->assertNull( $this->repo->by_id( $mapping->id ), 'the row is gone' );
		$this->assertFalse(
			get_option( 'pd_origin_confirmed_' . $mapping->id ),
			'and so is its test result, rather than waiting for the id to be reused'
		);
	}

	public function test_a_clone_reset_forgets_every_confirmation(): void {
		$one = $this->tested();
		$two = $this->tested();

		Environment::resolve_as_clone();

		$this->assertFalse( get_option( 'pd_origin_confirmed_' . $one->id ) );
		$this->assertFalse( get_option( 'pd_origin_confirmed_' . $two->id ) );
	}

	public function test_a_legacy_bare_timestamp_is_refused_and_removed(): void {
		$mapping = $this->tested();

		// The shape this used to store: no evidence about state at all.
		update_option( 'pd_origin_confirmed_' . $mapping->id, '2026-01-01 00:00:00', false );

		$this->assertNull( OriginConfirmation::confirmed_at( $mapping ) );
		$this->assertFalse( get_option( 'pd_origin_confirmed_' . $mapping->id ) );
	}

	public function test_a_malformed_record_is_refused_and_removed(): void {
		$mapping = $this->tested();

		update_option( 'pd_origin_confirmed_' . $mapping->id, array( 'confirmed_at' => 'yesterday' ), false );

		$this->assertNull( OriginConfirmation::confirmed_at( $mapping ) );
		$this->assertFalse( get_option( 'pd_origin_confirmed_' . $mapping->id ) );
	}

	// -- the proof itself ----------------------------------------------------

	public function test_a_proof_from_this_installation_verifies(): void {
		$mapping   = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = OriginProof::issue( $mapping, $challenge, $mapping->host );

		$this->assertNull( OriginProof::verify( $proof['payload'], $proof['signature'], $mapping, $challenge ) );
	}

	public function test_a_forged_signature_is_refused(): void {
		$mapping   = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = OriginProof::issue( $mapping, $challenge, $mapping->host );

		$this->assertSame(
			'signature',
			OriginProof::verify( $proof['payload'], str_repeat( 'f', 64 ), $mapping, $challenge ),
			'anything served at the mapped hostname could echo a token; only this installation can sign'
		);
	}

	public function test_moving_a_field_invalidates_the_signature(): void {
		$mapping   = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = OriginProof::issue( $mapping, $challenge, $mapping->host );

		$tampered         = $proof['payload'];
		$tampered['host'] = 'attacker.example';

		$this->assertSame( 'signature', OriginProof::verify( $tampered, $proof['signature'], $mapping, $challenge ) );
	}

	public function test_an_expired_proof_is_refused(): void {
		$mapping   = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = OriginProof::issue( $mapping, $challenge, $mapping->host );

		$expired            = $proof['payload'];
		$expired['expires'] = time() - 1;

		// Re-signed, so this is an expiry test rather than a signature test.
		$this->assertSame(
			'expired',
			OriginProof::verify( $expired, $this->sign( $expired ), $mapping, $challenge )
		);
	}

	public function test_a_proof_for_another_challenge_is_refused(): void {
		$mapping = $this->tested();
		$proof   = OriginProof::issue( $mapping, 'challenge-a', $mapping->host );

		$this->assertSame( 'challenge', OriginProof::verify( $proof['payload'], $proof['signature'], $mapping, 'challenge-b' ) );
	}

	public function test_a_proof_for_another_host_is_refused(): void {
		$mapping   = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = OriginProof::issue( $mapping, $challenge, 'elsewhere.example' );

		$this->assertSame( 'wrong_host', OriginProof::verify( $proof['payload'], $proof['signature'], $mapping, $challenge ) );
	}

	public function test_a_proof_for_another_mapping_is_refused(): void {
		$mine      = $this->tested();
		$other     = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mine );
		$proof     = OriginProof::issue( $other, $challenge, $other->host );

		$this->assertContains(
			OriginProof::verify( $proof['payload'], $proof['signature'], $mine, $challenge ),
			array( 'wrong_mapping', 'wrong_host' )
		);
	}

	public function test_a_proof_about_an_earlier_revision_is_refused(): void {
		$mapping   = $this->tested();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = OriginProof::issue( $mapping, $challenge, $mapping->host );

		MappingCommands::production( $this->repo )->rotate_challenge( $mapping );

		$this->assertSame(
			'stale_revision',
			OriginProof::verify( $proof['payload'], $proof['signature'], $this->reread( $mapping ), $challenge )
		);
	}

	public function test_a_malformed_payload_is_refused(): void {
		$mapping = $this->tested();

		$this->assertSame( 'malformed', OriginProof::verify( array( 'host' => 'x' ), 'sig', $mapping, 'c' ) );
	}

	/** @param array<string, mixed> $payload */
	private function sign( array $payload ): string {
		$method = new \ReflectionMethod( OriginProof::class, 'sign' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $payload );
	}

	private function step_seven( Mapping $mapping ): string {
		foreach ( Workflow::steps( $mapping ) as $step ) {
			if ( 7 === $step->number ) {
				return $step->status;
			}
		}

		return '';
	}
}
