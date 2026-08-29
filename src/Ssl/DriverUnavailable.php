<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * A named refusal. Returned instead of a driver so a misconfiguration is
 * reported as one, rather than silently becoming a no-op provisioning run.
 */
final class DriverUnavailable {

	private function __construct(
		public readonly string $reason,
		public readonly ?string $driver_id = null,
		public readonly ?string $expected_environment = null,
		public readonly ?string $configured_environment = null
	) {}

	/** No driver by that id, or none selected, or the identifiers were malformed. */
	public static function driver( string $reason, ?string $driver_id = null ): self {
		return new self( $reason, $driver_id );
	}

	/**
	 * The driver exists but is pointed somewhere else. Both environments are
	 * carried because an operator needs to see which one to go back to and which
	 * one they are currently on; neither is a credential.
	 */
	public static function environment_changed(
		string $driver_id,
		string $expected_environment,
		string $configured_environment
	): self {
		return new self( 'provider_environment_changed', $driver_id, $expected_environment, $configured_environment );
	}

	/** A sentence for a screen, a REST body, or an event. Never a credential. */
	public function detail(): string {
		if ( 'provider_environment_changed' !== $this->reason ) {
			return sprintf(
				/* translators: 1: refusal reason, 2: driver id. */
				__( 'No SSL driver is available (%1$s: %2$s).', 'post-domain' ),
				$this->reason,
				(string) $this->driver_id
			);
		}

		return sprintf(
			/* translators: 1: driver id, 2: environment the resource lives in, 3: currently configured environment. */
			__( 'This certificate lives in "%2$s", but driver "%1$s" is currently configured for "%3$s". Restore it to read or change the certificate.', 'post-domain' ),
			(string) $this->driver_id,
			(string) $this->expected_environment,
			(string) $this->configured_environment
		);
	}
}
