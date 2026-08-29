<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\Environment;

final class EnvironmentTest extends TestCase {

	public function test_supported_environment_has_no_blocker(): void {
		$this->assertNull( Environment::blocker( '8.1.0', '6.4', false ) );
		$this->assertNull( Environment::blocker( '8.3.2', '6.7.1', false ) );
	}

	public function test_multisite_is_blocked(): void {
		$this->assertSame(
			'post-domain does not support multisite networks.',
			Environment::blocker( '8.2.0', '6.5', true )
		);
	}

	public function test_php_below_floor_is_blocked(): void {
		$this->assertSame(
			'post-domain requires PHP 8.1 or later; this site runs 8.0.30.',
			Environment::blocker( '8.0.30', '6.5', false )
		);
	}

	public function test_wordpress_below_floor_is_blocked(): void {
		$this->assertSame(
			'post-domain requires WordPress 6.4 or later; this site runs 6.3.2.',
			Environment::blocker( '8.1.0', '6.3.2', false )
		);
	}

	public function test_multisite_outranks_a_version_blocker(): void {
		$this->assertSame(
			'post-domain does not support multisite networks.',
			Environment::blocker( '8.0.0', '6.0', true )
		);
	}
}
