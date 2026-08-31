<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use JsonSerializable;

/**
 * The default credential store: an authenticated-encryption envelope in a
 * dedicated, non-autoloaded option, with a constants-first override.
 *
 * Resolution order, highest first:
 *
 *   1. the `PD_WORDIFY_TOKEN` constant, defined in `wp-config.php`;
 *   2. the `pd_wordify_token` filter;
 *   3. the encrypted `pd_wordify_credential` option.
 *
 * When 1 or 2 is in force the credential is *externally provided*: `status()`
 * says so, `is_editable()` is false, and `put()` refuses rather than writing a
 * database copy that would be shadowed anyway and would leave a second copy of
 * the secret lying around for an operator who deliberately chose not to have
 * one. That refusal is the whole reason the override exists.
 *
 * The option is registered with `autoload = false`: a credential has no
 * business being loaded into memory on every request, including the ones that
 * will never talk to the provider.
 *
 * @package PostDomain
 */
final class CredentialOptionStore implements HostingCredentialStore, JsonSerializable {

	/** Define this in wp-config.php to supply the token outside the database. */
	public const CONSTANT = 'PD_WORDIFY_TOKEN';

	/** Return a non-empty string from this filter to do the same dynamically. */
	public const FILTER = 'pd_wordify_token';

	public const OPTION         = 'pd_wordify_credential';
	public const BINDING_OPTION = 'pd_wordify_credential_binding';

	/** Bound into the AEAD additional data, so an envelope belongs to one slot. */
	private const PURPOSE = 'wordify_api_token';

	private CredentialKeyring $keyring;

	private CredentialCipher $cipher;

	/** Decrypting on every request is wasteful; decrypting into a property is not a leak. */
	private ?CredentialSecret $decrypted = null;

	private bool $decrypted_is_current = false;

	public function __construct( CredentialKeyring $keyring, ?CredentialCipher $cipher = null ) {
		$this->keyring = $keyring;
		$this->cipher  = $cipher ?? new CredentialCipher();
	}

	public static function for_wordpress(): self {
		return new self( CredentialKeyring::from_wordpress() );
	}

	public function status(): CredentialStatus {
		$external = $this->external();

		if ( null !== $external ) {
			return CredentialStatus::configured(
				$external['source'],
				$this->keyring->fingerprint( self::PURPOSE, $external['secret']->reveal() )
			);
		}

		$secret = $this->stored();

		if ( null === $secret ) {
			return CredentialStatus::absent();
		}

		return CredentialStatus::configured(
			CredentialSource::DATABASE,
			$this->keyring->fingerprint( self::PURPOSE, $secret->reveal() )
		);
	}

	public function reveal(): ?CredentialSecret {
		$external = $this->external();

		if ( null !== $external ) {
			return $external['secret'];
		}

		return $this->stored();
	}

	public function put( CredentialSecret $secret ): void {
		// Checked before anything else: an operator who supplies the token from
		// wp-config.php has said the database must not hold one, and the answer
		// to "save this" is no, not "save it and ignore it".
		if ( null !== $this->external() ) {
			throw CredentialException::externally_provided();
		}

		if ( $secret->is_empty() ) {
			throw CredentialException::empty_credential();
		}

		if ( ! $this->cipher->is_available() ) {
			// Fail closed. No plaintext option, no base64, no "temporary"
			// anything: the write simply does not happen and the caller is told.
			throw CredentialException::no_secure_primitive();
		}

		$envelope = CredentialEnvelope::seal(
			$this->cipher,
			$this->keyring->key( self::PURPOSE ),
			self::PURPOSE,
			$secret
		);

		update_option( self::OPTION, $envelope->serialized(), false );

		$this->invalidate();
	}

	public function forget(): void {
		delete_option( self::OPTION );

		$this->invalidate();
	}

	public function binding(): ?string {
		$binding = get_option( self::BINDING_OPTION, '' );

		return is_string( $binding ) && '' !== $binding ? $binding : null;
	}

	public function remember_binding( string $environment_id ): void {
		if ( '' === $environment_id ) {
			return;
		}

		update_option( self::BINDING_OPTION, $environment_id, false );
	}

	/**
	 * Drops every conclusion that was reached under the previous credential.
	 *
	 * The cached plaintext goes, and so does the site binding: authority to act
	 * on a team and a site was proved by a token, and a different token has
	 * proved nothing yet. The action lets the rest of the plugin drop whatever
	 * else it had concluded without this class needing to know about it.
	 */
	private function invalidate(): void {
		if ( null !== $this->decrypted ) {
			$this->decrypted->forget();
		}

		$this->decrypted            = null;
		$this->decrypted_is_current = false;

		delete_option( self::BINDING_OPTION );

		do_action( 'pd_wordify_credential_replaced' );
	}

	/**
	 * The constant or filter value, when one is in force.
	 *
	 * @return array{source: CredentialSource, secret: CredentialSecret}|null
	 */
	private function external(): ?array {
		if ( defined( self::CONSTANT ) ) {
			$value = constant( self::CONSTANT );

			if ( is_string( $value ) && '' !== $value ) {
				return array(
					'source' => CredentialSource::CONSTANT,
					'secret' => new CredentialSecret( $value ),
				);
			}
		}

		/**
		 * Supplies the Wordify API token from outside the database.
		 *
		 * @param string $token The token, or '' to fall through to storage.
		 */
		$filtered = apply_filters( self::FILTER, '' );

		if ( is_string( $filtered ) && '' !== $filtered ) {
			return array(
				'source' => CredentialSource::FILTER,
				'secret' => new CredentialSecret( $filtered ),
			);
		}

		return null;
	}

	private function stored(): ?CredentialSecret {
		if ( $this->decrypted_is_current ) {
			return $this->decrypted;
		}

		$this->decrypted_is_current = true;
		$this->decrypted            = null;

		$raw = get_option( self::OPTION, '' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$envelope = CredentialEnvelope::parse( $raw );

		if ( null === $envelope ) {
			return null;
		}

		$this->decrypted = $envelope->open( $this->cipher, $this->keyring->key( self::PURPOSE ), self::PURPOSE );

		return $this->decrypted;
	}

	public function __toString(): string {
		return 'CredentialOptionStore(' . (string) $this->status() . ')';
	}

	/** @return array{status: CredentialStatus, keyring: string, credential: string} */
	public function __debugInfo(): array {
		return array(
			'status'     => $this->status(),
			'keyring'    => CredentialSecret::REDACTED,
			'credential' => CredentialSecret::REDACTED,
		);
	}

	/** @return array{configured: bool, source: string, fingerprint: string|null, editable: bool} */
	public function jsonSerialize(): array {
		return $this->status()->jsonSerialize();
	}
}
