<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\SystemClock;

final class SystemClockTest extends TestCase {

	public function test_now_is_utc(): void {
		$this->assertSame( 'UTC', ( new SystemClock() )->now()->getTimezone()->getName() );
	}

	public function test_mysql_format_matches_the_stored_shape(): void {
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			( new SystemClock() )->mysql()
		);
	}

	public function test_no_source_file_calls_current_time(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( str_contains( (string) file_get_contents( $file->getPathname() ), 'current_time(' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a site-local timestamp in a scheduling column drifts by hours across DST'
		);
	}
}
