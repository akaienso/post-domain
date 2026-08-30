<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use DOMDocument;
use DOMXPath;
use PostDomain\Admin\Screen;
use PostDomain\Admin\SettingsPage;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * The mapped-domain row.
 *
 * v1.0.1 printed the hostname twice — once as a link to the detail screen and
 * again in code styling underneath — which reads as two different values rather
 * than one value and a link.
 */
final class MappingListRowTest extends OwnedSessionTestCase {

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
		$_GET = array();
		parent::tear_down();
	}

	private function mapping( string $host = '' ): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				'' === $host ? "row-{$this->seq}.test" : $host,
				null,
				self::factory()->post->create(
					array(
						'post_status' => 'publish',
						'post_title'  => 'Club home',
					)
				),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_pad( (string) $this->seq, 32, 'r', STR_PAD_LEFT ),
				'_post-domain-challenge'
			)
		);
	}

	private function xpath(): DOMXPath {
		$_GET = array();

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$document = new DOMDocument();

		libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	public function test_the_hostname_appears_exactly_once_in_its_cell(): void {
		$mapping = $this->mapping( 'once.example' );

		$xpath = $this->xpath();
		$cells = $xpath->query( '//td[.//code[contains(text(), "once.example")]]' );

		$this->assertNotFalse( $cells );
		$this->assertSame( 1, $cells->length, 'one cell holds the hostname' );

		$occurrences = $xpath->query( '//td//*[text()="once.example"]' );

		$this->assertNotFalse( $occurrences );
		$this->assertSame( 1, $occurrences->length, 'and it is printed once, not twice' );

		unset( $mapping );
	}

	public function test_the_hostname_is_not_itself_a_link(): void {
		$this->mapping( 'notalink.example' );

		$xpath = $this->xpath();
		$links = $xpath->query( '//a[contains(text(), "notalink.example")]' );

		$this->assertNotFalse( $links );
		$this->assertSame( 0, $links->length, 'the hostname is a value to copy, not a link' );
	}

	public function test_the_hostname_carries_an_accessible_copy_control(): void {
		$this->mapping( 'copyme.example' );

		$xpath   = $this->xpath();
		$buttons = $xpath->query( '//span[@data-pd-copy][.//code[contains(text(),"copyme.example")]]//button[@data-pd-copy-button]' );

		$this->assertNotFalse( $buttons );
		$this->assertSame( 1, $buttons->length );

		$label = (string) $buttons->item( 0 )?->attributes?->getNamedItem( 'aria-label' )?->nodeValue;

		$this->assertStringContainsString( 'Copy copyme.example', $label );
		$this->assertSame(
			'button',
			strtolower( (string) $buttons->item( 0 )?->attributes?->getNamedItem( 'type' )?->nodeValue ),
			'a real button, so it is reachable by keyboard'
		);
	}

	public function test_the_copy_control_has_a_live_region_for_its_result(): void {
		$this->mapping( 'status.example' );

		$xpath  = $this->xpath();
		$status = $xpath->query( '//span[@data-pd-copy]//span[@role="status"][@aria-live="polite"]' );

		$this->assertNotFalse( $status );
		$this->assertGreaterThan( 0, $status->length );
	}

	public function test_the_row_offers_test_and_edit(): void {
		$mapping = $this->mapping( 'actions.example' );

		$xpath = $this->xpath();

		$test = $xpath->query( '//a[normalize-space(text())="Test" or .//text()[contains(., "Test")]][@target="_blank"]' );

		$this->assertNotFalse( $test );
		$this->assertGreaterThan( 0, $test->length, 'Test opens the mapped hostname' );

		$href = (string) $test->item( 0 )?->attributes?->getNamedItem( 'href' )?->nodeValue;
		$rel  = (string) $test->item( 0 )?->attributes?->getNamedItem( 'rel' )?->nodeValue;

		$this->assertStringStartsWith( 'https://', $href, 'the mapped hostname is addressed over HTTPS' );
		$this->assertStringContainsString( 'noopener', $rel, 'a new tab must not get a handle on this one' );

		$edit = $xpath->query( '//a[contains(@href, "mapping=' . $mapping->id . '")]' );

		$this->assertNotFalse( $edit );
		$this->assertGreaterThan( 0, $edit->length, 'Edit opens the detail screen' );
	}

	public function test_the_table_keeps_its_status_columns_and_gains_actions(): void {
		$this->mapping();

		$xpath   = $this->xpath();
		$headers = array();

		$cells = $xpath->query( '//table//thead//th' );

		foreach ( false === $cells ? array() : $cells as $th ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- a DOM property name.
			$headers[] = trim( (string) $th->textContent );
		}

		foreach ( array( 'Domain', 'Shows', 'Verification', 'Serving', 'Certificate', 'Actions' ) as $expected ) {
			$this->assertContains( $expected, $headers );
		}

		$this->assertSame( 'Actions', end( $headers ), 'actions come last' );
	}

	public function test_the_copy_component_is_reusable_for_dns_values(): void {
		$markup = Screen::copyable( 'post-domain-verify=abc123', 'Copy the record value' );

		$this->assertStringContainsString( 'data-pd-copy', $markup );
		$this->assertStringContainsString( 'post-domain-verify=abc123', $markup );
		$this->assertStringContainsString( 'aria-label="Copy the record value"', $markup );
	}

	public function test_the_copy_component_escapes_what_it_shows(): void {
		$markup = Screen::copyable( '<script>alert(1)</script>', 'Copy it' );

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
	}
}
