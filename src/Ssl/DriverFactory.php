<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\Mapping;

/**
 * The single production source of SSL drivers. REST, cron, reconciliation,
 * recovery, Admin, and CLI all resolve through here, so they cannot disagree
 * about which drivers exist or which one is selected.
 */
final class DriverFactory {

	public const NULL_DRIVER = 'null';

	private static ?SslDriverRegistry $registry = null;

	public static function registry(): SslDriverRegistry {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$null     = new NullDriver();
		$registry = new SslDriverRegistry( $null );

		/**
		 * The documented default is every built-in driver whose configuration is
		 * complete, with NullDriver always present (spec §11.6). The filter sees
		 * NullDriver in that array because the documented default says so — but
		 * the registry already holds it as its fallback, so re-registering the
		 * same instance is not an extension attempting a duplicate. A *different*
		 * driver claiming the id `null` still is, and is still rejected.
		 *
		 * @var SslDriver[] $drivers
		 */
		$drivers = (array) apply_filters( 'pd_ssl_drivers', array_merge( array( $null ), self::built_in_drivers() ) );

		foreach ( $drivers as $driver ) {
			if ( ! $driver instanceof SslDriver || $driver === $null ) {
				continue;
			}

			$registered = $registry->register( $driver );

			if ( $registered instanceof DriverUnavailable ) {
				$registry->reject( $registered );
			}
		}

		self::$registry = $registry;

		return $registry;
	}

	/**
	 * Built-in drivers that are configured well enough to construct. A driver
	 * missing part of its configuration is left unregistered on purpose: a named
	 * "not registered" refusal is more useful than a half-built driver that
	 * fails later inside a transport call.
	 *
	 * @return SslDriver[]
	 */
	private static function built_in_drivers(): array {
		$drivers = array();

		if ( Credentials::cloudflare_is_configured() ) {
			$drivers[] = new CloudflareSaasDriver(
				new \PostDomain\Support\WpHttpClient(),
				Credentials::api_token(),
				Credentials::zone_id(),
				Credentials::cname_target()
			);
		}

		return $drivers;
	}

	/** The operator's explicit choice. There is no implicit one. */
	public static function selected_driver_id(): string {
		if ( defined( 'PD_SSL_DRIVER' ) ) {
			return (string) constant( 'PD_SSL_DRIVER' );
		}

		$settings = get_option( 'pd_settings', array() );

		return is_array( $settings ) && isset( $settings['ssl_driver'] )
			? (string) $settings['ssl_driver']
			: self::NULL_DRIVER;
	}

	/** @return SslDriver|DriverUnavailable */
	public static function for_mapping( Mapping $mapping ) {
		if ( null === $mapping->ssl_provider ) {
			return self::for_new_mapping();
		}

		$driver = self::registry()->get( $mapping->ssl_provider );

		// A bound row is never reinterpreted: without its own driver the resource
		// cannot be read, let alone changed.
		return $driver ?? DriverUnavailable::driver( 'driver_not_registered', $mapping->ssl_provider );
	}

	/** @return SslDriver|DriverUnavailable */
	public static function for_new_mapping() {
		$selected = self::selected_driver_id();

		if ( self::NULL_DRIVER === $selected ) {
			return DriverUnavailable::driver( 'ssl_not_configured', $selected );
		}

		$driver = self::registry()->get( $selected );

		return $driver ?? DriverUnavailable::driver( 'driver_not_registered', $selected );
	}

	/** Tests and the settings screen invalidate the memoized registry. */
	public static function reset(): void {
		self::$registry = null;
	}
}
