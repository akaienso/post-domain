<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {

	public function test_the_plugin_is_loaded(): void {
		$this->assertTrue( class_exists( \PostDomain\Support\Environment::class ) );
	}

	public function test_this_install_is_single_site(): void {
		$this->assertFalse( is_multisite(), 'the integration suite must run on single site' );
	}

	public function test_the_activation_guard_allows_this_install(): void {
		$this->assertNull(
			\PostDomain\Support\Activation::refusal( PHP_VERSION, get_bloginfo( 'version' ), is_multisite() )
		);
	}

	public function test_the_activation_guard_refuses_a_network(): void {
		$refusal = \PostDomain\Support\Activation::refusal( PHP_VERSION, get_bloginfo( 'version' ), true );

		$this->assertNotNull( $refusal );
		$this->assertStringContainsString( 'multisite', $refusal );
	}
}
