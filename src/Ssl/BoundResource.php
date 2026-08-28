<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\Mapping;

/**
 * The one gate every access to an already-bound provider resource passes through.
 *
 * `ssl_provider` names a driver; it does not name an account. A driver repointed
 * at another zone is still the same driver id, so resolving from current
 * configuration would let an ordinary status read ask zone B about a certificate
 * that lives in zone A — and an "absent" from there is the answer that gets
 * recorded as truth (spec §12.6).
 *
 * Being unleased does not make that read safe. It only means no mutation is in
 * flight.
 */
final class BoundResource {

	/**
	 * The driver that may speak for this mapping's resource, or a refusal naming
	 * what must be restored.
	 *
	 * A mapping with no bound resource has nothing to protect yet, so it resolves
	 * through the configured selection as before.
	 *
	 * @return SslDriver|DriverUnavailable
	 */
	public static function driver_for( Mapping $mapping ) {
		$driver = DriverFactory::for_mapping( $mapping );

		if ( $driver instanceof DriverUnavailable ) {
			return $driver;
		}

		$bound = array(
			null !== $mapping->ssl_provider,
			null !== $mapping->ssl_provider_environment,
			null !== $mapping->ssl_ref,
			null !== $mapping->ssl_ownership_origin,
			null !== $mapping->ssl_owner_installation_id,
		);

		// Nothing bound: this is a first create or an unbound adoption, and the
		// configured selection is the right answer.
		if ( ! in_array( true, $bound, true ) ) {
			return $driver;
		}

		// Partially bound. The repository invariant forbids this, so reaching here
		// means a legacy row, a raw-SQL fixture, or a future mistake — and the one
		// safe reading of a half-written binding is to refuse it, not to fall
		// through to current configuration.
		if ( in_array( false, $bound, true ) ) {
			return DriverUnavailable::driver( 'provider_binding_incomplete', $mapping->ssl_provider );
		}

		if ( $driver->environment_id() !== $mapping->ssl_provider_environment ) {
			return DriverUnavailable::environment_changed(
				(string) $mapping->ssl_provider,
				(string) $mapping->ssl_provider_environment,
				$driver->environment_id()
			);
		}

		return $driver;
	}
}
