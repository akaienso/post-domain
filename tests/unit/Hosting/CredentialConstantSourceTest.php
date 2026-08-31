<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\CredentialException;
use PostDomain\Hosting\CredentialKeyring;
use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\CredentialSecret;
use PostDomain\Hosting\CredentialSource;

require_once __DIR__ . '/CredentialFunctionStubs.php';

/**
 * `PD_WORDIFY_TOKEN`, the wp-config.php escape hatch.
 *
 * A constant cannot be undefined, so this class runs in its own process: every
 * other test in the suite must keep seeing an installation where the constant
 * is absent, and one that defined it in the shared process would silently make
 * every later credential assertion meaningless.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * @package PostDomain
 */
final class CredentialConstantSourceTest extends TestCase {

	private const CONSTANT_TOKEN = 'wfy_test_2222222222222222';
	private const STORED_TOKEN   = 'wfy_test_0000000000000000';

	private function store(): CredentialOptionStore {
		return new CredentialOptionStore( new CredentialKeyring( 'salt-a', 'salt-a-second' ) );
	}

	private function reset_options(): void {
		$GLOBALS['pd_credential_test_options']  = array();
		$GLOBALS['pd_credential_test_autoload'] = array();
		$GLOBALS['pd_credential_test_filters']  = array();
		$GLOBALS['pd_credential_test_actions']  = array();
	}

	public function test_the_constant_takes_precedence_over_a_stored_value(): void {
		$this->reset_options();

		$this->store()->put( new CredentialSecret( self::STORED_TOKEN ) );

		define( CredentialOptionStore::CONSTANT, self::CONSTANT_TOKEN );

		$store  = $this->store();
		$secret = $store->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::CONSTANT_TOKEN, $secret->reveal() );
		$this->assertSame( CredentialSource::CONSTANT, $store->status()->source );
		$this->assertTrue( $store->status()->configured );
		$this->assertTrue( $store->status()->is_external() );
		$this->assertFalse( $store->status()->is_editable() );
	}

	public function test_the_constant_refuses_to_be_overwritten(): void {
		$this->reset_options();

		define( CredentialOptionStore::CONSTANT, self::CONSTANT_TOKEN );

		$store = $this->store();

		try {
			$store->put( new CredentialSecret( self::STORED_TOKEN ) );
			$this->fail( 'expected a CredentialException' );
		} catch ( CredentialException $e ) {
			$this->assertSame( CredentialException::EXTERNALLY_PROVIDED, $e->reason() );
			$this->assertStringNotContainsString( self::STORED_TOKEN, $e->getMessage() );
			$this->assertStringNotContainsString( self::CONSTANT_TOKEN, $e->getMessage() );
		}

		$this->assertArrayNotHasKey(
			CredentialOptionStore::OPTION,
			$GLOBALS['pd_credential_test_options'],
			'an operator who supplies the token from wp-config.php gets no database copy'
		);
	}

	public function test_the_constant_name_is_exactly_pd_wordify_token(): void {
		$this->assertSame( 'PD_WORDIFY_TOKEN', CredentialOptionStore::CONSTANT );
		$this->assertSame( 'pd_wordify_token', CredentialOptionStore::FILTER );
	}

	public function test_an_empty_constant_falls_through_to_storage(): void {
		$this->reset_options();

		define( CredentialOptionStore::CONSTANT, '' );

		$store = $this->store();
		$store->put( new CredentialSecret( self::STORED_TOKEN ) );

		$this->assertSame( CredentialSource::DATABASE, $store->status()->source );
	}
}
