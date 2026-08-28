<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\DriverIdentity;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Ssl\NullDriver;
use PostDomain\Tests\Unit\Ssl\Fixtures\IdentityDriver;

final class DriverIdentityTest extends TestCase {

	public function test_the_null_driver_is_valid(): void {
		$identity = DriverIdentity::of( new NullDriver() );

		$this->assertInstanceOf( DriverIdentity::class, $identity );
		$this->assertSame( 'null', $identity->driver_id );
		$this->assertSame( 'none', $identity->environment_id );
	}

	public function test_a_cloudflare_shaped_identity_is_valid(): void {
		$identity = DriverIdentity::of( new IdentityDriver( 'cloudflare-saas', 'cf-zone:0123456789abcdef' ) );

		$this->assertInstanceOf( DriverIdentity::class, $identity );
		$this->assertSame( 'cf-zone:0123456789abcdef', $identity->environment_id );
	}

	/**
	 * @dataProvider rejected_identities
	 */
	public function test_a_malformed_identity_is_refused_by_name( string $id, string $environment, string $reason ): void {
		$result = DriverIdentity::of( new IdentityDriver( $id, $environment ) );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( $reason, $result->reason );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function rejected_identities(): array {
		return array(
			'empty driver id'        => array( '', 'ok', 'driver_id_length' ),
			'overlong driver id'     => array( str_repeat( 'a', 61 ), 'ok', 'driver_id_length' ),
			'uppercase driver id'    => array( 'CloudFlare', 'ok', 'driver_id_syntax' ),
			'spaced driver id'       => array( 'cloud flare', 'ok', 'driver_id_syntax' ),
			'newline in driver id'   => array( "cf\nsaas", 'ok', 'driver_id_syntax' ),
			'leading dash driver id' => array( '-cf', 'ok', 'driver_id_syntax' ),
			'empty environment'      => array( 'cf', '', 'environment_id_length' ),
			'overlong environment'   => array( 'cf', str_repeat( 'z', 191 ), 'environment_id_length' ),
			'newline environment'    => array( 'cf', "zone:1\nzone:2", 'environment_id_syntax' ),
			'tab environment'        => array( 'cf', "zone:\t1", 'environment_id_syntax' ),
			'null byte environment'  => array( 'cf', "zone:\0", 'environment_id_syntax' ),
			'non ascii environment'  => array( 'cf', 'zone:münchen', 'environment_id_syntax' ),
		);
	}

	public function test_an_unstable_environment_is_refused(): void {
		$result = DriverIdentity::of( IdentityDriver::unstable_environment( 'cf' ) );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'environment_id_unstable', $result->reason );
	}

	public function test_an_unstable_driver_id_is_refused(): void {
		// An id that moves cannot be resolved back from a durable binding.
		$result = DriverIdentity::of( IdentityDriver::unstable_id() );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'driver_id_unstable', $result->reason );
	}

	public function test_a_refusal_renders_safely_for_an_operator(): void {
		$result = DriverIdentity::of( new IdentityDriver( "bad\x01id", 'ok' ) );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertMatchesRegularExpression( '/^[\x20-\x7E]*$/', $result->driver_id );
	}

	public function test_the_column_limits_match_the_schema(): void {
		// If these drift from Plan 02's column widths, a valid identity would be
		// silently truncated by MySQL instead of refused here.
		$this->assertSame( 60, DriverIdentity::MAX_DRIVER_ID );
		$this->assertSame( 190, DriverIdentity::MAX_ENVIRONMENT_ID );
	}
}
