<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\CloudflareStatusMap;

final class CloudflareStatusMapTest extends TestCase {

	public function test_active_and_active_is_the_only_route_to_local_active(): void {
		$this->assertSame( SslState::ACTIVE, CloudflareStatusMap::combine( 'active', 'active' )['state'] );
	}

	public function test_active_hostname_with_pending_ssl_is_pending(): void {
		$this->assertSame(
			SslState::PENDING_VALIDATION,
			CloudflareStatusMap::combine( 'active', 'pending_validation' )['state']
		);
	}

	public function test_pending_hostname_with_active_ssl_is_pending(): void {
		$this->assertSame(
			SslState::PENDING_VALIDATION,
			CloudflareStatusMap::combine( 'pending', 'active' )['state'],
			'production ready needs both axes active'
		);
	}

	public function test_a_failed_axis_fails_the_combination(): void {
		$this->assertSame( SslState::FAILED, CloudflareStatusMap::combine( 'moved', 'active' )['state'] );
		$this->assertSame( SslState::FAILED, CloudflareStatusMap::combine( 'active', 'expired' )['state'] );
	}

	/**
	 * @dataProvider unknown_values
	 */
	public function test_an_unknown_value_is_pending_and_flagged( ?string $hostname, ?string $ssl ): void {
		$result = CloudflareStatusMap::combine( $hostname, $ssl );

		$this->assertSame( SslState::PENDING_VALIDATION, $result['state'] );
		$this->assertTrue( $result['unknown'] );
	}

	/** @return array<string, array{0: string|null, 1: string|null}> */
	public static function unknown_values(): array {
		return array(
			'future hostname value' => array( 'some_future_state', 'active' ),
			'future ssl value'      => array( 'active', 'some_future_state' ),
			'null hostname'         => array( null, 'active' ),
			'null ssl'              => array( 'active', null ),
		);
	}

	public function test_an_unknown_value_can_never_produce_failed_or_revoked(): void {
		foreach ( array( 'unknown_a', 'unknown_b' ) as $value ) {
			$state = CloudflareStatusMap::combine( $value, $value )['state'];

			$this->assertNotSame( SslState::FAILED, $state );
			$this->assertNotSame( SslState::REVOKED, $state );
		}
	}

	public function test_caa_errors_are_classified_from_the_error_arrays(): void {
		$code = CloudflareStatusMap::classify_errors(
			array(),
			array( array( 'message' => 'SERVFAIL looking up CAA for app.example.com' ) )
		);

		$this->assertSame( 'caa_error', $code );
	}

	public function test_caa_is_not_a_status_axis_value(): void {
		/** @var array{hostname: array<string, string>, ssl: array<string, string>} $map */
		$map = require dirname( __DIR__, 3 ) . '/references/cloudflare-status-map.php';

		$this->assertArrayNotHasKey( 'caa_error', $map['hostname'] );
		$this->assertArrayNotHasKey( 'caa_error', $map['ssl'] );
	}

	public function test_empty_error_arrays_classify_to_nothing(): void {
		$this->assertNull( CloudflareStatusMap::classify_errors( array(), array() ) );
	}
}
