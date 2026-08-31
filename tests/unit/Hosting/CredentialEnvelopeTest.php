<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\CredentialCipher;
use PostDomain\Hosting\CredentialEnvelope;
use PostDomain\Hosting\CredentialException;
use PostDomain\Hosting\CredentialSecret;

/**
 * The envelope, exercised against both primitives and against tampering.
 *
 * @package PostDomain
 */
final class CredentialEnvelopeTest extends TestCase {

	private const TOKEN   = 'wfy_test_0000000000000000';
	private const PURPOSE = 'wordify_api_token';

	private function key( string $seed = 'key-a' ): string {
		return hash_hkdf( 'sha256', $seed, 32, 'test', 'salt' );
	}

	/** @return array<string, array{0: list<string>}> */
	public function available_primitives(): array {
		return array(
			'sodium xchacha20-poly1305' => array( array( CredentialCipher::XCHACHA20POLY1305 ) ),
			'openssl aes-256-gcm'       => array( array( CredentialCipher::AES256GCM ) ),
		);
	}

	/**
	 * @dataProvider available_primitives
	 *
	 * @param list<string> $available Primitives the cipher may use.
	 */
	public function test_a_sealed_envelope_opens_to_what_was_sealed( array $available ): void {
		$cipher = new CredentialCipher( $available );

		if ( ! $cipher->is_available() ) {
			$this->markTestSkipped( 'primitive not compiled into this PHP' );
		}

		$envelope = CredentialEnvelope::seal( $cipher, $this->key(), self::PURPOSE, new CredentialSecret( self::TOKEN ) );
		$opened   = CredentialEnvelope::parse( $envelope->serialized() );

		$this->assertNotNull( $opened );

		$secret = $opened->open( $cipher, $this->key(), self::PURPOSE );

		$this->assertNotNull( $secret );
		$this->assertSame( self::TOKEN, $secret->reveal() );
	}

	public function test_the_serialized_form_is_versioned_and_not_the_token(): void {
		$envelope = $this->seal();

		$serialized = $envelope->serialized();

		$this->assertStringStartsWith( CredentialEnvelope::VERSION . '.', $serialized );
		$this->assertStringNotContainsString( self::TOKEN, $serialized );
		$this->assertStringNotContainsString( base64_encode( self::TOKEN ), $serialized ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringNotContainsString( bin2hex( self::TOKEN ), $serialized );
	}

	public function test_a_tampered_ciphertext_is_rejected(): void {
		$envelope = $this->seal();
		$parts    = explode( '.', $envelope->serialized() );

		// Flip the last nibble of the ciphertext. AEAD must notice.
		$last     = substr( $parts[3], -1 );
		$parts[3] = substr( $parts[3], 0, -1 ) . ( '0' === $last ? '1' : '0' );
		$tampered = CredentialEnvelope::parse( implode( '.', $parts ) );

		$this->assertNotNull( $tampered, 'the tampered value is still well-formed, so it must parse' );
		$this->assertNull(
			$tampered->open( $this->cipher(), $this->key(), self::PURPOSE ),
			'a single flipped bit must fail authentication, not decrypt to something'
		);
	}

	public function test_a_tampered_nonce_is_rejected(): void {
		$envelope = $this->seal();
		$parts    = explode( '.', $envelope->serialized() );
		$first    = substr( $parts[2], 0, 1 );
		$parts[2] = ( '0' === $first ? '1' : '0' ) . substr( $parts[2], 1 );

		$tampered = CredentialEnvelope::parse( implode( '.', $parts ) );

		$this->assertNotNull( $tampered );
		$this->assertNull( $tampered->open( $this->cipher(), $this->key(), self::PURPOSE ) );
	}

	public function test_a_truncated_ciphertext_is_rejected(): void {
		$envelope = $this->seal();
		$parts    = explode( '.', $envelope->serialized() );
		$parts[3] = substr( $parts[3], 0, 20 );

		$tampered = CredentialEnvelope::parse( implode( '.', $parts ) );

		$this->assertTrue(
			null === $tampered || null === $tampered->open( $this->cipher(), $this->key(), self::PURPOSE )
		);
	}

	public function test_the_wrong_key_fails_closed(): void {
		$envelope = CredentialEnvelope::parse( $this->seal()->serialized() );

		$this->assertNotNull( $envelope );
		$this->assertNull(
			$envelope->open( $this->cipher(), $this->key( 'rotated-salts' ), self::PURPOSE ),
			'rotated salts must yield nothing, never garbage'
		);
	}

	public function test_an_envelope_cannot_be_moved_to_another_purpose(): void {
		$envelope = CredentialEnvelope::parse( $this->seal()->serialized() );

		$this->assertNotNull( $envelope );
		$this->assertNull( $envelope->open( $this->cipher(), $this->key(), 'some_other_credential' ) );
	}

	public function test_a_relabelled_algorithm_is_rejected(): void {
		$cipher = new CredentialCipher(
			array( CredentialCipher::XCHACHA20POLY1305, CredentialCipher::AES256GCM )
		);

		$envelope = CredentialEnvelope::seal( $cipher, $this->key(), self::PURPOSE, new CredentialSecret( self::TOKEN ) );
		$parts    = explode( '.', $envelope->serialized() );
		$parts[1] = CredentialCipher::AES256GCM === $parts[1]
			? CredentialCipher::XCHACHA20POLY1305
			: CredentialCipher::AES256GCM;

		$relabelled = CredentialEnvelope::parse( implode( '.', $parts ) );

		$this->assertTrue(
			null === $relabelled || null === $relabelled->open( $cipher, $this->key(), self::PURPOSE )
		);
	}

	/** @return array<string, array{0: string}> */
	public function non_envelopes(): array {
		return array(
			'empty'           => array( '' ),
			'a bare token'    => array( self::TOKEN ),
			'base64 of token' => array( 'd2Z5X3Rlc3RfMDAwMDAwMDAwMDAwMDAwMA==' ),
			'wrong version'   => array( 'pdc9.xchacha20poly1305.00.11' ),
			'too few fields'  => array( 'pdc1.xchacha20poly1305.0011' ),
			'non-hex nonce'   => array( 'pdc1.xchacha20poly1305.zzzz.0011' ),
			'odd-length hex'  => array( 'pdc1.xchacha20poly1305.001.0011' ),
			'empty algorithm' => array( 'pdc1..0011.0011' ),
		);
	}

	/** @dataProvider non_envelopes */
	public function test_anything_that_is_not_an_envelope_does_not_parse( string $stored ): void {
		$this->assertNull( CredentialEnvelope::parse( $stored ) );
	}

	public function test_sealing_without_a_primitive_throws_and_produces_nothing(): void {
		$this->expectException( CredentialException::class );

		CredentialEnvelope::seal(
			new CredentialCipher( array() ),
			$this->key(),
			self::PURPOSE,
			new CredentialSecret( self::TOKEN )
		);
	}

	public function test_the_exception_never_carries_the_token(): void {
		try {
			CredentialEnvelope::seal(
				new CredentialCipher( array() ),
				$this->key(),
				self::PURPOSE,
				new CredentialSecret( self::TOKEN )
			);
			$this->fail( 'expected a CredentialException' );
		} catch ( CredentialException $e ) {
			$this->assertSame( CredentialException::NO_SECURE_PRIMITIVE, $e->reason() );
			$this->assertStringNotContainsString( self::TOKEN, $e->getMessage() );
			$this->assertStringNotContainsString( self::TOKEN, $e->getTraceAsString() );
			$this->assertStringNotContainsString( self::TOKEN, (string) $e );
		}
	}

	public function test_two_seals_of_the_same_token_differ(): void {
		$first  = $this->seal()->serialized();
		$second = $this->seal()->serialized();

		$this->assertNotSame( $first, $second, 'a fresh nonce per seal, so equal tokens are not equal ciphertext' );
	}

	public function test_the_envelope_does_not_print_the_token(): void {
		$envelope = $this->seal();

		$printed  = print_r( $envelope, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$exported = var_export( $envelope, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$encoded  = json_encode( $envelope ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		ob_start();
		var_dump( $envelope ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
		$dumped = (string) ob_get_clean();

		foreach ( array( (string) $printed, (string) $exported, (string) $encoded, $dumped, "e: {$envelope}" ) as $text ) {
			$this->assertStringNotContainsString( self::TOKEN, $text );
		}
	}

	private function cipher(): CredentialCipher {
		return new CredentialCipher();
	}

	private function seal(): CredentialEnvelope {
		return CredentialEnvelope::seal(
			$this->cipher(),
			$this->key(),
			self::PURPOSE,
			new CredentialSecret( self::TOKEN )
		);
	}
}
