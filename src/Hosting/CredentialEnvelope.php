<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use JsonSerializable;

/**
 * The on-disk representation of one encrypted credential.
 *
 * Serialised form, four dot-separated fields:
 *
 *     pdc1.<algorithm>.<nonce, hex>.<ciphertext‖tag, hex>
 *
 * The leading `pdc1` is a format version, not a decoration. A stored secret
 * outlives the code that wrote it, so the format has to say what it is: a later
 * release that changes primitive, key derivation or framing writes `pdc2` and
 * can still read `pdc1` without guessing, and a value that is not an envelope
 * at all — a plaintext token pasted straight into the option, a base64 blob
 * from some other plugin — is recognisably not one rather than being decoded
 * into nonsense.
 *
 * Hex, not base64: `base64_decode()` accepts sloppy input and WordPress's own
 * standards treat base64 as an obfuscation smell. Hex round-trips exactly or
 * fails, which is the property this needs.
 *
 * The additional authenticated data binds version, algorithm and purpose to the
 * ciphertext. Moving an envelope to a different option, relabelling its
 * algorithm, or replaying it into a different credential slot all fail
 * authentication instead of decrypting.
 *
 * An envelope holds ciphertext only. It never holds, and cannot be made to
 * hold, a plaintext credential.
 *
 * @package PostDomain
 */
final class CredentialEnvelope implements JsonSerializable {

	public const VERSION = 'pdc1';

	private function __construct(
		public readonly string $version,
		public readonly string $algorithm,
		private readonly string $nonce,
		private readonly string $ciphertext
	) {}

	/**
	 * Encrypts a secret under the given key for the given purpose.
	 *
	 * @throws CredentialException When no authenticated primitive is available.
	 */
	public static function seal(
		CredentialCipher $cipher,
		string $key,
		string $purpose,
		CredentialSecret $secret
	): self {
		$algorithm = $cipher->preferred();

		if ( null === $algorithm ) {
			throw CredentialException::no_secure_primitive();
		}

		$nonce = random_bytes( $cipher->nonce_bytes( $algorithm ) );

		$ciphertext = $cipher->encrypt(
			$algorithm,
			$secret->reveal(),
			$key,
			$nonce,
			self::aad( self::VERSION, $algorithm, $purpose )
		);

		return new self( self::VERSION, $algorithm, $nonce, $ciphertext );
	}

	/** Null for anything that is not a well-formed envelope of a known version. */
	public static function parse( string $stored ): ?self {
		$parts = explode( '.', $stored );

		if ( 4 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			return null;
		}

		$nonce      = self::from_hex( $parts[2] );
		$ciphertext = self::from_hex( $parts[3] );

		if ( null === $nonce || null === $ciphertext || '' === $parts[1] ) {
			return null;
		}

		return new self( $parts[0], $parts[1], $nonce, $ciphertext );
	}

	/**
	 * The credential, or null when this envelope does not authenticate.
	 *
	 * Null is the only failure signal, and it covers every cause: a tampered
	 * byte, a truncated blob, salts that have been rotated, an envelope moved
	 * between purposes. None of them can produce a plaintext.
	 */
	public function open( CredentialCipher $cipher, string $key, string $purpose ): ?CredentialSecret {
		$plaintext = $cipher->decrypt(
			$this->algorithm,
			$this->ciphertext,
			$key,
			$this->nonce,
			self::aad( $this->version, $this->algorithm, $purpose )
		);

		if ( null === $plaintext || '' === $plaintext ) {
			return null;
		}

		return new CredentialSecret( $plaintext );
	}

	/** The exact bytes to persist. */
	public function serialized(): string {
		return implode(
			'.',
			array(
				$this->version,
				$this->algorithm,
				bin2hex( $this->nonce ),
				bin2hex( $this->ciphertext ),
			)
		);
	}

	private static function aad( string $version, string $algorithm, string $purpose ): string {
		return $version . '|' . $algorithm . '|' . $purpose;
	}

	private static function from_hex( string $value ): ?string {
		if ( '' === $value || 1 !== preg_match( '/^[0-9a-f]+$/', $value ) || 0 !== strlen( $value ) % 2 ) {
			return null;
		}

		$decoded = hex2bin( $value );

		return false === $decoded ? null : $decoded;
	}

	public function __toString(): string {
		return $this->serialized();
	}

	/** @return array<string, string|int> */
	public function __debugInfo(): array {
		return $this->summary();
	}

	/** @return array<string, string|int> */
	public function jsonSerialize(): array {
		return $this->summary();
	}

	/** @return array<string, string|int> */
	private function summary(): array {
		return array(
			'version'          => $this->version,
			'algorithm'        => $this->algorithm,
			'ciphertext_bytes' => strlen( $this->ciphertext ),
		);
	}
}
