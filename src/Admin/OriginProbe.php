<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
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
 * Confirmation therefore comes from a proof signed by the probe endpoint, which
 * runs inside this installation on the mapped host. An earlier design believed a
 * token echoed back by whatever loaded at that hostname, which proved only that
 * something loaded. There is deliberately no server-side fetch: only a browser
 * can produce the mapped Origin.
 */
final class OriginProbe {

	public const ACTION = 'pd_origin_confirmed';

	/** The one-time challenge the page issued, kept server-side. */
	private const CHALLENGE_PREFIX = 'pd_origin_challenge_';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'respond' ) );
	}

	public static function nonce_action( int $mapping_id ): string {
		return self::ACTION . ':' . $mapping_id;
	}

	/**
	 * Mints the challenge the probe must sign, and remembers it.
	 *
	 * Server-issued and single use, so a proof cannot be replayed: the second
	 * presentation finds nothing to match against.
	 */
	public static function issue_challenge( Mapping $mapping ): string {
		$challenge = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::CHALLENGE_PREFIX . $mapping->id,
			$challenge,
			OriginProof::TTL
		);

		return $challenge;
	}

	private static function take_challenge( int $mapping_id ): ?string {
		$stored = get_transient( self::CHALLENGE_PREFIX . $mapping_id );

		return is_string( $stored ) && '' !== $stored ? $stored : null;
	}

	private static function spend_challenge( int $mapping_id ): void {
		delete_transient( self::CHALLENGE_PREFIX . $mapping_id );
	}

	public static function respond(): void {
		$mapping_id = isset( $_POST['mapping'] ) ? absint( wp_unslash( $_POST['mapping'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the id selects which nonce is verified, immediately below.

		if ( ! check_ajax_referer( self::nonce_action( $mapping_id ), 'nonce', false ) ) {
			self::fail( __( 'That request could not be verified. Reload the page.', 'post-domain' ), 403 );
		}

		if ( ! current_user_can( Actions::capability() ) ) {
			self::fail( __( 'You do not have permission to manage domain mappings.', 'post-domain' ), 403 );
		}

		$mapping = ( new DbRepository() )->by_id( $mapping_id );

		if ( null === $mapping ) {
			self::fail( __( 'That mapping no longer exists.', 'post-domain' ), 404 );
		}

		// The states a passing test would have to be about. Checked before the
		// proof so a valid proof for a domain that is not serving cannot record
		// a success that means nothing.
		$precondition = match ( true ) {
			VerificationState::VERIFIED !== $mapping->verification_state
				=> __( 'This domain is not verified, so a successful test would not mean anything yet.', 'post-domain' ),
			ActivationState::ACTIVE !== $mapping->activation_state
				=> __( 'This domain is not serving yet.', 'post-domain' ),
			SslState::ACTIVE !== $mapping->ssl_state
				=> __( 'The certificate is not active yet.', 'post-domain' ),
			default => null,
		};

		if ( null !== $precondition ) {
			self::fail( $precondition, 409 );
		}

		$challenge = self::take_challenge( $mapping->id );

		if ( null === $challenge ) {
			self::fail(
				__( 'This test has expired or was already recorded. Reload the page and test again.', 'post-domain' ),
				409
			);
		}

		/** @var array<string, mixed> $payload */
		$payload   = isset( $_POST['payload'] ) && is_array( $_POST['payload'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			? map_deep( wp_unslash( $_POST['payload'] ), 'sanitize_text_field' ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			: array();
		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		$rejection = OriginProof::verify( $payload, $signature, $mapping, $challenge );

		if ( null !== $rejection ) {
			// Spent either way: a rejected attempt must not leave a challenge
			// lying around for a second try at guessing.
			self::spend_challenge( $mapping->id );
			self::fail( self::explain( $rejection ), 409 );
		}

		self::spend_challenge( $mapping->id );

		OriginConfirmation::record( $mapping );

		wp_send_json_success(
			array( 'message' => __( 'This domain reached your site. Setup is complete.', 'post-domain' ) )
		);
	}

	/** Plain language for a rejected proof; the reason itself is a term of art. */
	private static function explain( string $reason ): string {
		return match ( $reason ) {
			'expired'        => __( 'That test took too long to complete. Try again.', 'post-domain' ),
			'stale_revision' => __( 'This domain changed while the test was running. Reload the page and test again.', 'post-domain' ),
			'wrong_host'     => __( 'The browser reached a different hostname, so your hosting is sending this domain elsewhere.', 'post-domain' ),
			'wrong_mapping'  => __( 'That result was about a different domain.', 'post-domain' ),
			default          => __( 'The domain did not prove it reached this WordPress site. Your hosting may not be routing it here.', 'post-domain' ),
		};
	}

	/**
	 * @return never
	 */
	private static function fail( string $message, int $status ): void {
		wp_send_json_error( array( 'message' => $message ), $status );
	}
}
