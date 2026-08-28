<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\TrustedProxy;

final class TrustedProxyTest extends TestCase {

	public function test_forwarded_headers_are_ignored_by_default(): void {
		$proxy = new TrustedProxy( array() );

		$this->assertSame(
			'real.example',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'real.example',
					'HTTP_X_FORWARDED_HOST' => 'spoofed.example',
					'REMOTE_ADDR'           => '203.0.113.9',
				)
			)
		);
	}

	public function test_forwarded_host_is_honoured_from_a_trusted_proxy(): void {
		$proxy = new TrustedProxy( array( '10.0.0.0/8' ) );

		$this->assertSame(
			'forwarded.example',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'lb.internal',
					'HTTP_X_FORWARDED_HOST' => 'forwarded.example',
					'REMOTE_ADDR'           => '10.1.2.3',
				)
			)
		);
	}

	public function test_forwarded_host_is_ignored_from_an_untrusted_address(): void {
		$proxy = new TrustedProxy( array( '10.0.0.0/8' ) );

		$this->assertSame(
			'lb.internal',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'lb.internal',
					'HTTP_X_FORWARDED_HOST' => 'forwarded.example',
					'REMOTE_ADDR'           => '203.0.113.9',
				)
			)
		);
	}

	public function test_only_the_first_forwarded_host_is_taken(): void {
		$proxy = new TrustedProxy( array( '10.0.0.0/8' ) );

		$this->assertSame(
			'first.example',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'lb.internal',
					'HTTP_X_FORWARDED_HOST' => 'first.example, second.example',
					'REMOTE_ADDR'           => '10.1.2.3',
				)
			)
		);
	}

	public function test_invalid_cidr_entries_are_dropped(): void {
		$proxy = new TrustedProxy( array( 'not-a-cidr', '10.0.0.0/8' ) );

		$this->assertSame( array( '10.0.0.0/8' ), $proxy->cidrs() );
	}

	public function test_a_missing_host_header_yields_an_empty_authority(): void {
		$this->assertSame( '', ( new TrustedProxy( array() ) )->served_authority( array() ) );
	}
}
