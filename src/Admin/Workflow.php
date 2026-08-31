<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Hosting\HostingState;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\ValidationPlan;

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

	/**
	 * @param ValidationPlan|null $plan The plan already built for this screen, so
	 *                                  the provider is read once rather than once
	 *                                  per question asked about it.
	 * @return Step[]
	 */
	public static function steps( Mapping $mapping, ?ValidationPlan $plan = null ): array {
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
		// Nothing here can observe this record. Switching serving on is a local
		// decision and says nothing about DNS, so it used to mark this done on no
		// evidence at all. The only thing that actually demonstrates the routing
		// record exists is the final test reaching this installation over the
		// mapped name, so that is what settles it.
		$reached = null !== self::origin_confirmed_at( $mapping );

		// The hosting half of the same question. A routing record that resolves
		// still produces the host's placeholder page when the origin was never
		// told to answer for this name, so a provider that did not confirm the
		// attachment holds this step open however good the DNS is.
		$hosting = HostingState::of( $mapping->hosting_state );
		$origin  = null === $hosting || $hosting->is_settled_ok();

		$steps[] = new Step(
			2,
			__( 'Point the domain at this site', 'post-domain' ),
			match ( true ) {
				! $verified                                            => Step::UPCOMING,
				! $origin && null !== $hosting && $hosting->is_outstanding() => Step::WAITING,
				! $origin                                              => Step::FAILED,
				$reached                                               => Step::DONE,
				default                                                => Step::CURRENT,
			},
			match ( true ) {
				! $origin => self::hosting_detail( null === $hosting ? '' : $hosting->value ),
				$reached  => __( 'The domain reached this site, so the routing record is working. It stays for as long as the domain is mapped.', 'post-domain' ),
				default   => __( 'Publish the routing record shown below at your DNS provider. Nothing here can see that record, so this stays open until the final test succeeds.', 'post-domain' ),
			},
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
		// Derived from the plan's own purposes rather than from the breadth of
		// the SSL state. `REQUESTED` and `PENDING_VALIDATION` used to mark both
		// steps current at once — including when only one kind of record existed,
		// when neither had been issued, and when the provider had already
		// finished one phase.
		$steps[] = self::provider_step(
			5,
			'provider_ownership',
			__( 'Complete provider hostname ownership', 'post-domain' ),
			__( 'The provider is asking for its own ownership record. Publish the record shown below; it may be removed once the provider reports the hostname active.', 'post-domain' ),
			$mapping,
			$plan,
			$verified && $serving,
			$has_certificate
		);

		$steps[] = self::provider_step(
			6,
			'ssl_validation',
			__( 'Complete certificate validation', 'post-domain' ),
			__( 'Publish the certificate validation record shown below. Keep it: the provider may need it again when the certificate renews.', 'post-domain' ),
			$mapping,
			$plan,
			$verified && $serving,
			$has_certificate
		);

		// 7. The one thing stored state cannot prove ---------------------------
		$steps[] = self::origin_step( $mapping, $has_certificate );

		return $steps;
	}

	/** What the hosting provider's answer means for the routing step. */
	private static function hosting_detail( string $state ): string {
		return match ( HostingState::tryFrom( $state ) ) {
			HostingState::RESERVED,
			HostingState::AMBIGUOUS => __( 'Your hosting has not confirmed this domain yet. Post Domain is still checking and will not ask again. Until it confirms, the domain can resolve and still show your host\'s placeholder page.', 'post-domain' ),
			HostingState::FOREIGN   => __( 'Your hosting reports this domain attached to a different site on the same account. Nothing was changed there. Detach it from that site, then delete and re-add this mapping.', 'post-domain' ),
			HostingState::REFUSED   => __( 'Your hosting refused to accept this domain, so it will not reach this site. Check that the API token still works and has the Manage Sites ability, then delete and re-add this mapping.', 'post-domain' ),
			HostingState::MANUAL_REVIEW => __( 'Your hosting never settled this domain after several checks. Look at it in your hosting console: the domain may or may not be attached there.', 'post-domain' ),
			default                 => __( 'Your hosting has not accepted this domain.', 'post-domain' ),
		};
	}

	/**
	 * One provider phase, read from the plan rather than guessed from the state.
	 *
	 * CURRENT only when there is something the operator can actually publish for
	 * this purpose; WAITING when the provider has not issued it yet; DONE when
	 * the provider says the phase is finished; FAILED when the plan reports a
	 * blocker that belongs to it; BLOCKED when the read that would have told us
	 * about this phase failed outright.
	 *
	 * Ownership is read from the blocker's own metadata. It used to be guessed by
	 * looking for the purpose string inside the code or the translated message,
	 * which missed `provider_record_malformed` entirely and reported the phase
	 * DONE while the plan was saying the record was malformed.
	 */
	private static function provider_step(
		int $number,
		string $purpose,
		string $title,
		string $detail,
		Mapping $mapping,
		?ValidationPlan $plan,
		bool $reachable,
		bool $has_certificate
	): Step {
		if ( ! $reachable ) {
			return new Step( $number, $title, Step::UPCOMING, $detail, null, null, __( 'Start serving first.', 'post-domain' ) );
		}

		// Without a plan the honest answer is that nothing is known yet, not that
		// the operator has work waiting.
		if ( null === $plan ) {
			return new Step(
				$number,
				$title,
				$has_certificate ? Step::DONE : Step::UPCOMING,
				$detail
			);
		}

		// A blocker naming this phase is that phase's failure. A global one is a
		// failed read: it proves nothing about this phase, and in particular an
		// empty plan behind it is not evidence the phase is finished.
		foreach ( $plan->blockers as $candidate ) {
			if ( $purpose === $candidate->purpose ) {
				return new Step( $number, $title, Step::FAILED, $candidate->message, null, null, $candidate->remedy );
			}
		}

		foreach ( $plan->blockers as $candidate ) {
			if ( $candidate->is_global() ) {
				return new Step( $number, $title, Step::BLOCKED, $candidate->message, null, null, $candidate->remedy );
			}
		}

		$actionable = array() !== ( $plan->dns[ $purpose ] ?? array() );

		foreach ( $plan->http as $token ) {
			if ( $token->purpose === $purpose ) {
				$actionable = true;
			}
		}

		foreach ( $plan->manual as $manual ) {
			if ( property_exists( $manual, 'purpose' ) && $manual->purpose === $purpose ) {
				$actionable = true;
			}
		}

		$waiting = false;

		foreach ( $plan->pending as $pending ) {
			if ( $pending->purpose === $purpose ) {
				$waiting = true;
			}
		}

		return new Step(
			$number,
			$title,
			match ( true ) {
				$actionable      => Step::CURRENT,
				$waiting         => Step::WAITING,
				$has_certificate => Step::DONE,
				default          => Step::DONE,
			},
			match ( true ) {
				$actionable => $detail,
				$waiting    => __( 'The provider has not issued this record yet. This is a wait, not a failure.', 'post-domain' ),
				default     => __( 'The provider reports this phase complete.', 'post-domain' ),
			}
		);
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
		return OriginConfirmation::confirmed_at( $mapping );
	}

	/**
	 * Recorded only from a signed proof that this installation served the mapped
	 * host, and bound to the state it was made about, so any later change to the
	 * row retires it rather than inheriting it.
	 */
	public static function record_origin_confirmed( Mapping $mapping ): void {
		OriginConfirmation::record( $mapping );
	}

	public static function forget_origin_confirmation( int $mapping_id ): void {
		OriginConfirmation::forget( $mapping_id );
	}

	/** The headline, which must not overstate what is known. */
	public static function summary( Mapping $mapping, ?ValidationPlan $plan = null ): string {
		$steps = self::steps( $mapping, $plan );

		foreach ( $steps as $step ) {
			if ( Step::FAILED === $step->status || Step::BLOCKED === $step->status ) {
				return __( 'Something needs your attention below.', 'post-domain' );
			}
		}

		// A blocker that belongs to no step still belongs to this domain. Routing
		// is the standing case: nothing here can observe that record, so an
		// unsupported apex would otherwise leave the headline free to claim the
		// domain is set up while the plan says it cannot be routed at all.
		if ( null !== $plan && array() !== $plan->blockers ) {
			return __( 'Something needs your attention below.', 'post-domain' );
		}

		foreach ( $steps as $step ) {
			if ( Step::UNCONFIRMED === $step->status ) {
				// Deliberately not "fully set up". Three green states prove the
				// control plane and TLS, not that the host routes the domain here.
				//
				// This outranks the routing step still being open, because the
				// same test settles both: nothing else here can observe a DNS
				// record, so asking the operator to prove routing separately would
				// be asking twice for one answer.
				return __(
					'DNS and certificate setup are complete. Test the domain to confirm that your hosting routes it to this WordPress site.',
					'post-domain'
				);
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

			if ( Step::WAITING === $step->status ) {
				return __( 'Waiting on the certificate provider.', 'post-domain' );
			}
		}

		$hosting = HostingState::of( $mapping->hosting_state );

		if ( null !== $hosting && ! $hosting->is_settled_ok() ) {
			// Every local step is green and the origin still never accepted the
			// hostname. Saying "set up and tested" here is the exact falsehood
			// this workflow exists to prevent.
			return __( 'Your hosting has not confirmed this domain, so it may not reach this site.', 'post-domain' );
		}

		return __( 'This domain is set up and tested.', 'post-domain' );
	}
}
