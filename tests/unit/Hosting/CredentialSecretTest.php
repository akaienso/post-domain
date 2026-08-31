<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\CredentialSecret;

/**
 * Every way PHP has of turning an object into text, applied to the one object
 * that holds a token.
 *
 * @package PostDomain
 */
final class CredentialSecretTest extends TestCase {

	private const TOKEN = 'wfy_test_0000000000000000';

	public function test_the_secret_is_readable_only_through_reveal(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$this->assertSame( self::TOKEN, $secret->reveal() );
	}

	public function test_print_r_does_not_contain_the_token(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$printed = print_r( $secret, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		$this->assertStringNotContainsString( self::TOKEN, (string) $printed );
	}

	public function test_var_export_does_not_contain_the_token(): void {
		$secret = new CredentialSecret( self::TOKEN );

		// var_export ignores __debugInfo entirely and prints raw properties,
		// which is exactly why the plaintext is not held in one.
		$exported = var_export( $secret, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$this->assertStringNotContainsString( self::TOKEN, (string) $exported );
	}

	public function test_var_dump_does_not_contain_the_token(): void {
		$secret = new CredentialSecret( self::TOKEN );

		ob_start();
		var_dump( $secret ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
		$dump = (string) ob_get_clean();

		$this->assertStringNotContainsString( self::TOKEN, $dump );
	}

	public function test_json_encode_does_not_contain_the_token(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$encoded = json_encode( $secret ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertStringNotContainsString( self::TOKEN, (string) $encoded );
	}

	public function test_string_interpolation_does_not_contain_the_token(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$this->assertStringNotContainsString( self::TOKEN, "value: {$secret}" );
		$this->assertSame( CredentialSecret::REDACTED, (string) $secret );
	}

	public function test_serialize_does_not_contain_the_token(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$serialized = serialize( $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$this->assertStringNotContainsString( self::TOKEN, $serialized );
	}

	public function test_an_exception_holding_a_secret_does_not_print_it(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$exception = new \RuntimeException( 'failed for ' . (string) $secret );

		$this->assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
		$this->assertStringNotContainsString( self::TOKEN, $exception->getTraceAsString() );
	}

	public function test_forget_makes_the_secret_unreadable(): void {
		$secret = new CredentialSecret( self::TOKEN );
		$secret->forget();

		$this->assertSame( '', $secret->reveal() );
		$this->assertTrue( $secret->is_empty() );
	}

	public function test_a_clone_keeps_its_own_copy(): void {
		$secret = new CredentialSecret( self::TOKEN );
		$copy   = clone $secret;

		$copy->forget();

		$this->assertSame( self::TOKEN, $secret->reveal(), 'the clone must not empty the original' );
	}

	public function test_equality_is_by_value(): void {
		$secret = new CredentialSecret( self::TOKEN );

		$this->assertTrue( $secret->equals( new CredentialSecret( self::TOKEN ) ) );
		$this->assertFalse( $secret->equals( new CredentialSecret( 'wfy_test_1111111111111111' ) ) );
	}

	public function test_two_secrets_do_not_share_a_handle(): void {
		$first  = new CredentialSecret( self::TOKEN );
		$second = new CredentialSecret( 'wfy_test_1111111111111111' );

		unset( $second );

		$this->assertSame( self::TOKEN, $first->reveal() );
	}
}
