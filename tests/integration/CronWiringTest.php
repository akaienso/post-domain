<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Plugin;
use WP_UnitTestCase;

/**
 * Plans 06 and 08 place their cron registrations inside `Plugin::boot()`. They
 * live in per-subsystem `CronWiring` classes instead, so this asserts the one
 * thing that decomposition could break: that booting still registers them.
 */
final class CronWiringTest extends WP_UnitTestCase {

	public function test_booting_registers_the_verification_cron_topology(): void {
		Plugin::boot();

		$this->assertNotFalse( has_action( 'pd_verify_pending' ) );
		$this->assertNotFalse( has_action( 'pd_verify_established' ) );
	}
}
