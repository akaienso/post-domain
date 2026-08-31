<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Actions;
use PostDomain\Admin\OriginChallenge;
use PostDomain\Admin\OriginConfirmation;
use PostDomain\Admin\OriginProbe;
use PostDomain\Admin\OriginProof;
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
 * Issuing and spending the challenges a domain test runs against.
 *
 * One transient per mapping had two faults. Every render overwrote it, so a
 * second tab silently broke the first tab's test for no security reason. And
 * consumption was read, verify, delete — so two requests arriving together could
 * both read it before either deleted it, and a single-use proof was spendable
 * twice. These exercise the whole AJAX path rather than `OriginProof::verify()`
 * on its own, because that is where the claim happens.
 */
final class OriginChallengeTest extends OwnedSessionTestCase {

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
		OriginChallenge::forget_all();
		OriginConfirmation::forget_all();

		$_POST    = array();
		$_REQUEST = array();

		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_doing_ajax' );

		parent::tear_down();
	}

	private function testable(): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"challenge-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_pad( (string) $this->seq, 32, 'c', STR_PAD_LEFT ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	/**
	 * Drives the real AJAX handler and returns its JSON answer.
	 *
	 * `wp_send_json_*` calls `wp_die()`, so the handler is run inside the
	 * harness's own AJAX die handler and the buffered body is read back.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{success: bool, data: array<string, mixed>}
	 */
	private function submit( Mapping $mapping, array $payload, string $signature ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- building the request the handler under test verifies for itself.
		$_POST = array(
			'action'    => OriginProbe::ACTION,
			'mapping'   => (string) $mapping->id,
			'nonce'     => wp_create_nonce( OriginProbe::nonce_action( $mapping->id ) ),
			'payload'   => array_map( 'strval', $payload ),
			'signature' => $signature,
		);

		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// wp_send_json_* ends in wp_die(), and the AJAX die handler — the one that
		// can be replaced — is only consulted when WordPress believes it is
		// serving an AJAX request.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn(): callable => static function (): void {
				throw new \RuntimeException( 'pd_ajax_done' );
			}
		);

		ob_start();

		try {
			OriginProbe::respond();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			$body = (string) ob_get_clean();
			remove_all_filters( 'wp_die_ajax_handler' );
			remove_all_filters( 'wp_doing_ajax' );
		}

		$decoded = json_decode( $body, true );

		return array(
			'success' => is_array( $decoded ) && true === ( $decoded['success'] ?? false ),
			'data'    => is_array( $decoded ) && is_array( $decoded['data'] ?? null ) ? $decoded['data'] : array(),
		);
	}

	/** @return array{payload: array<string, string|int>, signature: string} */
	private function proof( Mapping $mapping, string $challenge ): array {
		return OriginProof::issue( $mapping, $challenge, $mapping->host );
	}

	// -- independence --------------------------------------------------------

	public function test_two_challenges_for_one_mapping_both_remain_usable(): void {
		$mapping = $this->testable();

		$first  = OriginProbe::issue_challenge( $mapping );
		$second = OriginProbe::issue_challenge( $mapping );

		$this->assertNotSame( $first, $second );

		// Claimed, not merely reported outstanding: with one shared key both would
		// answer for the same record and the second claim would find nothing.
		$this->assertTrue( OriginChallenge::claim( $mapping->id, $first ) );
		$this->assertTrue(
			OriginChallenge::claim( $mapping->id, $second ),
			'issuing one must not retire the other'
		);
	}

	public function test_rendering_the_page_again_does_not_break_the_first_tab(): void {
		$mapping = $this->testable();

		$first = OriginProbe::issue_challenge( $mapping );

		// A second tab opening the same detail screen.
		OriginProbe::issue_challenge( $mapping );

		$proof  = $this->proof( $mapping, $first );
		$result = $this->submit( $mapping, $proof['payload'], $proof['signature'] );

		$this->assertTrue( $result['success'], 'the first tab must still be able to finish its test' );
	}

	// -- single use ----------------------------------------------------------

	public function test_a_valid_submission_records_the_confirmation(): void {
		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = $this->proof( $mapping, $challenge );

		$result = $this->submit( $mapping, $proof['payload'], $proof['signature'] );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( OriginConfirmation::confirmed_at( $mapping ) );
	}

	public function test_replaying_the_same_proof_fails(): void {
		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = $this->proof( $mapping, $challenge );

		$this->assertTrue( $this->submit( $mapping, $proof['payload'], $proof['signature'] )['success'] );

		$replay = $this->submit( $mapping, $proof['payload'], $proof['signature'] );

		$this->assertFalse( $replay['success'], 'a single-use proof is single use' );
	}

	/**
	 * Two claims for one challenge, with nothing between them.
	 *
	 * The old sequence read, verified and then deleted, so both could read before
	 * either deleted. The claim is now the delete itself, and the database
	 * decides.
	 */
	public function test_two_overlapping_claims_produce_exactly_one_winner(): void {
		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );

		$won = 0;

		foreach ( array( 1, 2 ) as $_ ) {
			if ( OriginChallenge::claim( $mapping->id, $challenge ) ) {
				++$won;
			}
		}

		$this->assertSame( 1, $won, 'exactly one caller may claim a challenge' );
	}

	/**
	 * The primitive the claim rests on.
	 *
	 * Two processes cannot be interleaved from inside one test, so what is
	 * asserted here is the property that makes the interleaving safe: the delete
	 * reports how many rows it removed, and only one caller can be told one. A
	 * read-then-delete sequence has no such answer to give, which is why two
	 * overlapping requests could both proceed.
	 */
	public function test_the_claim_is_decided_by_the_delete_not_by_a_prior_read(): void {
		global $wpdb;

		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$key       = 'pd_origin_challenge_' . hash( 'sha256', $mapping->id . ':' . $challenge );

		// Both "requests" have read the row and believe it is theirs.
		$this->assertIsArray( get_option( $key ) );
		$this->assertIsArray( get_option( $key ) );

		$this->assertSame( 1, $wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ) );
		$this->assertSame(
			0,
			$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ),
			'the second remover is told it removed nothing'
		);
	}

	public function test_a_failed_test_does_not_spend_another_tabs_challenge(): void {
		$mapping = $this->testable();

		$mine   = OriginProbe::issue_challenge( $mapping );
		$theirs = OriginProbe::issue_challenge( $mapping );

		// A forged signature against my challenge.
		$proof = $this->proof( $mapping, $mine );

		$this->assertFalse( $this->submit( $mapping, $proof['payload'], str_repeat( '0', 64 ) )['success'] );

		$this->assertTrue(
			OriginChallenge::is_outstanding( $mapping->id, $theirs ),
			'the other tab is untouched'
		);
	}

	// -- refusals ------------------------------------------------------------

	public function test_an_expired_challenge_fails(): void {
		global $wpdb;

		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );

		// Age it exactly as time would.
		$key = 'pd_origin_challenge_' . hash( 'sha256', $mapping->id . ':' . $challenge );

		update_option(
			$key,
			array(
				'mapping' => $mapping->id,
				'expires' => time() - 1,
			),
			false
		);

		unset( $wpdb );

		$proof = $this->proof( $mapping, $challenge );

		$this->assertFalse( $this->submit( $mapping, $proof['payload'], $proof['signature'] )['success'] );
	}

	public function test_a_challenge_issued_for_another_mapping_fails(): void {
		$mine  = $this->testable();
		$other = $this->testable();

		$challenge = OriginProbe::issue_challenge( $other );

		$this->assertFalse(
			OriginChallenge::claim( $mine->id, $challenge ),
			'a challenge belongs to the mapping it was issued for'
		);
	}

	public function test_a_forged_proof_records_nothing(): void {
		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = $this->proof( $mapping, $challenge );

		$result = $this->submit( $mapping, $proof['payload'], str_repeat( 'a', 64 ) );

		$this->assertFalse( $result['success'] );
		$this->assertNull(
			OriginConfirmation::confirmed_at( $mapping ),
			'a rejected proof must leave no confirmation behind'
		);
	}

	public function test_a_challenge_nobody_issued_fails(): void {
		$mapping = $this->testable();
		$proof   = $this->proof( $mapping, 'never-issued-challenge' );

		$this->assertFalse( $this->submit( $mapping, $proof['payload'], $proof['signature'] )['success'] );
	}

	public function test_a_submission_for_a_domain_that_is_not_serving_is_refused(): void {
		global $wpdb;

		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );
		$proof     = $this->proof( $mapping, $challenge );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array( 'activation_state' => ActivationState::INACTIVE->value ),
			array( 'id' => $mapping->id )
		);

		$this->assertFalse( $this->submit( $mapping, $proof['payload'], $proof['signature'] )['success'] );
	}

	// -- housekeeping --------------------------------------------------------

	public function test_expired_challenges_do_not_accumulate_forever(): void {
		$mapping = $this->testable();

		for ( $i = 0; $i < 5; $i++ ) {
			$challenge = OriginProbe::issue_challenge( $mapping );
			$key       = 'pd_origin_challenge_' . hash( 'sha256', $mapping->id . ':' . $challenge );

			update_option(
				$key,
				array(
					'mapping' => $mapping->id,
					'expires' => time() - 100,
				),
				false
			);
		}

		$this->assertGreaterThan(
			0,
			OriginChallenge::collect_expired(),
			'issuing a challenge tidies expired ones rather than letting them pile up'
		);
	}

	public function test_a_live_challenge_is_never_collected(): void {
		$mapping   = $this->testable();
		$challenge = OriginProbe::issue_challenge( $mapping );

		OriginChallenge::collect_expired();

		$this->assertTrue( OriginChallenge::is_outstanding( $mapping->id, $challenge ) );
	}
}
