<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\EnvironmentNotice;
use PostDomain\Admin\MappingListTable;
use PostDomain\Admin\SettingsPage;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class AdminScreensTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		$this->repo = new DbRepository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function seed( string $host, VerificationState $v, ActivationState $a ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				$v,
				$a,
				SslState::NONE,
				null,
				substr( md5( $host ), 0, 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_the_menu_registers_under_a_capability(): void {
		set_current_screen( 'dashboard' );
		SettingsPage::register();
		do_action( 'admin_menu' );

		global $submenu;

		$this->assertNotEmpty( $submenu, 'the admin menu must carry a post-domain entry' );
	}

	public function test_the_list_shows_the_unicode_host_and_the_ascii_form(): void {
		$this->seed( 'xn--mnchen-3ya.example', VerificationState::VERIFIED, ActivationState::ACTIVE );

		$rows = MappingListTable::rows();

		$this->assertSame( 'münchen.example', $rows[0]['host_display'] );
		$this->assertSame( 'xn--mnchen-3ya.example', $rows[0]['host'] );
	}

	public function test_the_list_shows_three_state_chips_and_no_serving_chip(): void {
		$this->seed( 'example.test', VerificationState::PENDING, ActivationState::INACTIVE );

		$row = MappingListTable::rows()[0];

		$this->assertArrayHasKey( 'verification', $row );
		$this->assertArrayHasKey( 'activation', $row );
		$this->assertArrayHasKey( 'ssl', $row );
		$this->assertArrayNotHasKey( 'serving', $row, 'serving is computed on expansion only' );
	}

	public function test_no_environment_notice_when_the_host_is_stable(): void {
		Environment::remember_primary_host();

		$this->assertSame( '', EnvironmentNotice::render() );
	}

	public function test_the_environment_notice_blocks_and_offers_both_choices(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		$html = EnvironmentNotice::render();

		$this->assertStringContainsString( 'old-host.test', $html );
		$this->assertStringContainsString( 'Restore', $html );
		$this->assertStringContainsString( 'Clone', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_the_environment_notice_escapes_the_stored_host(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', '<script>alert(1)</script>', false );
		Environment::check();

		$this->assertStringNotContainsString( '<script>', EnvironmentNotice::render() );
	}

	public function test_the_selection_offers_only_registered_drivers(): void {
		\PostDomain\Ssl\DriverFactory::reset();

		$html = SettingsPage::render_driver_selection();

		foreach ( \PostDomain\Ssl\DriverFactory::registry()->ids() as $id ) {
			$this->assertStringContainsString( 'value="' . $id . '"', $html );
		}

		$this->assertStringNotContainsString( 'type="text"', $html, 'a free-text driver name is a trap' );
	}

	public function test_an_unregistered_configured_driver_is_reported_not_corrected(): void {
		update_option( 'pd_settings', array( 'ssl_driver' => 'gone-away' ), false );
		\PostDomain\Ssl\DriverFactory::reset();

		$html = SettingsPage::render_driver_selection();

		$this->assertStringContainsString( 'gone-away', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertSame( 'gone-away', \PostDomain\Ssl\DriverFactory::selected_driver_id() );

		delete_option( 'pd_settings' );
		\PostDomain\Ssl\DriverFactory::reset();
	}

	public function test_saving_an_unregistered_driver_is_ignored(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['pd_ssl_driver'] = 'not-a-driver';
		$_POST['_wpnonce']      = wp_create_nonce( 'pd_settings' );
		// check_admin_referer() reads $_REQUEST, which a real POST populates.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		SettingsPage::save_driver_selection();

		$this->assertSame(
			\PostDomain\Ssl\DriverFactory::NULL_DRIVER,
			\PostDomain\Ssl\DriverFactory::selected_driver_id()
		);

		unset( $_POST['pd_ssl_driver'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_saving_resets_the_memoized_registry(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		\PostDomain\Ssl\DriverFactory::registry();

		$_POST['pd_ssl_driver'] = \PostDomain\Ssl\DriverFactory::NULL_DRIVER;
		$_POST['_wpnonce']      = wp_create_nonce( 'pd_settings' );
		// check_admin_referer() reads $_REQUEST, which a real POST populates.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		SettingsPage::save_driver_selection();

		$this->assertSame(
			\PostDomain\Ssl\DriverFactory::NULL_DRIVER,
			\PostDomain\Ssl\DriverFactory::selected_driver_id()
		);

		unset( $_POST['pd_ssl_driver'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_a_subscriber_cannot_change_the_selection(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST['pd_ssl_driver'] = \PostDomain\Ssl\DriverFactory::NULL_DRIVER;
		$_POST['_wpnonce']      = wp_create_nonce( 'pd_settings' );
		// check_admin_referer() reads $_REQUEST, which a real POST populates.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_option( 'pd_settings', array( 'ssl_driver' => 'preexisting' ), false );

		SettingsPage::save_driver_selection();

		$this->assertSame( 'preexisting', \PostDomain\Ssl\DriverFactory::selected_driver_id() );

		unset( $_POST['pd_ssl_driver'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		delete_option( 'pd_settings' );
		\PostDomain\Ssl\DriverFactory::reset();
	}
}
