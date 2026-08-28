<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;

final class SslDriverRegistry {

	/** @var array<string, SslDriver> */
	private array $drivers = array();

	public function __construct( private readonly SslDriver $fallback ) {
		$this->drivers[ $fallback->id() ] = $fallback;
	}

	/** True when this exact instance is already registered under this exact id. */
	public function holds( SslDriver $driver ): bool {
		return ( $this->drivers[ $driver->id() ] ?? null ) === $driver;
	}

	/**
	 * Refusals collected while building this registry, so a misconfigured driver
	 * is reportable in Diagnostics rather than merely absent.
	 *
	 * @var DriverUnavailable[]
	 */
	private array $rejected = array();

	/** @return DriverUnavailable[] */
	public function rejected(): array {
		return $this->rejected;
	}

	public function reject( DriverUnavailable $refusal ): void {
		$this->rejected[] = $refusal;
	}

	/**
	 * @return true|DriverUnavailable A refusal naming why, never a silent drop.
	 */
	public function register( SslDriver $driver ) {
		$identity = DriverIdentity::of( $driver );

		if ( $identity instanceof DriverUnavailable ) {
			return $identity;
		}

		// Registering the very same instance again is a no-op, not a conflict:
		// the factory's own fallback appears in the documented filter default.
		if ( ( $this->drivers[ $identity->driver_id ] ?? null ) === $driver ) {
			return true;
		}

		// A duplicate id must not replace a registered driver: whichever one then
		// answered would depend on filter order, and a bound row would resolve to
		// a driver that is not the one it was bound to.
		if ( isset( $this->drivers[ $identity->driver_id ] ) ) {
			return DriverUnavailable::driver( 'driver_id_duplicate', $identity->driver_id );
		}

		$this->drivers[ $identity->driver_id ] = $driver;

		return true;
	}

	public function get( string $id ): ?SslDriver {
		return $this->drivers[ $id ] ?? null;
	}

	public function default(): SslDriver {
		return $this->fallback;
	}

	/** @return string[] */
	public function ids(): array {
		return array_keys( $this->drivers );
	}
}
