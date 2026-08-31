<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Contracts\HostingProvider;

/**
 * The provider for installations that register hostnames by hand.
 *
 * It is the plugin's existing behaviour expressed as a provider rather than as
 * the absence of one: always ready, aware of nothing, and changing nothing.
 * `identify()` reporting `absent()` is honest here — there is no provider that
 * could hold the hostname, so nothing is being inferred from a failed read.
 *
 * It is selected, never fallen back to. A configured provider that fails to
 * construct or is not ready must surface as a refusal; quietly becoming this
 * one would report success for a registration that never happened.
 *
 * @package PostDomain
 */
final class ManualHostingProvider implements HostingProvider {

	public const ID = 'manual';

	public function id(): string {
		return self::ID;
	}

	public function environment(): ?HostingEnvironment {
		return null;
	}

	public function is_ready(): bool {
		return true;
	}

	public function identify( HostingResourceContext $context ): HostingIdentityResult {
		unset( $context );

		return HostingIdentityResult::absent();
	}

	public function register( HostingResourceContext $context ): RegistrationOutcome {
		unset( $context );

		return RegistrationOutcome::unsupported();
	}

	public function supports_detach(): bool {
		return false;
	}

	public function detach( HostingResourceContext $context ): RegistrationOutcome {
		unset( $context );

		return RegistrationOutcome::unsupported();
	}
}
