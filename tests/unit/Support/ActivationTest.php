<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\Activation;

final class ActivationTest extends TestCase {

	public function test_no_refusal_on_a_supported_single_site(): void {
		$this->assertNull( Activation::refusal( '8.1.0', '6.4', false ) );
	}

	public function test_multisite_refusal_explains_why(): void {
		$refusal = Activation::refusal( '8.2.0', '6.5', true );

		$this->assertNotNull( $refusal );
		$this->assertStringContainsString( 'multisite', $refusal );
		$this->assertStringContainsString( 'different problem', $refusal );
	}

	public function test_version_refusal_names_the_floor(): void {
		$refusal = Activation::refusal( '8.0.0', '6.4', false );

		$this->assertNotNull( $refusal );
		$this->assertStringContainsString( 'PHP 8.1', $refusal );
	}

	public function test_bootstrap_registers_the_activation_hook_before_the_multisite_return(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../post-domain.php' );

		$autoload  = strpos( $source, "require_once __DIR__ . '/vendor/autoload.php';" );
		$hook      = strpos( $source, 'register_activation_hook' );
		$multisite = strpos( $source, 'is_multisite()' );

		$this->assertNotFalse( $autoload, 'the bootstrap must require the autoloader' );
		$this->assertNotFalse( $hook, 'the bootstrap must register the activation hook' );
		$this->assertNotFalse( $multisite, 'the bootstrap must check for multisite' );
		$this->assertLessThan( $hook, $autoload, 'autoloader must load before the hook registers' );
		$this->assertLessThan( $multisite, $hook, 'the hook must register before the multisite return' );
	}
}
