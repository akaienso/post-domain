<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Hosting\CredentialCipher;
use PostDomain\Hosting\CredentialException;
use PostDomain\Hosting\CredentialKeyring;
use PostDomain\Hosting\CredentialOptionStore;
use PostDomain\Hosting\CredentialSecret;
use PostDomain\Hosting\CredentialSource;
use WP_UnitTestCase;

/**
 * The credential store against a real WordPress and a real `wp_options` row.
 *
 * The load-bearing assertion is `test_the_row_in_wp_options_is_not_the_token()`:
 * it reads the column directly with `$wpdb`, bypassing the options cache, so
 * what it inspects is the bytes an operator would find in a database dump.
 *
 * `WP_UnitTestCase`, not `OwnedSessionTestCase`: nothing here needs a committed
 * transition, and rolling every write back is what keeps this safe to run
 * against a database other suites share.
 *
 * The `PD_WORDIFY_TOKEN` constant is deliberately not exercised here — a
 * constant cannot be undefined and would leak into every later test in the
 * process. It has its own process-isolated unit test,
 * `PostDomain\Tests\Unit\Hosting\CredentialConstantSourceTest`.
 *
 * @package PostDomain
 */
final class CredentialOptionStoreTest extends WP_UnitTestCase {

	private const TOKEN = 'wfy_test_0000000000000000';
	private const OTHER = 'wfy_test_1111111111111111';

	public function set_up(): void {
		parent::set_up();

		delete_option( CredentialOptionStore::OPTION );
		delete_option( CredentialOptionStore::BINDING_OPTION );
	}

	public function tear_down(): void {
		remove_all_filters( CredentialOptionStore::FILTER );

		delete_option( CredentialOptionStore::OPTION );
		delete_option( CredentialOptionStore::BINDING_OPTION );

		parent::tear_down();
	}

	private function store(): CredentialOptionStore {
		return CredentialOptionStore::for_wordpress();
	}

	/** Reads the column itself, so the options cache cannot answer for it. */
	private function raw_option_value(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB
				CredentialOptionStore::OPTION
			)
		);

		return is_string( $value ) ? $value : null;
	}

	public function test_the_row_in_wp_options_is_not_the_token(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		$raw = $this->raw_option_value();

		$this->assertIsString( $raw, 'the credential should have been written' );
		$this->assertNotSame( self::TOKEN, $raw );
		$this->assertStringNotContainsString( self::TOKEN, $raw );
		$this->assertStringNotContainsString( bin2hex( self::TOKEN ), $raw, 'hex is not encryption' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringNotContainsString( base64_encode( self::TOKEN ), $raw, 'base64 is not encryption' );
		$this->assertStringStartsWith( 'pdc1.', $raw, 'a versioned envelope, so a later format is unambiguous' );
	}

	public function test_no_other_option_holds_the_token(): void {
		global $wpdb;

		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		$matches = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s", // phpcs:ignore WordPress.DB
				'%' . $wpdb->esc_like( self::TOKEN ) . '%'
			)
		);

		$this->assertSame( array(), $matches );
	}

	public function test_the_credential_option_is_not_autoloaded(): void {
		global $wpdb;

		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		$autoload = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB
				CredentialOptionStore::OPTION
			)
		);

		$this->assertNotSame( 'yes', $autoload, 'a credential has no business on every request' );
	}

	public function test_a_stored_credential_round_trips_through_the_database(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		// A completely fresh store, reading the row back the way a later request
		// would, with the key derived from wp_salt() rather than kept in memory.
		$secret = $this->store()->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::TOKEN, $secret->reveal() );

		$status = $this->store()->status();

		$this->assertTrue( $status->configured );
		$this->assertSame( CredentialSource::DATABASE, $status->source );
		$this->assertTrue( $status->is_editable() );
	}

	public function test_a_tampered_row_is_rejected(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		$raw  = (string) $this->raw_option_value();
		$last = substr( $raw, -1 );

		update_option( CredentialOptionStore::OPTION, substr( $raw, 0, -1 ) . ( '0' === $last ? '1' : '0' ), false );

		$this->assertNull( $this->store()->reveal(), 'a flipped bit must be detected, not decrypted' );
		$this->assertFalse( $this->store()->status()->configured );
	}

	public function test_rotated_salts_fail_closed(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		// The row is untouched; only the key material differs — a restored
		// database on a host with its own wp-config.php salts.
		$rotated = new CredentialOptionStore( new CredentialKeyring( 'some-other-salt', 'and-another' ) );

		$this->assertNull( $rotated->reveal() );
		$this->assertFalse( $rotated->status()->configured );
		$this->assertIsString( $this->raw_option_value(), 'a failed read must not delete the row' );
	}

	public function test_the_filter_takes_precedence_and_refuses_to_be_overwritten(): void {
		$this->store()->put( new CredentialSecret( self::TOKEN ) );

		add_filter( CredentialOptionStore::FILTER, static fn (): string => self::OTHER );

		$store  = $this->store();
		$secret = $store->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::OTHER, $secret->reveal() );
		$this->assertSame( CredentialSource::FILTER, $store->status()->source );
		$this->assertFalse( $store->status()->is_editable() );

		try {
			$store->put( new CredentialSecret( 'wfy_test_3333333333333333' ) );
			$this->fail( 'expected a CredentialException' );
		} catch ( CredentialException $e ) {
			$this->assertSame( CredentialException::EXTERNALLY_PROVIDED, $e->reason() );
		}

		$this->assertStringNotContainsString(
			self::TOKEN,
			(string) $this->raw_option_value(),
			'the refused write must not have replaced the row either'
		);
	}

	public function test_without_a_secure_primitive_nothing_is_written(): void {
		$store = new CredentialOptionStore( CredentialKeyring::from_wordpress(), new CredentialCipher( array() ) );

		try {
			$store->put( new CredentialSecret( self::TOKEN ) );
			$this->fail( 'expected a CredentialException' );
		} catch ( CredentialException $e ) {
			$this->assertSame( CredentialException::NO_SECURE_PRIMITIVE, $e->reason() );
			$this->assertStringNotContainsString( self::TOKEN, $e->getMessage() );
			$this->assertStringNotContainsString( self::TOKEN, $e->getTraceAsString() );
		}

		$this->assertNull( $this->raw_option_value(), 'fail closed means no row at all' );
	}

	public function test_replacing_a_credential_invalidates_the_site_binding(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );
		$store->remember_binding( 'wordify:team-1:site-1' );

		$this->assertSame( 'wordify:team-1:site-1', $this->store()->binding() );

		$replaced = 0;
		add_action(
			'pd_wordify_credential_replaced',
			static function () use ( &$replaced ): void {
				++$replaced;
			}
		);

		$store->put( new CredentialSecret( self::OTHER ) );

		$this->assertNull( $this->store()->binding() );
		$this->assertSame( 1, $replaced );

		$secret = $this->store()->reveal();

		$this->assertNotNull( $secret );
		$this->assertSame( self::OTHER, $secret->reveal() );

		remove_all_actions( 'pd_wordify_credential_replaced' );
	}

	public function test_forgetting_removes_the_row_and_the_binding(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );
		$store->remember_binding( 'wordify:team-1:site-1' );

		$store->forget();

		$this->assertNull( $this->raw_option_value() );
		$this->assertNull( $this->store()->binding() );
		$this->assertNull( $this->store()->reveal() );
	}

	public function test_the_store_never_renders_the_token(): void {
		$store = $this->store();
		$store->put( new CredentialSecret( self::TOKEN ) );

		$printed  = print_r( $store, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$exported = var_export( $store, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$encoded  = (string) wp_json_encode( $store->status() );

		foreach ( array( (string) $printed, (string) $exported, $encoded, "store: {$store}" ) as $text ) {
			$this->assertStringNotContainsString( self::TOKEN, $text );
			$this->assertStringNotContainsString( wp_salt( 'secure_auth' ), $text );
		}
	}
}
