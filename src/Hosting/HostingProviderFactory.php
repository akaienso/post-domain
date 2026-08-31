<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Contracts\HostingProvider;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\WpHttpClient;

/**
 * The single production source of hosting providers.
 *
 * Mirrors `Ssl\DriverFactory`: memoised for the life of a request, invalidated
 * by `reset()`, and refusing by name rather than by silence.
 *
 * The rule that matters here is the one about fallback. A configured provider
 * that cannot be built, or that is not ready, returns
 * `HostingProviderUnavailable` — it never quietly becomes the manual provider.
 * The manual provider answers "nothing to register", which is true when it was
 * chosen and a falsehood when it was substituted for a broken Wordify
 * configuration: the operator would be told a hostname was handled when nothing
 * had been told to the host at all.
 *
 * @package PostDomain
 */
final class HostingProviderFactory {

	public const MANUAL = ManualHostingProvider::ID;

	/** @var array<string, HostingProvider>|null */
	private static ?array $registry = null;

	/**
	 * Every provider this installation can select, by id.
	 *
	 * @return array<string, HostingProvider>
	 */
	public static function registry(): array {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$providers = array( self::MANUAL => new ManualHostingProvider() );

		$wordify = self::built_in_wordify();

		if ( null !== $wordify ) {
			$providers[ $wordify->id() ] = $wordify;
		}

		/**
		 * Filters the hosting providers available to this installation.
		 *
		 * @param HostingProvider[] $providers
		 */
		$filtered = apply_filters( 'pd_hosting_providers', array_values( $providers ) );

		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $provider ) {
				if ( $provider instanceof HostingProvider ) {
					$providers[ $provider->id() ] = $provider;
				}
			}
		}

		// The manual provider is not removable: it is the plugin's own
		// long-standing behaviour, and something has to answer when nothing else
		// is configured.
		$providers[ self::MANUAL ] = $providers[ self::MANUAL ] ?? new ManualHostingProvider();

		self::$registry = $providers;

		return $providers;
	}

	/**
	 * The Wordify provider, or null when it is not configured well enough to
	 * construct. Null here becomes a named refusal at selection time rather than
	 * a half-built provider that fails later inside a transport call.
	 */
	/**
	 * The Wordify provider, if this installation is actually connected.
	 *
	 * The credential and the binding are each owned by one place — the encrypted
	 * store and `HostingBinding` — and read from there rather than duplicated
	 * here. The token is taken as a supplier, so it is fetched at the moment a
	 * request needs it and never held on this object.
	 */
	private static function built_in_wordify(): ?WordifyHostingProvider {
		$binding     = HostingBinding::current();
		$environment = $binding->environment();

		// Not bound is not an error; it is the ordinary state before setup, and
		// before a clone reconnects.
		if ( null === $environment ) {
			return null;
		}

		/** @var HostingCredentialStore $store */
		$store = apply_filters( 'pd_hosting_credential_store', CredentialOptionStore::for_wordpress() );

		if ( ! $store->status()->configured ) {
			return null;
		}

		$client = new WordifyApiClient(
			new WpHttpClient(),
			static function () use ( $store ): string {
				$secret = $store->reveal();

				return null === $secret ? '' : $secret->reveal();
			},
			WordifyEndpoints::configured()
		);

		return new WordifyHostingProvider( $client, $environment );
	}

	/**
	 * Which provider this installation uses.
	 *
	 * Deferred to `HostingDetection`, which is the one place that weighs the
	 * operator's explicit choice against what was detected. A constant still
	 * wins, for operators who configure this in `wp-config.php`.
	 */
	public static function selected_provider_id(): string {
		if ( defined( 'PD_HOSTING_PROVIDER' ) ) {
			return (string) constant( 'PD_HOSTING_PROVIDER' );
		}

		return HostingDetection::selected();
	}

	/** @return HostingProvider|HostingProviderUnavailable */
	public static function for_new_mapping() {
		return self::resolve( self::selected_provider_id() );
	}

	/**
	 * The provider a stored registration belongs to.
	 *
	 * A bound row is never reinterpreted by a different provider, and never by
	 * the same provider pointed at a different account or site.
	 *
	 * @return HostingProvider|HostingProviderUnavailable
	 */
	public static function for_mapping( Mapping $mapping ) {
		if ( null === $mapping->hosting_provider ) {
			return self::for_new_mapping();
		}

		$provider = self::resolve( $mapping->hosting_provider );

		if ( $provider instanceof HostingProviderUnavailable ) {
			return $provider;
		}

		$environment = $provider->environment();

		if ( null === $environment || null === $mapping->hosting_environment ) {
			return $provider;
		}

		if ( ! $environment->matches( $mapping->hosting_environment ) ) {
			return HostingProviderUnavailable::environment_changed(
				$provider->id(),
				$mapping->hosting_environment,
				$environment->id()
			);
		}

		return $provider;
	}

	/** @return HostingProvider|HostingProviderUnavailable */
	private static function resolve( string $id ) {
		$provider = self::registry()[ $id ] ?? null;

		if ( null === $provider ) {
			// Deliberately not a fallback to the manual provider: a selected
			// provider that is missing is a misconfiguration, and saying so is
			// the only answer that cannot mislead.
			return HostingProviderUnavailable::provider( 'hosting_provider_not_registered', $id );
		}

		if ( ! $provider->is_ready() ) {
			return HostingProviderUnavailable::provider( 'hosting_provider_not_ready', $id );
		}

		return $provider;
	}

	/** Tests and the settings screen invalidate the memoized registry. */
	public static function reset(): void {
		self::$registry = null;
	}
}
