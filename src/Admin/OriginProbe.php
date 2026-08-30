<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;

/**
 * Records that the mapped domain really reached this installation.
 *
 * Verified, serving and an active certificate prove the control plane and TLS.
 * They do not prove that the hosting, proxy or CDN routes the mapped `Host`
 * header here rather than canonicalising it back to the primary domain — in
 * live testing all three were green and the domain served the host's own
 * placeholder page.
 *
 * Only the browser can make a request with the mapped Origin, so confirmation
 * comes from the probe page this plugin serves on that host: if the host
 * reroutes the name, that page is never served, no script runs, and nothing is
 * reported. The absence of a result is the answer, and it stays "not confirmed"
 * rather than being inferred. There is deliberately no server-side fetch.
 */
final class OriginProbe {

	public const ACTION = 'pd_origin_confirmed';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'respond' ) );
	}

	public static function nonce_action( int $mapping_id ): string {
		return self::ACTION . ':' . $mapping_id;
	}

	public static function respond(): void {
		$mapping_id = isset( $_POST['mapping'] ) ? absint( wp_unslash( $_POST['mapping'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the id selects which nonce is verified, immediately below.

		if ( ! check_ajax_referer( self::nonce_action( $mapping_id ), 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That request could not be verified. Reload the page.', 'post-domain' ) ), 403 );
		}

		if ( ! current_user_can( Actions::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage domain mappings.', 'post-domain' ) ), 403 );
		}

		$mapping = ( new DbRepository() )->by_id( $mapping_id );

		if ( null === $mapping ) {
			wp_send_json_error( array( 'message' => __( 'That mapping no longer exists.', 'post-domain' ) ), 404 );
		}

		// The client reports what it saw; the server decides whether that is a
		// pass. A client claiming success for a domain that is not even serving
		// is not evidence of anything.
		$observed = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$secure   = isset( $_POST['secure'] ) && '1' === $_POST['secure']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		$refusal = match ( true ) {
			VerificationState::VERIFIED !== $mapping->verification_state
				=> __( 'This domain is not verified, so a successful test would not mean anything yet.', 'post-domain' ),
			ActivationState::ACTIVE !== $mapping->activation_state
				=> __( 'This domain is not serving yet.', 'post-domain' ),
			SslState::ACTIVE !== $mapping->ssl_state
				=> __( 'The certificate is not active yet.', 'post-domain' ),
			$observed !== $mapping->host
				=> __( 'The browser reached a different hostname, so your hosting is redirecting this domain elsewhere.', 'post-domain' ),
			! $secure
				=> __( 'The browser reached the domain over plain HTTP, so something is downgrading the connection.', 'post-domain' ),
			default => null,
		};

		if ( null !== $refusal ) {
			wp_send_json_error( array( 'message' => $refusal ), 409 );
		}

		Workflow::record_origin_confirmed( $mapping );

		wp_send_json_success(
			array(
				'message' => __( 'This domain reached your site. Setup is complete.', 'post-domain' ),
			)
		);
	}
}
