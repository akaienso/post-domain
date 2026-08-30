<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;

/**
 * What a state means, for the person looking at it.
 *
 * `pending_validation` is a precise name and a poor explanation. The enum value
 * stays available for anyone who wants it — it is what the REST API returns and
 * what support conversations quote — but it is never the primary thing an
 * operator is asked to interpret.
 */
final class Labels {

	public static function verification( VerificationState $state ): string {
		return match ( $state ) {
			VerificationState::UNVERIFIED => __( 'Not verified yet', 'post-domain' ),
			VerificationState::PENDING    => __( 'Waiting for the DNS record', 'post-domain' ),
			VerificationState::VERIFIED   => __( 'Verified', 'post-domain' ),
			VerificationState::FAILED     => __( 'Verification failed', 'post-domain' ),
		};
	}

	public static function activation( ActivationState $state ): string {
		return match ( $state ) {
			ActivationState::ACTIVE   => __( 'Serving', 'post-domain' ),
			ActivationState::INACTIVE => __( 'Not serving', 'post-domain' ),
		};
	}

	public static function ssl( SslState $state ): string {
		return match ( $state ) {
			SslState::NONE               => __( 'No certificate', 'post-domain' ),
			SslState::REQUESTED          => __( 'Certificate requested', 'post-domain' ),
			SslState::PENDING_VALIDATION => __( 'Waiting on certificate validation', 'post-domain' ),
			SslState::ACTIVE             => __( 'Certificate active', 'post-domain' ),
			SslState::FAILED             => __( 'Certificate failed', 'post-domain' ),
			SslState::PENDING_REMOVAL    => __( 'Certificate being removed', 'post-domain' ),
			SslState::REVOKED            => __( 'Certificate removed', 'post-domain' ),
		};
	}

	/** The one-line explanation of what the operator should do next. */
	public static function next_step( Mapping $mapping ): string {
		if ( null !== $mapping->ssl_removal_scope ) {
			return __( 'This domain is being removed. Nothing else is needed.', 'post-domain' );
		}

		if ( null !== $mapping->ssl_mutation_token ) {
			return __( 'A certificate operation is running. Controls return when it finishes.', 'post-domain' );
		}

		return match ( true ) {
			VerificationState::VERIFIED !== $mapping->verification_state
				=> __( 'Publish the TXT record below, then choose Check verification.', 'post-domain' ),
			ActivationState::ACTIVE !== $mapping->activation_state
				=> __( 'Verified. Choose Start serving to bring this domain online.', 'post-domain' ),
			SslState::NONE === $mapping->ssl_state || SslState::REVOKED === $mapping->ssl_state
				=> __( 'Serving. Request a certificate so the domain works over HTTPS.', 'post-domain' ),
			SslState::ACTIVE === $mapping->ssl_state
				=> __( 'This domain is fully set up.', 'post-domain' ),
			SslState::FAILED === $mapping->ssl_state
				=> __( 'The certificate did not complete. Check the validation records below, then request it again.', 'post-domain' ),
			default
				=> __( 'The certificate provider is still working. Check back shortly.', 'post-domain' ),
		};
	}

	/** A driver id an operator can read. */
	public static function driver( string $id ): string {
		return match ( $id ) {
			'null'            => __( 'None — certificates are managed outside this plugin', 'post-domain' ),
			'cloudflare-saas' => __( 'Cloudflare for SaaS', 'post-domain' ),
			default           => $id,
		};
	}
}
