<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Wiring;
use PostDomain\Http\ProbeEndpoint;
use WP_UnitTestCase;

/**
 * Plan 11 prescribes these registrations inside `Plugin::boot()`. They live on
 * `Admin\Wiring` instead, so this proves the one line the composition root adds
 * reaches both of them.
 */
final class WiringTest extends WP_UnitTestCase {

	public function test_the_admin_menu_is_registered_when_the_request_is_an_admin_one(): void {
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Wiring::register();
		do_action( 'admin_menu' );

		global $submenu;

		$this->assertNotEmpty( $submenu, 'Wiring::register() must reach SettingsPage::register()' );
	}

	public function test_the_probe_endpoint_is_registered_outside_the_admin(): void {
		Wiring::register();
		do_action( 'plugins_loaded' );

		$registered = false;

		foreach ( $GLOBALS['wp_filter']['parse_request']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof ProbeEndpoint ) {
					$registered = true;
				}
			}
		}

		$this->assertTrue( $registered, 'Wiring::register() must reach ProbeEndpoint::boot()' );
	}
}
