<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Contracts\HostingProvider;
use PostDomain\Mapping\Mapping;
use PostDomain\Ssl\Environment;

/**
 * Registers a mapped hostname with the hosting provider, durably and once.
 *
 * The ordering is the whole design. A row is claimed *before* the provider is
 * called, so a crash between the two leaves evidence that a mutation may have
 * been sent; the claim is then settled by whatever the provider answered. A
 * second worker cannot claim the same row, so two concurrent creates produce
 * one attachment call rather than two.
 *
 * `MappingCommands` calls this and knows nothing about Wordify: no endpoint, no
 * payload field, no failure kind. This class knows nothing about admin notices
 * or REST status codes. What travels between them is a `RegistrationOutcome`.
 *
 * @package PostDomain
 */
final class HostingRegistrationCoordinator {

	public function __construct(
		private readonly HostingTransitions $transitions = new HostingTransitions()
	) {}

	/**
	 * Registers a freshly created mapping with the selected provider.
	 *
	 * @param HostingProvider|HostingProviderUnavailable $provider
	 */
	public function register_new( Mapping $mapping, $provider ): RegistrationOutcome {
		if ( $provider instanceof HostingProviderUnavailable ) {
			// Reached only when a caller chose to proceed anyway; creation itself
			// refuses earlier. Recorded rather than silent, because a mapping
			// whose origin was never told is not a mapping that is set up.
			return RegistrationOutcome::refused( 'The hosting provider is not available, so nothing was registered.' );
		}

		$environment = $provider->environment();

		if ( null === $environment ) {
			// The manual provider: there is no origin API to tell, so this
			// converges rather than fencing — there would be nothing for a later
			// pass to settle, and manual hosting has no environment for the
			// recovery sweep to select on. It fails only if the row already
			// belongs to a provider registration, which is not this one's to
			// relabel.
			return $this->transitions->not_required( $mapping->id, $mapping->revision, $provider->id() )
				? RegistrationOutcome::unsupported()
				: RegistrationOutcome::fenced();
		}

		$claim = $this->transitions->reserve(
			$mapping->id,
			$mapping->revision,
			$provider->id(),
			$environment->id()
		);

		if ( null === $claim ) {
			// Someone else holds this row's one attempt. Theirs will settle it;
			// a second attach here is exactly the duplicate this prevents.
			return RegistrationOutcome::ambiguous( 'Another registration for this domain is already in progress.' );
		}

		return $this->settle( $claim, $provider->register( $this->context( $mapping ) ) );
	}

	/**
	 * Repeats the one attachment for a registration the provider definitively
	 * refused.
	 *
	 * Only from `refused`. A 401 or 403 says nothing happened at the provider,
	 * so once the token is corrected the attachment can be made — and requiring
	 * the operator to delete and rebuild a mapping to do that would throw away
	 * its challenge, its certificate and its history for no reason.
	 *
	 * Everything else is excluded on purpose. `ambiguous` may already have
	 * landed, and `foreign` belongs to another site; writing again in either
	 * case is the duplicate mutation this whole design exists to prevent.
	 *
	 * @param HostingProvider|HostingProviderUnavailable $provider
	 */
	public function retry_refused( Mapping $mapping, $provider ): RegistrationOutcome {
		if ( HostingState::REFUSED !== HostingState::of( $mapping->hosting_state ) ) {
			return RegistrationOutcome::refused(
				'Only a registration the hosting provider definitively refused can be retried.'
			);
		}

		return $this->register_new( $mapping, $provider );
	}

	/**
	 * Settles an outstanding registration by reading. Never writes at the provider.
	 *
	 * @param HostingProvider|HostingProviderUnavailable $provider
	 */
	public function recover( Mapping $mapping, $provider, int $attempts_spent ): RegistrationOutcome {
		if ( $provider instanceof HostingProviderUnavailable ) {
			return RegistrationOutcome::ambiguous( 'The hosting provider cannot be reached; this stays unresolved.' );
		}

		$environment = $provider->environment();

		if ( null === $environment || null === $mapping->hosting_ref ) {
			return RegistrationOutcome::ambiguous( 'This registration has no attempt to settle.' );
		}

		// The claim is reconstructed from the row, so the settling CAS re-checks
		// every value it was made under. A credential rebound to a different
		// team no longer matches, and a clone's installation never did.
		$claim = new HostingClaim(
			$mapping->id,
			$mapping->revision,
			(string) $mapping->hosting_provider,
			(string) $mapping->hosting_environment,
			$mapping->hosting_ref
		);

		if ( ! $environment->matches( $mapping->hosting_environment ) ) {
			return RegistrationOutcome::ambiguous( 'This registration belongs to a different hosting account.' );
		}

		if ( ! $this->transitions->count_attempt( $claim ) ) {
			// Lost the row to another worker or another write. Not an error, and
			// emphatically not a reason to read again in this pass.
			return RegistrationOutcome::ambiguous( 'Another worker is settling this registration.' );
		}

		$claim   = $claim->at_revision( $claim->revision + 1 );
		$outcome = ( new HostingReconciler( $provider ) )->resolve( $this->context( $mapping ), $attempts_spent );

		if ( HostingRegistrationState::AMBIGUOUS === $outcome->state && $attempts_spent + 1 >= HostingReconciler::MAX_ATTEMPTS ) {
			// Bounded: past the documented number of reads this becomes a state a
			// person is asked to look at, not a job that runs forever. A CAS that
			// lost its row has not moved anything there, and saying so leaves the
			// claim for the next pass rather than reporting a state nobody wrote.
			return $this->transitions->manual_review( $claim ) ? $outcome : RegistrationOutcome::fenced();
		}

		return $this->settle( $claim, $outcome );
	}

	/**
	 * Writes what the provider answered, through the owner-pinned CAS.
	 *
	 * The CAS result decides what is reported. A zero-row settlement means the
	 * row moved between the provider answering and this write, so nothing was
	 * recorded — and announcing the provider's answer anyway would claim a state
	 * the database does not hold. The claim survives, so recovery settles it by
	 * reading; nothing is attached a second time.
	 */
	private function settle( HostingClaim $claim, RegistrationOutcome $outcome ): RegistrationOutcome {
		// A seam for the one interleaving this guards: a write landing between
		// the provider's answer and the settling CAS. Nothing in production
		// listens to it.
		apply_filters( 'pd_test_before_hosting_settlement', null, $claim );

		$written = match ( $outcome->state ) {
			HostingRegistrationState::REGISTERED,
			HostingRegistrationState::ALREADY_MINE => $this->transitions->attach( $claim, $outcome->reference ),
			HostingRegistrationState::FOREIGN      => $this->transitions->foreign( $claim ),
			HostingRegistrationState::REFUSED      => $this->transitions->refuse( $claim ),
			HostingRegistrationState::AMBIGUOUS    => $this->transitions->ambiguous( $claim ),
			HostingRegistrationState::UNSUPPORTED,
			HostingRegistrationState::FENCED       => true,
		};

		return $written ? $outcome : RegistrationOutcome::fenced();
	}

	private function context( Mapping $mapping ): HostingResourceContext {
		return HostingResourceContext::from_mapping( $mapping, Environment::installation_id() );
	}
}
