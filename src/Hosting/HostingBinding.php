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
			(bool) apply_filters( 'pd_hosting_has_credential', false )
		);
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

		return hash_equals( Environment::installation_id(), $this->installation_id );
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

	/** @param array<string, mixed> $fields */
	public static function store( array $fields ): void {
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

		unset( $stored['validated_at'] );

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
