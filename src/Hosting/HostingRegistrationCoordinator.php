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
			// The manual provider: there is no origin API to tell.
			$this->transitions->not_required( $mapping->id, $mapping->revision, $provider->id() );

			return RegistrationOutcome::unsupported();
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
			// person is asked to look at, not a job that runs forever.
			$this->transitions->manual_review( $claim );

			return $outcome;
		}

		return $this->settle( $claim, $outcome );
	}

	/** Writes what the provider answered, through the owner-pinned CAS. */
	private function settle( HostingClaim $claim, RegistrationOutcome $outcome ): RegistrationOutcome {
		match ( $outcome->state ) {
			HostingRegistrationState::REGISTERED,
			HostingRegistrationState::ALREADY_MINE => $this->transitions->attach( $claim, $outcome->reference ),
			HostingRegistrationState::FOREIGN      => $this->transitions->foreign( $claim ),
			HostingRegistrationState::REFUSED      => $this->transitions->refuse( $claim ),
			HostingRegistrationState::AMBIGUOUS    => $this->transitions->ambiguous( $claim ),
			HostingRegistrationState::UNSUPPORTED  => null,
		};

		return $outcome;
	}

	private function context( Mapping $mapping ): HostingResourceContext {
		return HostingResourceContext::from_mapping( $mapping, Environment::installation_id() );
	}
}
