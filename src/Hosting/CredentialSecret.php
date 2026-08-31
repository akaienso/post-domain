<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use JsonSerializable;

/**
 * A single secret string that refuses to be printed.
 *
 * The plaintext is deliberately NOT held in an object property. PHP's
 * introspection is wide: `print_r()`, `var_dump()` and `var_export()` all walk
 * private properties, `var_export()` ignores `__debugInfo()` entirely, and a
 * closure captured with `use` leaks its bound variables to `print_r()` just as
 * plainly. Any design that keeps the token on the instance leaks it through at
 * least one of those doors — and those doors are exactly what a PHPUnit failure
 * message, a `wp_die()` dump or a Query Monitor panel opens.
 *
 * So the instance holds only an unguessable handle, and the plaintext lives in
 * a private static side table keyed by that handle. Dumping the object in any
 * form yields the handle, which is random bytes with no relation to the value.
 * Reading the secret is a single explicit call, `reveal()`, which is greppable.
 *
 * @package PostDomain
 */
final class CredentialSecret implements JsonSerializable {

	public const REDACTED = '[redacted credential]';

	/**
	 * Plaintext by handle. Never exposed, never iterated in error handling.
	 *
	 * @var array<string, string>
	 */
	private static array $vault = array();

	private string $handle;

	public function __construct( string $value ) {
		$this->handle = self::store( $value );
	}

	/**
	 * The only way to read the secret. Narrow, explicit and easy to grep for.
	 *
	 * Returns the empty string once the secret has been forgotten, so a stale
	 * reference can never resurrect a value or emit a notice mid-render.
	 */
	public function reveal(): string {
		return self::$vault[ $this->handle ] ?? '';
	}

	public function is_empty(): bool {
		return '' === $this->reveal();
	}

	/** Constant-time comparison, so equality checks cannot be timed. */
	public function equals( self $other ): bool {
		return hash_equals( $this->reveal(), $other->reveal() );
	}

	/**
	 * Drops the plaintext immediately rather than waiting for collection.
	 *
	 * Used when a credential is replaced: the superseded value should stop
	 * being readable at the moment it stops being current.
	 */
	public function forget(): void {
		self::discard( $this->handle );
	}

	public function __destruct() {
		self::discard( $this->handle );
	}

	/**
	 * A clone gets its own handle and its own copy.
	 *
	 * Without this, the clone's destructor would empty the original's entry.
	 */
	public function __clone() {
		$this->handle = self::store( $this->reveal() );
	}

	public function __toString(): string {
		return self::REDACTED;
	}

	/** @return array<string, string> */
	public function __debugInfo(): array {
		return array( 'credential' => self::REDACTED );
	}

	/** @return array<string, string> */
	public function __serialize(): array {
		return array( 'credential' => self::REDACTED );
	}

	/** @param array<string, string> $data */
	public function __unserialize( array $data ): void {
		// A serialised secret carries no plaintext by design, so it can only
		// ever come back empty. Restoring it as empty keeps `reveal()` honest.
		unset( $data );
		$this->handle = self::store( '' );
	}

	public function jsonSerialize(): string {
		return self::REDACTED;
	}

	private static function store( string $value ): string {
		$handle = bin2hex( random_bytes( 16 ) );

		self::$vault[ $handle ] = $value;

		return $handle;
	}

	private static function discard( string $handle ): void {
		if ( ! isset( self::$vault[ $handle ] ) ) {
			return;
		}

		if ( function_exists( 'sodium_memzero' ) ) {
			// By reference, so the zval actually holding the plaintext is
			// overwritten rather than a copy-on-write duplicate of it.
			$slot = &self::$vault[ $handle ];
			sodium_memzero( $slot );
			unset( $slot );
		}

		unset( self::$vault[ $handle ] );
	}
}
