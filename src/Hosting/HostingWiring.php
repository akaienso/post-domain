<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * Joins the credential store, the binding and the admin screen.
 *
 * The screen must never hold a credential store, and the store must never know
 * what a settings page is. They meet here, through the same filter-and-action
 * seam the rest of the plugin uses, so each half stays testable on its own and
 * neither can reach past the other.
 */
final class HostingWiring {

	public static function register(): void {
		add_filter( 'pd_hosting_has_credential', array( self::class, 'has_credential' ) );
		add_filter( 'pd_hosting_credential_is_external', array( self::class, 'is_external' ) );
		add_filter( 'pd_hosting_store_credential', array( self::class, 'store' ), 10, 2 );
		add_action( 'pd_hosting_forget_credential', array( self::class, 'forget' ) );

		// Replacing a credential is not the same as validating one. The site
		// choice survives; the authority to act on it does not, until the new
		// token has proved it can reach that site.
		add_action( 'pd_wordify_credential_replaced', array( HostingBinding::class, 'invalidate' ) );

		// The production answer to the connection test. Without this the screen
		// can only report that nothing on the site knows how to test anything.
		add_filter( 'pd_hosting_test_connection', array( self::class, 'test_connection' ) );

		// Ambiguous attachments are settled by reading, on the existing sweep.
		HostingRecoveryService::register();
	}

	/**
	 * The read-only connection test, adapted to the filter's contract.
	 *
	 * The typed result is the internal currency; this is the one place it
	 * becomes the `ok`/`message` pair the admin action consumes, and the message
	 * is this plugin's own sentence rather than anything the provider said.
	 *
	 * @param mixed $result
	 * @return array{ok: bool, message: string, outcome: string}
	 */
	public static function test_connection( $result ): array {
		unset( $result );

		$outcome = WordifyConnectionService::test();

		return array(
			'ok'      => $outcome->is_ready(),
			'message' => HostingMessages::for_connection( $outcome ),
			'outcome' => $outcome->outcome->value,
		);
	}

	public static function has_credential( bool $configured ): bool {
		unset( $configured );

		return self::credential_store()->status()->configured;
	}

	public static function is_external( bool $external ): bool {
		unset( $external );

		return self::credential_store()->status()->is_external();
	}

	/**
	 * Stores a token, or refuses.
	 *
	 * Answers only true or false. The token does not come back, and neither does
	 * any detail of why the cipher refused — a failure message is a place a
	 * secret can leak from, and the operator's next step is the same either way.
	 *
	 * @param mixed $result
	 */
	public static function store( $result, string $token ): bool {
		unset( $result );

		$store = self::credential_store();

		if ( ! $store->status()->is_editable() ) {
			return false;
		}

		try {
			$store->put( new CredentialSecret( $token ) );
		} catch ( CredentialException $e ) {
			unset( $e );

			return false;
		}

		return true;
	}

	public static function forget(): void {
		self::credential_store()->forget();
	}

	private static function credential_store(): HostingCredentialStore {
		/** @var HostingCredentialStore $store */
		$store = apply_filters( 'pd_hosting_credential_store', CredentialOptionStore::for_wordpress() );

		return $store;
	}
}
