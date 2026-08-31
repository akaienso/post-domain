<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\CredentialCipher;
use PostDomain\Hosting\CredentialException;
use PostDomain\Hosting\CredentialKeyring;
use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\CredentialSecret;
use PostDomain\Hosting\CredentialSource;

require_once __DIR__ . '/CredentialFunctionStubs.php';

/**
 * The store's behaviour, against in-memory stand-ins for the option functions.
 *
 * The real database round-trip lives in the integration suite; what is asserted
 * here is the logic that does not need one — precedence, fail-closed refusal,
 * invalidation and redaction.
 *
 * @package PostDomain
 */
final class CredentialStoreTest extends TestCase {

	private const TOKEN = 'wfy_test_0000000000000000';
	private const OTHER = 'wfy_test_1111111111111111';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['pd_credential_test_options']  = array();
		$GLOBALS['pd_credential_test_autoload'] = array();
		$GLOBALS['pd_credential_test_filters']  = array();
		$GLOBALS['pd_credential_test_actions']  = array();
	}

	protected function tearDown(): void {
		$GLOBALS['pd_credential_test_options']  = array();
		$GLOBALS['pd_credential_test_autoload'] = array();
		$GLOBALS['pd_credential_test_filters']  = array();
		$GLOBALS['pd_credential_test_actions']  = array();

		parent::tearDown();
	}

	private function store( ?CredentialCipher $cipher = null, string $seed = 'salt-a' ): CredentialOptionStore {
		return new CredentialOptionStore( new CredentialKeyring( $seed, $seed . '-second' ), $cipher );
	}

	/** @return mixed */
	private function stored_option() {
		return $GLOBALS['pd_credential_test_options'][ CredentialOptionStore::OPTION ] ?? null;
	}

	public function test_nothing_is_configured_to_begin_with(): void {
		$status = $this->store()->status();

		$this->assertFalse( $status->configured );
		$this->assertSame( CredentialSource::NONE, $status->source );
		$this->assertNull( $status->fingerprint );
		$this->assertTrue( $status->is_editable() );
	}

	public function test_a_stored_credential_round_trips(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$secret = $store->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::TOKEN, $secret->reveal() );
	}

	public function test_the_stored_value_is_not_the_token(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$raw = (string) $this->stored_option();

		$this->assertNotSame( self::TOKEN, $raw );
		$this->assertStringNotContainsString( self::TOKEN, $raw );
		$this->assertStringNotContainsString( bin2hex( self::TOKEN ), $raw );
		$this->assertStringNotContainsString( base64_encode( self::TOKEN ), $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringStartsWith( 'pdc1.', $raw );
	}

	public function test_the_option_is_not_autoloaded(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$this->assertFalse( $GLOBALS['pd_credential_test_autoload'][ CredentialOptionStore::OPTION ] );
	}

	public function test_rotated_salts_fail_closed(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		// Same option table, different key material: what a restored database
		// on a new host with fresh wp-config.php salts looks like.
		$rotated = $this->store( null, 'salt-b' );

		$this->assertNull( $rotated->reveal(), 'a wrong key must yield nothing, never garbage' );
		$this->assertFalse( $rotated->status()->configured );
	}

	public function test_a_tampered_option_fails_closed(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$raw      = (string) $this->stored_option();
		$last     = substr( $raw, -1 );
		$tampered = substr( $raw, 0, -1 ) . ( '0' === $last ? '1' : '0' );

		$GLOBALS['pd_credential_test_options'][ CredentialOptionStore::OPTION ] = $tampered;

		$this->assertNull( $this->store()->reveal() );
	}

	public function test_a_plaintext_token_left_in_the_option_is_not_read_back(): void {
		$GLOBALS['pd_credential_test_options'][ CredentialOptionStore::OPTION ] = self::TOKEN;

		$this->assertNull( $this->store()->reveal(), 'only a versioned envelope is a credential' );
	}

	public function test_without_a_secure_primitive_the_store_fails_and_persists_nothing(): void {
		// An empty available-set is exactly the state `CredentialCipher::detect()`
		// returns on a PHP build with neither libsodium nor an AES-GCM-capable
		// OpenSSL, so this exercises the real fail-closed branch rather than a
		// stand-in for it.
		$store = $this->store( new CredentialCipher( array() ) );

		try {
			$store->put( new CredentialSecret( self::TOKEN ) );
			$this->fail( 'expected a CredentialException' );
		} catch ( CredentialException $e ) {
			$this->assertSame( CredentialException::NO_SECURE_PRIMITIVE, $e->reason() );
			$this->assertStringNotContainsString( self::TOKEN, $e->getMessage() );
		}

		$this->assertSame( array(), $GLOBALS['pd_credential_test_options'], 'nothing at all may be written' );
		$this->assertNull( $store->reveal() );
		$this->assertFalse( $store->status()->configured );
	}

	public function test_an_empty_credential_is_refused(): void {
		$store = $this->store();

		$this->expectException( CredentialException::class );

		$store->put( new CredentialSecret( '' ) );
	}

	public function test_the_filter_takes_precedence_over_a_stored_value(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$GLOBALS['pd_credential_test_filters'][ CredentialOptionStore::FILTER ] = static fn (): string => self::OTHER;

		$fresh  = $this->store();
		$secret = $fresh->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::OTHER, $secret->reveal() );
		$this->assertSame( CredentialSource::FILTER, $fresh->status()->source );
		$this->assertTrue( $fresh->status()->is_external() );
		$this->assertFalse( $fresh->status()->is_editable() );
	}

	public function test_an_externally_provided_credential_cannot_be_overwritten(): void {
		$GLOBALS['pd_credential_test_filters'][ CredentialOptionStore::FILTER ] = static fn (): string => self::OTHER;

		$store = $this->store();

		try {
			$store->put( new CredentialSecret( self::TOKEN ) );
			$this->fail( 'expected a CredentialException' );
		} catch ( CredentialException $e ) {
			$this->assertSame( CredentialException::EXTERNALLY_PROVIDED, $e->reason() );
			$this->assertStringNotContainsString( self::TOKEN, $e->getMessage() );
		}

		$this->assertArrayNotHasKey( CredentialOptionStore::OPTION, $GLOBALS['pd_credential_test_options'] );
	}

	public function test_an_empty_filter_value_falls_through_to_storage(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$GLOBALS['pd_credential_test_filters'][ CredentialOptionStore::FILTER ] = static fn (): string => '';

		$fresh = $this->store();

		$this->assertSame( CredentialSource::DATABASE, $fresh->status()->source );
	}

	public function test_replacing_a_credential_invalidates_the_site_binding(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );
		$store->remember_binding( 'wordify:team-1:site-1' );

		$this->assertSame( 'wordify:team-1:site-1', $store->binding() );

		$store->put( new CredentialSecret( self::OTHER ) );

		$this->assertNull( $store->binding(), 'a new token has proved nothing about a team or a site' );
		$this->assertContains( 'pd_wordify_credential_replaced', $GLOBALS['pd_credential_test_actions'] );

		$secret = $store->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::OTHER, $secret->reveal(), 'the cached plaintext must be dropped too' );
	}

	public function test_forgetting_a_credential_invalidates_the_site_binding(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );
		$store->remember_binding( 'wordify:team-1:site-1' );

		$store->forget();

		$this->assertNull( $store->binding() );
		$this->assertNull( $store->reveal() );
		$this->assertArrayNotHasKey( CredentialOptionStore::OPTION, $GLOBALS['pd_credential_test_options'] );
	}

	public function test_the_fingerprint_is_not_the_token_and_is_stable(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$fingerprint = $store->status()->fingerprint;

		$this->assertIsString( $fingerprint );
		$this->assertSame( 8, strlen( $fingerprint ) );
		$this->assertStringNotContainsString( $fingerprint, self::TOKEN );
		$this->assertStringNotContainsString( self::TOKEN, $fingerprint );
		$this->assertSame( $fingerprint, $this->store()->status()->fingerprint );
	}

	public function test_the_fingerprint_is_keyed_so_it_differs_between_installations(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );
		$here = (string) $this->store()->status()->fingerprint;

		$elsewhere = ( new CredentialKeyring( 'salt-b', 'salt-b-second' ) )->fingerprint( 'wordify_api_token', self::TOKEN );

		$this->assertNotSame( $here, $elsewhere, 'an unkeyed digest would let a guess be confirmed offline' );
	}

	public function test_a_different_token_gets_a_different_fingerprint(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );
		$first = $store->status()->fingerprint;

		$store->put( new CredentialSecret( self::OTHER ) );

		$this->assertNotSame( $first, $store->status()->fingerprint );
	}

	public function test_the_store_does_not_print_the_token(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$printed  = print_r( $store, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$exported = var_export( $store, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$encoded  = json_encode( $store ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		ob_start();
		var_dump( $store ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
		$dumped = (string) ob_get_clean();

		foreach ( array( (string) $printed, (string) $exported, (string) $encoded, $dumped, "store: {$store}" ) as $text ) {
			$this->assertStringNotContainsString( self::TOKEN, $text );
		}

		$this->assertStringContainsString( 'configured', (string) $encoded );
	}

	public function test_the_store_does_not_print_the_key_material(): void {
		$store = $this->store( null, 'a-very-secret-salt' );
		$store->put( new CredentialSecret( self::TOKEN ) );

		$printed  = print_r( $store, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$exported = var_export( $store, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$this->assertStringNotContainsString( 'a-very-secret-salt', (string) $printed );
		$this->assertStringNotContainsString( 'a-very-secret-salt', (string) $exported );
	}

	public function test_the_status_object_carries_no_token(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$status  = $store->status();
		$encoded = json_encode( $status ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$printed = print_r( $status, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		$this->assertStringNotContainsString( self::TOKEN, (string) $encoded );
		$this->assertStringNotContainsString( self::TOKEN, (string) $printed );
		$this->assertStringNotContainsString( self::TOKEN, "status: {$status}" );
	}

	public function test_a_keyring_without_material_refuses_to_exist(): void {
		$this->expectException( CredentialException::class );

		new CredentialKeyring( '', '' );
	}
}
