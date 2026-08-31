<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Hosting\HostingWiring;
use WP_UnitTestCase;

/**
 * Temporary: the five production gaps, asserted directly.
 *
 * Each assertion here failed against b280832 and passes once the workflow is
 * wired. It is deliberately blunt, and it is not the evidence that the feature
 * works — WordifyWorkflowTest drives the real screen and the real POSTs, and is
 * what actually proves the behaviour.
 */
final class ProductionWiringGapTest extends WP_UnitTestCase {

	public function test_a_production_listener_answers_the_test_connection_filter(): void {
		HostingWiring::register();

		$this->assertTrue(
			has_filter( 'pd_hosting_test_connection' ) !== false,
			'Nothing under src/ answers pd_hosting_test_connection, so Test connection always reports the fallback.'
		);
	}

	public function test_a_production_action_exists_for_binding_a_wordify_site(): void {
		$this->assertContains(
			'pd_select_wordify_site',
			\PostDomain\Admin\Actions::ACTIONS,
			'There is no administrator action that selects and binds a Wordify site.'
		);
	}

	public function test_mapping_creation_consults_a_hosting_registration_coordinator(): void {
		$this->assertTrue(
			class_exists( \PostDomain\Hosting\HostingRegistrationCoordinator::class ),
			'Mapping creation has no coordinator to call.'
		);

		$source = (string) file_get_contents( __DIR__ . '/../../../src/Application/MappingCommands.php' );

		$this->assertStringContainsString(
			'HostingRegistrationCoordinator',
			$source,
			'create_mapping() never asks the hosting provider to register the hostname.'
		);
	}

	public function test_hosting_state_has_a_dedicated_transition_writer(): void {
		$this->assertTrue(
			class_exists( \PostDomain\Hosting\HostingTransitions::class ),
			'No production SQL writes hosting_provider/hosting_environment/hosting_ref/hosting_state/hosting_registered_at.'
		);
	}

	public function test_the_reconciler_has_a_production_recovery_caller(): void {
		$this->assertTrue(
			class_exists( \PostDomain\Hosting\HostingRecoveryService::class ),
			'HostingReconciler has no production caller; ambiguous attachments stay unresolved forever.'
		);

		HostingWiring::register();

		$this->assertNotFalse(
			has_action( \PostDomain\Hosting\HostingRecoveryService::HOOK ),
			'No cron or continuation hook runs hosting recovery.'
		);
	}

	public function test_a_binding_records_the_credential_fingerprint_it_was_validated_under(): void {
		$this->assertTrue(
			method_exists( \PostDomain\Hosting\HostingBinding::class, 'fingerprint' ),
			'HostingBinding does not persist or compare a credential fingerprint.'
		);
	}
}
