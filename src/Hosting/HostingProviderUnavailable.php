<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * A named refusal, returned instead of a hosting provider.
 *
 * The factory hands this back rather than the manual provider when a configured
 * provider cannot be built or is not ready. The distinction is the whole point:
 * the manual provider answers "there is nothing to do here", which for a
 * misconfigured Wordify integration would be a lie that reports success for a
 * registration that never happened.
 *
 * @package PostDomain
 */
final class HostingProviderUnavailable {

	private function __construct(
		public readonly string $reason,
		public readonly ?string $provider_id = null,
		public readonly ?string $expected_environment = null,
		public readonly ?string $configured_environment = null
	) {}

	public static function provider( string $reason, ?string $provider_id = null ): self {
		return new self( $reason, $provider_id );
	}

	/** The provider exists but is bound to a different account or site. */
	public static function environment_changed(
		string $provider_id,
		string $expected_environment,
		string $configured_environment
	): self {
		return new self( 'hosting_environment_changed', $provider_id, $expected_environment, $configured_environment );
	}

	/** A sentence for a screen, a REST body or an event. Never a credential. */
	public function detail(): string {
		if ( 'hosting_environment_changed' !== $this->reason ) {
			return sprintf(
				/* translators: 1: refusal reason, 2: hosting provider id. */
				__( 'No hosting provider is available (%1$s: %2$s).', 'post-domain' ),
				$this->reason,
				(string) $this->provider_id
			);
		}

		return sprintf(
			/* translators: 1: hosting provider id, 2: environment the registration was made in, 3: currently configured environment. */
			__( 'This registration was made in "%2$s", but hosting provider "%1$s" is currently configured for "%3$s". Restore it to read or change the registration.', 'post-domain' ),
			(string) $this->provider_id,
			(string) $this->expected_environment,
			(string) $this->configured_environment
		);
	}
}
