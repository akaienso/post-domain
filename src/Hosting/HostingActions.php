<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Admin\Notices;

/**
 * The hosting half of the settings screen's POST handling.
 *
 * Reached only from `Admin\Actions::dispatch()`, which has already verified the
 * nonce for this exact action and the administrator's capability. Nothing here
 * re-decides authorization; it decides what the action means.
 */
final class HostingActions {

	public static function dispatch( string $action ): void {
		match ( $action ) {
			'pd_set_hosting'         => self::set_provider(),
			'pd_set_wordify_token'   => self::set_token(),
			'pd_test_wordify'        => self::test_connection(),
			'pd_disconnect_wordify'  => self::disconnect(),
			default                  => null,
		};
	}

	private static function set_provider(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Admin\Actions::handle() verified the nonce for this action before dispatching.
		$requested = isset( $_POST['pd_hosting_provider'] ) ? sanitize_key( wp_unslash( $_POST['pd_hosting_provider'] ) ) : '';

		if ( ! in_array( $requested, array( HostingDetection::WORDIFY, HostingDetection::MANUAL ), true ) ) {
			Notices::failure( __( 'That is not a hosting provider Post Domain supports.', 'post-domain' ) );

			return;
		}

		$settings = get_option( 'pd_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['hosting_provider'] = $requested;

		update_option( 'pd_settings', $settings, false );

		Notices::success(
			HostingDetection::WORDIFY === $requested
				? __( 'Hosting set to Wordify. Add an API token to finish connecting.', 'post-domain' )
				: __( 'Hosting set to manual. Post Domain will not contact a hosting API.', 'post-domain' )
		);
	}

	private static function set_token(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified before dispatch.
		$token = isset( $_POST['pd_wordify_token'] ) ? trim( (string) wp_unslash( $_POST['pd_wordify_token'] ) ) : '';

		if ( '' === $token ) {
			Notices::failure( __( 'Enter a Wordify API token.', 'post-domain' ) );

			return;
		}

		$stored = apply_filters( 'pd_hosting_store_credential', null, $token );

		// The token is not echoed back, not logged, and not included in any
		// failure message — only whether storing it worked.
		unset( $token );

		if ( true !== $stored ) {
			Notices::failure(
				__( 'That token could not be stored securely, so nothing was saved. Check that your site can encrypt secrets.', 'post-domain' )
			);

			return;
		}

		// A new credential is not a validated connection. The site choice is
		// kept; the authority to act on it is not, until it is proved again.
		HostingBinding::invalidate();

		Notices::success( __( 'Token saved. Choose Test connection to validate it.', 'post-domain' ) );
	}

	private static function test_connection(): void {
		/** @var mixed $result */
		$result = apply_filters( 'pd_hosting_test_connection', null );

		// Nothing listening is the ordinary case before the provider layer is
		// wired, and an answer that is not the agreed shape is not an answer.
		if ( ! is_array( $result ) || ! array_key_exists( 'ok', $result ) || ! array_key_exists( 'message', $result ) ) {
			Notices::failure(
				__( 'The Wordify connection could not be tested on this site yet.', 'post-domain' )
			);

			return;
		}

		if ( true === $result['ok'] ) {
			Notices::success( (string) $result['message'] );

			return;
		}

		Notices::failure( (string) $result['message'] );
	}

	/**
	 * Removes local authority only.
	 *
	 * Nothing is detached at Wordify and no mapping is deleted: a domain that is
	 * serving keeps serving. All this withdraws is the plugin's permission to
	 * make further changes on the operator's behalf.
	 */
	private static function disconnect(): void {
		do_action( 'pd_hosting_forget_credential' );

		HostingBinding::forget();

		Notices::success(
			__( 'Wordify disconnected. No domains were detached and no mappings were deleted.', 'post-domain' )
		);
	}
}
