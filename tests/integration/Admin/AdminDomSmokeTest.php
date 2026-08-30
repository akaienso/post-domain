<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use DOMDocument;
use DOMXPath;
use PostDomain\Admin\SettingsPage;
use PostDomain\Application\MappingCommands;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Ssl\Credentials;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * Parses the admin page as a browser would, rather than matching strings in it.
 *
 * The v1.0.0 failure is invisible to a substring assertion: the provider names
 * were all present, and only the elements around them were gone. So these load
 * the page into a DOM and ask it the questions a person looking at the screen
 * would ask — is there a control here, can I choose something with it.
 */
final class AdminDomSmokeTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_settings' );
		delete_option( 'pd_ssl_credentials' );
		DriverFactory::reset();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( 'pd_settings' );
		delete_option( 'pd_ssl_credentials' );
		DriverFactory::reset();
		$_GET = array();
		parent::tear_down();
	}

	private function dom( int $mapping_id = 0 ): DOMXPath {
		$_GET = 0 === $mapping_id ? array() : array( 'mapping' => (string) $mapping_id );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$document = new DOMDocument();

		// The admin page is a fragment; libxml complains about the missing
		// document shell and about HTML5 elements it predates. Neither matters
		// for asking whether an element exists.
		libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	private function configure_cloudflare(): void {
		update_option(
			'pd_ssl_credentials',
			array(
				'api_token'    => 'cf-token-value',
				'zone_id'      => 'zone-1',
				'cname_target' => 'saas.example.net',
			),
			false
		);
		DriverFactory::reset();
	}

	public function test_the_provider_control_is_a_real_element_in_the_document(): void {
		$this->configure_cloudflare();
		$this->assertTrue( Credentials::cloudflare_is_configured() );

		$xpath = $this->dom();

		$selects = $xpath->query( '//select[@name="pd_ssl_driver"]' );

		$this->assertNotFalse( $selects );
		$this->assertSame( 1, $selects->length, 'exactly one provider control' );

		$options = $xpath->query( '//select[@name="pd_ssl_driver"]/option' );

		$this->assertNotFalse( $options );
		$this->assertGreaterThanOrEqual( 2, $options->length, 'a choice needs more than one option' );
	}

	/**
	 * The exact v1.0.0 symptom, stated as the test that would have caught it:
	 * the provider names are in the document, and no control contains them.
	 */
	public function test_provider_names_are_never_loose_text_outside_a_control(): void {
		$this->configure_cloudflare();

		$xpath = $this->dom();

		$named  = $xpath->query( '//*[contains(text(), "Cloudflare")]' );
		$inside = $xpath->query( '//select[@name="pd_ssl_driver"]/option[contains(text(), "Cloudflare")]' );

		$this->assertNotFalse( $named );
		$this->assertNotFalse( $inside );
		$this->assertGreaterThan( 0, $named->length, 'the provider must be named somewhere' );
		$this->assertGreaterThan(
			0,
			$inside->length,
			'the provider name appears on the page but not inside a selectable option: '
			. 'that is precisely how v1.0.0 shipped'
		);
	}

	public function test_the_add_form_is_a_real_form_a_browser_can_submit(): void {
		$xpath = $this->dom();

		$forms = $xpath->query( '//form[.//input[@name="pd_action"][@value="pd_add_mapping"]]' );

		$this->assertNotFalse( $forms );
		$this->assertSame( 1, $forms->length );

		$this->assertSame(
			'post',
			strtolower( (string) $forms->item( 0 )?->attributes?->getNamedItem( 'method' )?->nodeValue ),
			'a mutation must not be reachable by GET'
		);

		foreach ( array( 'pd_host', 'pd_post_id', '_wpnonce' ) as $field ) {
			$found = $xpath->query( '//form//*[@name="' . $field . '"]' );

			$this->assertNotFalse( $found );
			$this->assertGreaterThan( 0, $found->length, "the form needs a {$field} field" );
		}
	}

	public function test_every_mutation_control_is_a_post_form_with_a_nonce(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0,
				'dom.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'd', 32 ),
				'_post-domain-challenge'
			)
		);

		$xpath = $this->dom( $mapping->id );
		$forms = $xpath->query( '//form[.//input[@name="pd_action"]]' );

		$this->assertNotFalse( $forms );
		$this->assertGreaterThan( 0, $forms->length );

		foreach ( $forms as $form ) {
			$method = strtolower( (string) $form->attributes?->getNamedItem( 'method' )?->nodeValue );

			$this->assertSame( 'post', $method );

			$nonce = $xpath->query( './/input[@name="_wpnonce"]', $form );
			$this->assertNotFalse( $nonce );
			$this->assertGreaterThan( 0, $nonce->length, 'every mutation form carries a nonce' );

			$revision = $xpath->query( './/input[@name="pd_revision"]', $form );
			$this->assertNotFalse( $revision );
			$this->assertGreaterThan( 0, $revision->length, 'and the revision it was drawn from' );
		}
	}

	public function test_no_secret_reaches_the_document(): void {
		$this->configure_cloudflare();

		$mapping = $this->repo->save(
			new Mapping(
				0,
				'secret.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'e', 32 ),
				'_post-domain-challenge'
			)
		);

		ob_start();
		$_GET = array( 'mapping' => (string) $mapping->id );
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'cf-token-value', $html );
		$this->assertStringNotContainsString( 'zone-1', $html );

		// The challenge value is published as a DNS record on purpose; the raw
		// token by itself must not appear anywhere else.
		$this->assertStringNotContainsString( 'ssl_mutation_token', $html );
	}

	// -- REST stays authoritative -------------------------------------------

	public function test_the_admin_and_rest_refuse_the_same_things(): void {
		$commands = MappingCommands::production( $this->repo );

		// Both surfaces call this; there is no second implementation to diverge.
		$this->assertTrue( $commands->create_mapping( '*.wild.test', null, 1 )->refused_as( Errors::HOST_WILDCARD ) );
		$this->assertTrue( $commands->create_mapping( 'no target.test', null, 1 )->refused_as( Errors::HOST_MALFORMED_AUTHORITY ) );

		$mapping = $this->repo->save(
			new Mapping(
				0,
				'shared.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'f', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->assertTrue(
			$commands->create_mapping( 'shared.test', null, $mapping->post_id )->refused_as( Errors::HOST_EXISTS )
		);

		$stale = $commands->at_revision( $mapping, $mapping->revision - 1 );

		$this->assertNotNull( $stale );
		$this->assertSame( Errors::PRECONDITION_FAILED, $stale->code );
		$this->assertSame( 412, $stale->status );
	}

	public function test_the_rest_controller_and_the_admin_share_one_command_object(): void {
		$controller = new \ReflectionClass( \PostDomain\Rest\ManagementController::class );

		$this->assertTrue(
			$controller->hasProperty( 'commands' ),
			'REST must delegate to the shared commands rather than decide for itself'
		);

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Actions.php' );

		$this->assertStringContainsString( 'MappingCommands', $source );
		$this->assertStringNotContainsString(
			'DriverFactory::for_mapping',
			$source,
			'the admin must not reach a provider except through the shared path'
		);
	}
}
