<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;

/**
 * Setting a domain up, in the order it actually happens.
 *
 * The screen used to present every action at once and let the operator work out
 * which were possible. Most were not: requesting a certificate before the domain
 * is verified and serving is refused, and offering the button anyway teaches an
 * operator that refusals are normal.
 *
 * A step is derived from persisted state only. Nothing here decides whether an
 * action is *permitted* — `MappingCommands` does, and still refuses whatever it
 * refused before. This decides what to *show*, and the two must agree, so a step
 * is marked current only when its real prerequisites hold.
 */
final class Workflow {

	/** The one thing stored state cannot tell us: does the host route the domain here. */
	public const ORIGIN_CONFIRMED_META = 'pd_origin_confirmed_at';

	/** @return Step[] */
	public static function steps( Mapping $mapping ): array {
		$verified = VerificationState::VERIFIED === $mapping->verification_state;
		$serving  = ActivationState::ACTIVE === $mapping->activation_state;
		$leased   = null !== $mapping->ssl_mutation_token;
		$removing = null !== $mapping->ssl_removal_scope;

		$steps = array();

		// 1. Ownership --------------------------------------------------------
		$steps[] = new Step(
			1,
			__( 'Prove you own the domain', 'post-domain' ),
			match ( true ) {
				$verified                                              => Step::DONE,
				VerificationState::FAILED === $mapping->verification_state => Step::FAILED,
				default                                                => Step::CURRENT,
			},
			$verified
				? __( 'Ownership is confirmed. Leave the TXT record in place for as long as this domain is mapped.', 'post-domain' )
				: __( 'Publish the ownership TXT record shown below, then check it. DNS can take a few minutes to propagate.', 'post-domain' ),
			$removing || $leased ? null : 'pd_verify',
			__( 'Check verification', 'post-domain' )
		);

		// 2. Routing ----------------------------------------------------------
		// Nothing on the server can observe this record, so it is never "done"
		// on its own evidence. It becomes done when the domain is serving,
		// because that is the point at which it starts to matter.
		$steps[] = new Step(
			2,
			__( 'Point the domain at this site', 'post-domain' ),
			match ( true ) {
				! $verified => Step::UPCOMING,
				$serving    => Step::DONE,
				default     => Step::CURRENT,
			},
			__( 'Publish the routing record shown below at your DNS provider. It stays for as long as the domain is mapped.', 'post-domain' ),
			null,
			null,
			$verified ? null : __( 'Confirm ownership first.', 'post-domain' )
		);

		// 3. Serving ----------------------------------------------------------
		$steps[] = new Step(
			3,
			__( 'Start serving the domain', 'post-domain' ),
			match ( true ) {
				! $verified => Step::UPCOMING,
				$serving    => Step::DONE,
				default     => Step::CURRENT,
			},
			$serving
				? __( 'This domain is serving your chosen content.', 'post-domain' )
				: __( 'Turn the mapping on. Visitors reach your content at this domain from here on.', 'post-domain' ),
			$verified && ! $serving && ! $leased && ! $removing ? 'pd_activate' : null,
			__( 'Start serving', 'post-domain' ),
			$verified ? null : __( 'Confirm ownership first.', 'post-domain' )
		);

		// 4. Certificate ------------------------------------------------------
		$has_certificate = SslState::ACTIVE === $mapping->ssl_state;
		$requestable     = in_array(
			$mapping->ssl_state,
			array( SslState::NONE, SslState::FAILED, SslState::REVOKED ),
			true
		);

		$steps[] = new Step(
			4,
			__( 'Request the HTTPS certificate', 'post-domain' ),
			match ( true ) {
				! $verified || ! $serving              => Step::UPCOMING,
				$has_certificate                       => Step::DONE,
				SslState::FAILED === $mapping->ssl_state => Step::FAILED,
				$requestable                           => Step::CURRENT,
				default                                => Step::WAITING,
			},
			match ( true ) {
				$has_certificate => __( 'The certificate is active.', 'post-domain' ),
				SslState::FAILED === $mapping->ssl_state
					=> __( 'The certificate did not complete. Check the validation records below, then request it again.', 'post-domain' ),
				$requestable => __( 'Ask the provider for a certificate. Records to publish appear below once it answers.', 'post-domain' ),
				default      => __( 'The provider is working on the certificate. This can take several minutes.', 'post-domain' ),
			},
			$verified && $serving && $requestable && ! $leased && ! $removing ? 'pd_provision_ssl' : null,
			SslState::FAILED === $mapping->ssl_state
				? __( 'Request the certificate again', 'post-domain' )
				: __( 'Request a certificate', 'post-domain' ),
			$serving ? null : __( 'Start serving first.', 'post-domain' )
		);

		// 5 and 6. Provider records ------------------------------------------
		// These exist only while the provider is asking for them, which the
		// validation plan reports. They are steps rather than notes because the
		// operator has to go and publish something.
		$awaiting_provider = in_array(
			$mapping->ssl_state,
			array( SslState::REQUESTED, SslState::PENDING_VALIDATION ),
			true
		);

		$steps[] = new Step(
			5,
			__( 'Complete provider hostname ownership', 'post-domain' ),
			match ( true ) {
				! $verified || ! $serving => Step::UPCOMING,
				$has_certificate          => Step::DONE,
				$awaiting_provider        => Step::CURRENT,
				default                   => Step::UPCOMING,
			},
			__( 'If the provider asks for its own ownership record, publish it. It may be removed once the provider reports the hostname active.', 'post-domain' )
		);

		$steps[] = new Step(
			6,
			__( 'Complete certificate validation', 'post-domain' ),
			match ( true ) {
				! $verified || ! $serving => Step::UPCOMING,
				$has_certificate          => Step::DONE,
				$awaiting_provider        => Step::CURRENT,
				default                   => Step::UPCOMING,
			},
			__( 'Publish the certificate validation record if one is shown. Keep it: the provider may need it again when the certificate renews.', 'post-domain' )
		);

		// 7. The one thing stored state cannot prove ---------------------------
		$steps[] = self::origin_step( $mapping, $has_certificate );

		return $steps;
	}

	/**
	 * Whether the host actually routes the mapped name to this installation.
	 *
	 * Verified, serving and an active certificate prove the control plane and
	 * TLS. They do not prove that the web server, platform, proxy or CDN accepts
	 * the mapped `Host` header and routes it here rather than canonicalising it
	 * back to the primary domain. In live testing all three were green and the
	 * domain showed the host's placeholder page.
	 *
	 * So this step is never satisfied by inference. It is confirmed by the
	 * browser probe reaching this installation *on the mapped origin*, and until
	 * that happens it stays "Not confirmed" rather than being assumed.
	 */
	private static function origin_step( Mapping $mapping, bool $has_certificate ): Step {
		$confirmed = self::origin_confirmed_at( $mapping );

		return new Step(
			7,
			__( 'Test the domain', 'post-domain' ),
			match ( true ) {
				! $has_certificate  => Step::UPCOMING,
				null !== $confirmed => Step::DONE,
				default             => Step::UNCONFIRMED,
			},
			null !== $confirmed
				? __( 'This domain reached your site with the certificate in use.', 'post-domain' )
				: __( 'Open the domain to confirm your hosting sends it to this WordPress site. DNS and the certificate being ready does not prove that on its own.', 'post-domain' ),
			null,
			null,
			$has_certificate ? null : __( 'Finish the certificate first.', 'post-domain' )
		);
	}

	public static function origin_confirmed_at( Mapping $mapping ): ?string {
		$value = get_option( self::ORIGIN_CONFIRMED_META . '_' . $mapping->id );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Recorded only from a probe that ran on the mapped origin and reached this
	 * installation. The revision is stored with it so a later change to the row
	 * does not keep claiming a test that was run against something else.
	 */
	public static function record_origin_confirmed( Mapping $mapping ): void {
		update_option(
			self::ORIGIN_CONFIRMED_META . '_' . $mapping->id,
			gmdate( 'Y-m-d H:i:s' ),
			false
		);
	}

	public static function forget_origin_confirmation( int $mapping_id ): void {
		delete_option( self::ORIGIN_CONFIRMED_META . '_' . $mapping_id );
	}

	/** The headline, which must not overstate what is known. */
	public static function summary( Mapping $mapping ): string {
		$steps = self::steps( $mapping );

		foreach ( $steps as $step ) {
			if ( Step::FAILED === $step->status ) {
				return __( 'Something needs your attention below.', 'post-domain' );
			}
		}

		foreach ( $steps as $step ) {
			if ( Step::CURRENT === $step->status ) {
				return sprintf(
					/* translators: %s: the title of the step to do next. */
					__( 'Next: %s', 'post-domain' ),
					$step->title
				);
			}

			if ( Step::UNCONFIRMED === $step->status ) {
				// Deliberately not "fully set up". Three green states prove the
				// control plane and TLS, not that the host routes the domain here.
				return __(
					'DNS and certificate setup are complete. Test the domain to confirm that your hosting routes it to this WordPress site.',
					'post-domain'
				);
			}

			if ( Step::WAITING === $step->status ) {
				return __( 'Waiting on the certificate provider.', 'post-domain' );
			}
		}

		return __( 'This domain is set up and tested.', 'post-domain' );
	}
}
