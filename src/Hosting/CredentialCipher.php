<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use SodiumException;

/**
 * The authenticated-encryption primitives this plugin will use, in preference
 * order — and the single place that decides one is unavailable.
 *
 * Preference: `sodium_crypto_aead_xchacha20poly1305_ietf_*` first. libsodium is
 * compiled into PHP core from 7.2 onward, so on the 8.1 floor it is present
 * without an optional extension; XChaCha20-Poly1305 is a constant-time software
 * construction, so it does not depend on AES-NI and does not expose the
 * cache-timing surface a table-driven AES would on shared hosting; and its
 * 192-bit nonce makes a randomly generated nonce safe without any counter or
 * reuse bookkeeping, which is what a plugin that cannot coordinate across
 * processes actually needs.
 *
 * Fallback: `openssl_encrypt()` with `aes-256-gcm`, whose 96-bit nonce is still
 * comfortably safe at the handful of writes a credential ever sees.
 *
 * Both are AEAD. Neither is a keyed hash, an unauthenticated stream cipher, or
 * an encoding. If neither is present this class reports nothing available and
 * the store refuses to write — there is deliberately no third option, because
 * every third option is worse than failing.
 *
 * The available set is injectable so the fail-closed path can be exercised for
 * real on a machine where both primitives happen to exist.
 *
 * @package PostDomain
 */
final class CredentialCipher {

	public const XCHACHA20POLY1305 = 'xchacha20poly1305';
	public const AES256GCM         = 'aes256gcm';

	private const TAG_BYTES = 16;

	/** @var list<string> */
	private array $available;

	/** @param list<string>|null $available Overrides detection; an empty list means fail closed. */
	public function __construct( ?array $available = null ) {
		$this->available = null === $available ? self::detect() : array_values( $available );
	}

	/** @return list<string> */
	public static function detect(): array {
		$available = array();

		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			$available[] = self::XCHACHA20POLY1305;
		}

		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$available[] = self::AES256GCM;
		}

		return $available;
	}

	public function is_available(): bool {
		return array() !== $this->available;
	}

	public function supports( string $algorithm ): bool {
		return in_array( $algorithm, $this->available, true );
	}

	/** The best available primitive, or null when there is none. */
	public function preferred(): ?string {
		return $this->available[0] ?? null;
	}

	public function key_bytes(): int {
		return 32;
	}

	/** @return positive-int */
	public function nonce_bytes( string $algorithm ): int {
		return self::XCHACHA20POLY1305 === $algorithm ? 24 : 12;
	}

	/**
	 * @throws CredentialException When the algorithm is not available here.
	 */
	public function encrypt( string $algorithm, string $plaintext, string $key, string $nonce, string $aad ): string {
		if ( ! $this->supports( $algorithm ) ) {
			throw CredentialException::no_secure_primitive();
		}

		if ( self::XCHACHA20POLY1305 === $algorithm ) {
			return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $aad, $nonce, $key );
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$aad,
			self::TAG_BYTES
		);

		if ( false === $ciphertext ) {
			throw CredentialException::no_secure_primitive();
		}

		// Tag appended, so an envelope is one opaque blob whichever primitive
		// produced it and a truncated blob fails authentication rather than
		// decrypting to a prefix.
		return $ciphertext . $tag;
	}

	/**
	 * Returns null on any failure: a wrong key, a tampered byte, a truncated
	 * blob, an algorithm that is no longer available. Never a partial plaintext.
	 */
	public function decrypt( string $algorithm, string $ciphertext, string $key, string $nonce, string $aad ): ?string {
		if ( ! $this->supports( $algorithm )
			|| strlen( $ciphertext ) <= self::TAG_BYTES
			|| strlen( $key ) !== $this->key_bytes()
			|| strlen( $nonce ) !== $this->nonce_bytes( $algorithm )
		) {
			// A malformed envelope is attacker-controlled input. Rejecting the
			// shape before it reaches a primitive keeps a wrong nonce length
			// from becoming a SodiumException with a stack trace attached.
			return null;
		}

		if ( self::XCHACHA20POLY1305 === $algorithm ) {
			try {
				$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, $aad, $nonce, $key );
			} catch ( SodiumException $e ) {
				return null;
			}

			return false === $plaintext ? null : $plaintext;
		}

		$tag  = substr( $ciphertext, -self::TAG_BYTES );
		$body = substr( $ciphertext, 0, -self::TAG_BYTES );

		$plaintext = openssl_decrypt( $body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );

		return false === $plaintext ? null : $plaintext;
	}
}
