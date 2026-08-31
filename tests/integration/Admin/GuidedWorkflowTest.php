<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Screen;
use PostDomain\Admin\SettingsPage;
use PostDomain\Admin\Step;
use PostDomain\Admin\Workflow;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * The setup journey, and the claims made about it.
 *
 * Live testing of v1.0.1 reached verified + serving + active certificate and the
 * screen said the domain was fully set up. The domain served the host's
 * placeholder page: nothing in those three states says the hosting routes the
 * mapped Host header here. These pin what the screen may and may not claim.
 */
final class GuidedWorkflowTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function mapping(
		VerificationState $verification = VerificationState::UNVERIFIED,
		ActivationState $activation = ActivationState::INACTIVE,
		SslState $ssl = SslState::NONE,
		bool $bound = false
	): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"step-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				$verification,
				$activation,
				$ssl,
				null,
				str_pad( (string) $this->seq, 32, 'w', STR_PAD_LEFT ),
				'_post-domain-challenge',
				$bound ? OwnershipOrigin::CREATED : null,
				$bound ? Environment::installation_id() : null,
				$bound ? 'recording' : null,
				$bound ? 'recording:default' : null,
				$bound ? 'ref-1' : null
			)
		);
	}

	private function page( int $id ): string {
		$_GET = array( 'mapping' => (string) $id );

		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	/** @return array<int, string> step number => status */
	private function statuses( Mapping $mapping ): array {
		$out = array();

		foreach ( Workflow::steps( $mapping ) as $step ) {
			$out[ $step->number ] = $step->status;
		}

		return $out;
	}

	// -- the journey ---------------------------------------------------------

	public function test_a_new_mapping_asks_only_for_ownership(): void {
		$statuses = $this->statuses( $this->mapping() );

		$this->assertSame( Step::CURRENT, $statuses[1] );
		$this->assertSame( Step::UPCOMING, $statuses[3], 'serving cannot be first' );
		$this->assertSame( Step::UPCOMING, $statuses[4], 'a certificate cannot be first' );
	}

	public function test_verifying_moves_the_journey_on(): void {
		$statuses = $this->statuses( $this->mapping( VerificationState::VERIFIED ) );

		$this->assertSame( Step::DONE, $statuses[1] );
		$this->assertSame( Step::CURRENT, $statuses[2], 'the routing record comes next' );
		$this->assertSame( Step::CURRENT, $statuses[3] );
	}

	public function test_a_certificate_is_offered_only_once_serving(): void {
		$verified = $this->statuses( $this->mapping( VerificationState::VERIFIED ) );

		$this->assertSame( Step::UPCOMING, $verified[4] );

		$serving = $this->statuses( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( Step::CURRENT, $serving[4] );
	}

	public function test_a_failed_verification_is_marked_as_needing_attention(): void {
		$statuses = $this->statuses( $this->mapping( VerificationState::FAILED ) );

		$this->assertSame( Step::FAILED, $statuses[1] );
	}

	public function test_a_failed_certificate_offers_another_attempt(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::FAILED );

		$statuses = $this->statuses( $mapping );

		$this->assertSame( Step::FAILED, $statuses[4] );
		$this->assertStringContainsString( 'Request the certificate again', $this->page( $mapping->id ) );
	}

	public function test_a_certificate_in_progress_is_a_wait_not_an_invitation(): void {
		$statuses = $this->statuses(
			$this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::PENDING_VALIDATION, true )
		);

		$this->assertSame( Step::WAITING, $statuses[4] );

		// Steps 5 and 6 are no longer inferred from the breadth of the SSL state;
		// with no plan in hand nothing is known about either phase, and claiming
		// both are outstanding is what CooldownAndStepsTest now pins down.
		$this->assertNotSame( Step::CURRENT, $statuses[5] );
		$this->assertNotSame( Step::CURRENT, $statuses[6] );
	}

	public function test_the_page_does_not_invite_steps_out_of_order(): void {
		$html = $this->page( $this->mapping()->id );

		$this->assertStringNotContainsString( 'value="pd_provision_ssl"', $html );
		$this->assertStringNotContainsString( 'value="pd_activate"', $html );
		$this->assertStringContainsString( 'value="pd_verify"', $html );
	}

	// -- the claim -----------------------------------------------------------

	public function test_three_green_states_do_not_make_a_domain_fully_set_up(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		$summary = Workflow::summary( $mapping );

		$this->assertStringNotContainsString( 'fully set up', $summary );
		$this->assertStringContainsString( 'Test the domain', $summary );
		$this->assertSame( Step::UNCONFIRMED, $this->statuses( $mapping )[7] );
	}

	public function test_the_final_step_becomes_done_only_when_the_origin_answers(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		Workflow::record_origin_confirmed( $mapping );

		$this->assertSame( Step::DONE, $this->statuses( $mapping )[7] );
		$this->assertStringContainsString( 'set up and tested', Workflow::summary( $mapping ) );

		Workflow::forget_origin_confirmation( $mapping->id );

		$this->assertSame( Step::UNCONFIRMED, $this->statuses( $mapping )[7] );
	}

	public function test_the_test_step_offers_a_way_to_open_the_domain(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'https://' . $mapping->host . '/', $html );
		$this->assertStringContainsString( 'data-pd-origin-test', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	// -- destructive actions are kept apart ----------------------------------

	public function test_destructive_actions_are_not_part_of_the_setup_list(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		$html = $this->page( $mapping->id );

		$steps = substr( $html, (int) strpos( $html, 'Setup' ), (int) strpos( $html, 'Manage this domain' ) - (int) strpos( $html, 'Setup' ) );

		$this->assertStringNotContainsString( 'pd_delete_mapping', $steps );
		$this->assertStringNotContainsString( 'pd_remove_ssl', $steps );
		$this->assertStringNotContainsString( 'pd_deactivate', $steps );

		$this->assertStringContainsString( 'Danger zone', $html );
		$this->assertStringContainsString( 'pd_delete_mapping', $html );
	}

	public function test_deleting_still_confirms_and_says_the_content_survives(): void {
		$html = $this->page( $this->mapping( VerificationState::VERIFIED )->id );

		$this->assertStringContainsString( 'The content itself is not deleted', $html );
		$this->assertStringContainsString( 'the page or post it shows is not deleted', strtolower( $html ) );
	}

	public function test_a_leased_mapping_offers_no_management_actions(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE, true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => str_repeat( '4', 32 ),
				'ssl_mutation_kind'        => 'create',
				'ssl_mutation_phase'       => 'in_flight',
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'ssl_mutation_driver'      => 'recording',
				'ssl_mutation_environment' => 'recording:default',
			),
			array( 'id' => $mapping->id )
		);

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'A certificate operation is running', $html );
		$this->assertStringNotContainsString( 'value="pd_delete_mapping"', $html );
	}

	// -- timings -------------------------------------------------------------

	public function test_the_verification_cooldown_is_read_from_what_the_server_enforces(): void {
		$mapping = $this->mapping();

		$this->assertNull( Screen::verify_available_at( $mapping ), 'no cooldown before a check' );

		// Exactly the state MappingCommands::verify_now() sets, through the same
		// representation both it and the screen read.
		\PostDomain\Verification\Cooldown::begin( $mapping->id );

		$available = Screen::verify_available_at( $mapping );

		$this->assertNotNull( $available );
		$this->assertGreaterThan( time(), $available );
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, $available );
	}

	public function test_an_expired_cooldown_stops_disabling_the_action(): void {
		$mapping = $this->mapping();

		// The boundary: a stored instant in the past is not a cooldown.
		set_transient( 'pd_verify_rate_' . $mapping->id, time() - 1, MINUTE_IN_SECONDS );

		$this->assertNull( Screen::verify_available_at( $mapping ) );
	}

	public function test_the_page_disables_the_check_and_carries_a_countdown(): void {
		$mapping = $this->mapping();

		\PostDomain\Verification\Cooldown::begin( $mapping->id );

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'data-pd-countdown="', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'limited to one a minute', $html );
	}

	public function test_timings_report_stored_times_rather_than_invented_ones(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::PENDING_VALIDATION, true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'last_checked_at'        => '2026-01-02 03:04:05',
				'verify_next_attempt_at' => '2026-01-02 04:04:05',
				'ssl_checked_at'         => '2026-01-02 03:05:05',
				'ssl_next_attempt_at'    => '2026-01-02 03:20:05',
			),
			array( 'id' => $mapping->id )
		);

		$html = $this->page( $mapping->id );

		$this->assertStringContainsString( 'Ownership last checked', $html );
		$this->assertStringContainsString( 'Next automatic certificate check', $html );
		$this->assertStringContainsString( '2026-01-02T03:04:05+00:00', $html, 'the stored instant, machine readable' );

		// The provider owns the timing, so no deadline is promised for it.
		$this->assertStringContainsString( 'no exact deadline', $html );
	}

	public function test_a_mapping_with_no_recorded_times_shows_no_timings_section(): void {
		$html = $this->page( $this->mapping()->id );

		$this->assertStringNotContainsString( 'Ownership last checked', $html );
	}
}
