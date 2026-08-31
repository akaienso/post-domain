<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\CredentialSecret;
use PostDomain\Hosting\HostingBinding;
use PostDomain\Hosting\WordifySite;
use PostDomain\Hosting\WordifyTeam;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * Temporary: the four defects, isolated, each failing at 70b98ed for its own
 * reason. The behavioural coverage lives in WordifyWorkflowTest and
 * WordifyTeamSelectionTest; this file exists so the failure is unambiguous.
 */
final class DefectProofTest extends OwnedSessionTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		HostingBinding::forget();
		CredentialOptionStore::for_wordpress()->forget();
		CredentialOptionStore::for_wordpress()->put( new CredentialSecret( 'wpk_proof-token-not-a-credential' ) );
		add_filter( 'pd_hosting_has_credential', '__return_true' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_hosting_has_credential' );
		HostingBinding::forget();
		CredentialOptionStore::for_wordpress()->forget();
		parent::tear_down();
	}

	/** Defect 1: a multi-team account has no way to say which team. */
	public function test_the_selector_offers_a_team_choice_when_no_team_is_implied(): void {
		$this->assertTrue(
			method_exists( \PostDomain\Admin\Screen::class, 'wordify_team_selector' ),
			'A NO_TEAM result renders a message and no team selector, so setup cannot be completed.'
		);

		$this->assertContains(
			'pd_select_wordify_team',
			\PostDomain\Admin\Actions::ACTIONS,
			'There is no action for choosing a team.'
		);
	}

	/** Defect 3: a lost settlement CAS is reported as a persisted success. */
	public function test_a_lost_settlement_cas_is_not_reported_as_success(): void {
		$this->assertTrue(
			method_exists( \PostDomain\Hosting\RegistrationOutcome::class, 'fenced' ),
			'settle() discards every transition boolean, so a zero-row CAS still reports REGISTERED.'
		);
	}

	/**
	 * Defect 4: store() strips validation from the incoming fields only, so an
	 * existing valid binding keeps its old validated_at and fingerprint while
	 * its team and site are changed underneath them.
	 */
	public function test_store_cannot_leave_a_changed_binding_valid(): void {
		HostingBinding::bind(
			new WordifyTeam( 'team-original', 'Original' ),
			new WordifySite( '01HQ0000000000000000000001', 'active' )
		);

		$this->assertTrue( HostingBinding::current()->is_valid() );

		HostingBinding::store(
			array(
				'team_id'      => 'team-substituted',
				'site_id'      => '01HQ0000000000000000000009',
				'validated_at' => gmdate( 'Y-m-d H:i:s' ),
				'fingerprint'  => 'deadbeef',
			)
		);

		$changed = HostingBinding::current();

		$this->assertSame( 'team-substituted', $changed->team_id );
		$this->assertFalse(
			$changed->is_valid(),
			'A binding whose team and site changed was never confirmed under the old validation.'
		);
	}
}
