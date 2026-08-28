<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class AdoptionAuthorizer {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @param array{confirm?: bool, override_foreign_marker?: bool} $request
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver, lease: LeaseOwner, mapping: Mapping, observed_ref: string}|MutationRefusal
	 */
	public function authorize( Mapping $mapping, array $request ) {
		if ( true !== ( $request['confirm'] ?? false ) ) {
			return new MutationRefusal( 'confirmation_required', false );
		}

		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::ADOPT
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver   = $window['driver'];
		$context  = $window['context'];
		$held     = $window['lease'];
		$leased   = $window['mapping'];
		$identity = $driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::ADOPT,
				'identity_incomplete',
				true
			);
		}

		if ( null === $identity->observed_ref || $identity->observed_hostname !== $leased->host ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::ADOPT,
				'identity_not_confirmed',
				false
			);
		}

		$override = true === ( $request['override_foreign_marker'] ?? false );

		if ( $identity->has_conflicting_marker( $context->installation_id, $leased->id ) && ! $override ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::ADOPT,
				'foreign_marker_override_required',
				false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::ADOPT,
				'fresh_proof_transient',
				true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::ADOPT,
				'fresh_proof_failed',
				false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'       => $driver,
			'context'      => $context,
			'lease'        => $held,
			'mapping'      => $leased,
			'observed_ref' => $identity->observed_ref,
			'auth'         => new MutationAuthorization(
				MutationOperation::ADOPT,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::ADOPT ),
				$override,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
