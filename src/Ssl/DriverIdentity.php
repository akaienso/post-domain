<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;

/**
 * The one boundary at which a driver's own identifiers are checked.
 *
 * `id()` and `environment_id()` are supplied by executable code an integrator
 * registered, so they are trusted not to be hostile — but they are not trusted to
 * be well-formed. These values are written into fixed-width columns, shown to
 * operators, and compared for equality across process boundaries, so an empty,
 * oversized, unstable, or control-character-bearing value has to be refused
 * before it reaches SQL, REST, Diagnostics, an event, or a log line.
 *
 * The values stay readable. Nothing here hashes or obscures them.
 */
final class DriverIdentity {

	/** Matches the ssl_mutation_driver column and ssl_provider. */
	public const MAX_DRIVER_ID = 60;

	/** Matches the ssl_mutation_environment column. */
	public const MAX_ENVIRONMENT_ID = 190;

	/** Lower-case, machine-readable, safe in an option key and a URL. */
	public const DRIVER_ID_SYNTAX = '/^[a-z0-9][a-z0-9._-]*$/';

	/** Printable ASCII only: no control characters, no line breaks, no tabs. */
	public const ENVIRONMENT_SYNTAX = '/^[\x20-\x7E]+$/';

	private function __construct(
		public readonly string $driver_id,
		public readonly string $environment_id
	) {}

	/** @return self|DriverUnavailable */
	public static function of( SslDriver $driver ) {
		$id = $driver->id();

		if ( '' === $id || strlen( $id ) > self::MAX_DRIVER_ID ) {
			return DriverUnavailable::driver( 'driver_id_length', self::describe( $id ) );
		}

		if ( 1 !== preg_match( self::DRIVER_ID_SYNTAX, $id ) ) {
			return DriverUnavailable::driver( 'driver_id_syntax', self::describe( $id ) );
		}

		// An id that changes between registration and lease acquisition would
		// write a durable binding the registry can never resolve again.
		if ( $driver->id() !== $id ) {
			return DriverUnavailable::driver( 'driver_id_unstable', self::describe( $id ) );
		}

		$environment = $driver->environment_id();

		if ( '' === $environment || strlen( $environment ) > self::MAX_ENVIRONMENT_ID ) {
			return DriverUnavailable::driver( 'environment_id_length', $id );
		}

		if ( 1 !== preg_match( self::ENVIRONMENT_SYNTAX, $environment ) ) {
			return DriverUnavailable::driver( 'environment_id_syntax', $id );
		}

		// Determinism is the property the whole binding rests on: a value that
		// differs between two calls cannot be compared across a process boundary.
		if ( $driver->environment_id() !== $environment ) {
			return DriverUnavailable::driver( 'environment_id_unstable', $id );
		}

		return new self( $id, $environment );
	}

	/** A safe rendering of a rejected id, for a refusal an operator will read. */
	private static function describe( string $id ): string {
		$safe = preg_replace( '/[^\x20-\x7E]/', '?', substr( $id, 0, 40 ) );

		return '' === (string) $safe ? '(empty)' : (string) $safe;
	}
}
