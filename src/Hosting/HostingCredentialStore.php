<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The one thing the rest of the plugin is allowed to know about the Wordify
 * administrator token.
 *
 * The admin layer, the provider and the REST controllers depend on this
 * interface, never on `update_option()` and never on an option name. That is
 * what lets the storage change — a different primitive, a constants-only
 * deployment, a secrets manager — without a caller changing, and what lets a
 * test substitute a store instead of writing real ciphertext into a real row.
 *
 * Note the asymmetry, which is the point: `status()` is cheap, safe and
 * renderable; `reveal()` is a separate, deliberate call that exists only to
 * hand the token to the HTTP client at the moment of a request. There is no
 * property, no getter, and no accidental path from an object graph to a token.
 *
 * @package PostDomain
 */
interface HostingCredentialStore {

	/** Safe to render, encode and log. Never contains the credential. */
	public function status(): CredentialStatus;

	/**
	 * The credential itself, or null when none is configured or none can be
	 * decrypted. The only method that yields a usable token.
	 */
	public function reveal(): ?CredentialSecret;

	/**
	 * Stores a credential, replacing any current one.
	 *
	 * @throws CredentialException When encryption is unavailable, the value is
	 *                             empty, or the credential is externally
	 *                             provided. In every case nothing is written.
	 */
	public function put( CredentialSecret $secret ): void;

	/** Removes any stored credential. A no-op when there is nothing stored. */
	public function forget(): void;

	/**
	 * The provider identity this credential was last confirmed against, if any.
	 *
	 * Cleared whenever the credential changes: a token is what proves the
	 * plugin may act on a given team and site, so a binding established under a
	 * previous token is evidence about nothing.
	 */
	public function binding(): ?string;

	/** Records the identity the current credential was confirmed against. */
	public function remember_binding( string $environment_id ): void;
}
