<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Ssl\Environment;

/**
 * Which Wordify team and site this installation is bound to.
 *
 * Stored separately from the credential, because the two have different
 * lifetimes and different sensitivities: the binding is ordinary configuration
 * worth keeping across a token replacement, and the token is a secret that is
 * never read back. Replacing the credential invalidates the *validation*, not
 * the binding, so the operator does not have to choose their site again — but
 * nothing may be mutated until it is revalidated.
 *
 * The binding also records the installation that made it. A clone inherits the
 * row and must not act on it: the domains belong to the original site.
 */
final class HostingBinding {

	private const OPTION = 'pd_hosting_binding';

	private function __construct(
		public readonly ?string $team_id,
		public readonly ?string $team_name,
		public readonly ?string $site_id,
		public readonly ?string $site_name,
		public readonly ?string $validated_at,
		public readonly ?string $installation_id,
		/** The credential this binding was validated under, as a keyed HMAC. */
		public readonly ?string $fingerprint,
		public readonly bool $has_credential
	) {}

	public static function current(): self {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		return new self(
			self::string_or_null( $stored, 'team_id' ),
			self::string_or_null( $stored, 'team_name' ),
			self::string_or_null( $stored, 'site_id' ),
			self::string_or_null( $stored, 'site_name' ),
			self::string_or_null( $stored, 'validated_at' ),
			self::string_or_null( $stored, 'installation_id' ),
			self::string_or_null( $stored, 'fingerprint' ),
			(bool) apply_filters( 'pd_hosting_has_credential', false )
		);
	}

	/**
	 * A keyed, truncated HMAC of the credential currently configured.
	 *
	 * Not the credential, and not reversible into it: the key is derived from
	 * the site's own salts, so confirming a guess offline needs the salts, and
	 * anyone holding those already holds everything the ciphertext protects.
	 *
	 * Null when no credential is configured or none can be decrypted, which is
	 * distinct from a fingerprint that does not match.
	 */
	public static function fingerprint(): ?string {
		$secret = HostingProviderFactory::credential_store()->reveal();

		if ( null === $secret || $secret->is_empty() ) {
			return null;
		}

		return CredentialKeyring::from_wordpress()->fingerprint( 'wordify-token', $secret->reveal() );
	}

	public function has_credential(): bool {
		return $this->has_credential;
	}

	/**
	 * Validated, and validated by *this* installation.
	 *
	 * A restored backup or a staging clone carries someone else's validation.
	 * Honouring it would let the copy mutate the original's domains, so the
	 * mismatch is treated as not validated at all.
	 */
	public function is_valid(): bool {
		if ( null === $this->validated_at || null === $this->installation_id ) {
			return false;
		}

		if ( ! hash_equals( Environment::installation_id(), $this->installation_id ) ) {
			return false;
		}

		// The credential is what proved this binding. Replacing a database token
		// invalidates explicitly, but an external token — a constant, or a
		// filter — changes with no event to hook, so the fingerprint is compared
		// on every read rather than trusted to have been cleared.
		$current = self::fingerprint();

		if ( null === $current || null === $this->fingerprint ) {
			return false;
		}

		return hash_equals( $current, $this->fingerprint );
	}

	public function is_bound(): bool {
		return $this->is_valid() && null !== $this->site_id && null !== $this->team_id;
	}

	public function environment(): ?HostingEnvironment {
		if ( ! $this->is_bound() ) {
			return null;
		}

		return new HostingEnvironment(
			HostingDetection::WORDIFY,
			(string) $this->team_id,
			(string) $this->site_id
		);
	}

	/**
	 * Records a binding that has just been confirmed against the credential.
	 *
	 * The only writer of a *valid* binding. The installation and the credential
	 * fingerprint are stamped here rather than accepted from the caller, so a
	 * binding cannot be minted for someone else's installation or for a token
	 * that was never used to confirm it.
	 */
	public static function bind( WordifyTeam $team, WordifySite $site ): bool {
		$fingerprint = self::fingerprint();

		if ( null === $fingerprint ) {
			// No readable credential means nothing confirmed this, whatever the
			// caller believes it just did.
			return false;
		}

		update_option(
			self::OPTION,
			array(
				'team_id'         => $team->id,
				'team_name'       => $team->name,
				'site_id'         => $site->id,
				'site_name'       => $site->label(),
				'site_domain'     => $site->domain,
				'validated_at'    => gmdate( 'Y-m-d H:i:s' ),
				'installation_id' => Environment::installation_id(),
				'fingerprint'     => $fingerprint,
			),
			false
		);

		return true;
	}

	/**
	 * Merges fields into the stored binding.
	 *
	 * Deliberately cannot produce a valid binding on its own: `validated_at` and
	 * `fingerprint` are stripped, so only `bind()` — which has actually
	 * confirmed the site — can make a binding authoritative.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function store( array $fields ): void {
		unset( $fields['validated_at'], $fields['fingerprint'] );

		$existing = get_option( self::OPTION );
		$existing = is_array( $existing ) ? $existing : array();

		update_option(
			self::OPTION,
			array_merge(
				$existing,
				$fields,
				array( 'installation_id' => Environment::installation_id() )
			),
			false
		);
	}

	/**
	 * Marks the connection unvalidated without forgetting which site was chosen.
	 *
	 * Used when the credential is replaced: the operator's site choice is still
	 * their choice, but nothing may be mutated until the new token has proved
	 * it can reach it.
	 */
	public static function invalidate(): void {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) ) {
			return;
		}

		unset( $stored['validated_at'], $stored['fingerprint'] );

		update_option( self::OPTION, $stored, false );
	}

	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/** @param array<string, mixed> $source */
	private static function string_or_null( array $source, string $key ): ?string {
		$value = $source[ $key ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
