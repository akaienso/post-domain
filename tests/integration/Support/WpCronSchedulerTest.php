<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Support\WpCronScheduler;
use WP_UnitTestCase;

final class WpCronSchedulerTest extends WP_UnitTestCase {

	public function test_scheduling_and_reading_back_a_single_event(): void {
		$scheduler = new WpCronScheduler();
		$at        = new \DateTimeImmutable( '+10 minutes', new \DateTimeZone( 'UTC' ) );

		$scheduler->schedule( 'pd_test_hook', $at, array( 7 ) );

		$this->assertSame(
			$at->getTimestamp(),
			$scheduler->next( 'pd_test_hook', array( 7 ) )?->getTimestamp()
		);
	}

	public function test_unscheduling_removes_the_event(): void {
		$scheduler = new WpCronScheduler();
		$scheduler->schedule( 'pd_test_hook', new \DateTimeImmutable( '+5 minutes', new \DateTimeZone( 'UTC' ) ), array( 8 ) );
		$scheduler->unschedule( 'pd_test_hook', array( 8 ) );

		$this->assertNull( $scheduler->next( 'pd_test_hook', array( 8 ) ) );
	}
}
