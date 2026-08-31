<?php
/**
 * Namespaced stand-ins for the four WordPress option functions and the two hook
 * functions `CredentialOptionStore` uses.
 *
 * PHP resolves an unqualified function call against the calling namespace
 * before the global one, so declaring `PostDomain\Hosting\get_option()` here
 * makes the store storable and readable in the unit suite, which bootstraps
 * Composer and nothing else. The store's own code is unchanged and unaware.
 *
 * The real option round-trip — including that the bytes in `wp_options` are not
 * the token — is asserted separately against a real database by
 * `PostDomain\Tests\Integration\Hosting\CredentialOptionStoreTest`.
 *
 * Loaded with `require_once`; it declares functions, not a class, so it is
 * deliberately outside PSR-4 autoloading.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

namespace PostDomain\Hosting;

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {

	/**
	 * @param mixed $default_value Returned when the option is unset.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['pd_credential_test_options'][ $option ] ?? $default_value;
	}

	/**
	 * @param mixed $value    Option value.
	 * @param mixed $autoload Ignored; recorded so the test can assert on it.
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['pd_credential_test_options'][ $option ]  = $value;
		$GLOBALS['pd_credential_test_autoload'][ $option ] = $autoload;

		return true;
	}

	function delete_option( string $option ): bool {
		unset( $GLOBALS['pd_credential_test_options'][ $option ] );

		return true;
	}

	/**
	 * @param mixed $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value ) {
		$callback = $GLOBALS['pd_credential_test_filters'][ $hook_name ] ?? null;

		return null === $callback ? $value : $callback( $value );
	}

	function do_action( string $hook_name ): void {
		$GLOBALS['pd_credential_test_actions'][] = $hook_name;
	}
}
