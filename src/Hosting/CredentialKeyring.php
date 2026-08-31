<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use JsonSerializable;

/**
 * Derives per-purpose encryption keys from WordPress's own secret material.
 *
 * No key is ever written next to the ciphertext — or written anywhere. The
 * input keying material is `wp_salt()`, which lives in `wp-config.php`, so a
 * database dump on its own decrypts nothing, and rotating the salts renders
 * every stored envelope permanently unreadable rather than silently wrong.
 *
 * HKDF (RFC 5869, via `hash_hkdf`) rather than a bare hash: it gives every
 * purpose an independent key from the same salts, so the credential key and the
 * fingerprint key cannot be substituted for one another.
 *
 * The salts themselves are secrets, so they are held in `CredentialSecret`
 * rather than in a plain property — that is what keeps a `var_export()` of a
 * store that holds a keyring from printing the site's salts.
 *
 * @package PostDomain
 */
final class CredentialKeyring implements JsonSerializable {

	private const INFO_PREFIX = 'post-domain/hosting-credential/v1/';

	private CredentialSecret $material;

	private CredentialSecret $salt;

	public function __construct( string $material, string $salt ) {
		if ( '' === $material ) {
			throw CredentialException::no_key_material();
		}

		$this->material = new CredentialSecret( $material );
		$this->salt     = new CredentialSecret( $salt );
	}

	/**
	 * Two distinct salts, so the keyring is not a function of a single one.
	 *
	 * `secure_auth` and `auth` are both defined on every installation; when
	 * wp-config.php omits them WordPress generates and persists them itself, so
	 * this never silently degrades to an empty key.
	 */
	public static function from_wordpress(): self {
		return new self( wp_salt( 'secure_auth' ), wp_salt( 'auth' ) );
	}

	/** A 256-bit key bound to one purpose. Never cached, never stored. */
	public function key( string $purpose ): string {
		return hash_hkdf(
			'sha256',
			$this->material->reveal(),
			32,
			self::INFO_PREFIX . $purpose,
			$this->salt->reveal()
		);
	}

	/**
	 * A short, keyed indication that two values are the same value.
	 *
	 * This is safe to render because it is an HMAC under a key derived from the
	 * site's salts, not a plain digest. Confirming a guessed token offline needs
	 * the salts, and anyone holding the salts already holds everything the
	 * ciphertext protects — so the fingerprint hands an attacker with database
	 * access nothing they did not have. It is truncated to 8 hex characters,
	 * which is enough for an operator to tell "still the token I pasted" from
	 * "someone changed it" and far too little to reconstruct anything from.
	 */
	public function fingerprint( string $purpose, string $value ): string {
		return substr( hash_hmac( 'sha256', $value, $this->key( 'fingerprint/' . $purpose ) ), 0, 8 );
	}

	public function __toString(): string {
		return 'CredentialKeyring(' . CredentialSecret::REDACTED . ')';
	}

	/** @return array<string, string> */
	public function __debugInfo(): array {
		return array( 'material' => CredentialSecret::REDACTED );
	}

	/** @return array<string, string> */
	public function jsonSerialize(): array {
		return array( 'material' => CredentialSecret::REDACTED );
	}
}
