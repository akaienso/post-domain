<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class DeletionAuthorizer {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver}|MutationRefusal
	 */
	public function authorize( Mapping $mapping ) {
		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::REMOVE
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver  = $window['driver'];
		$context = $window['context'];
		$held    = $window['lease'];
		$leased  = $window['mapping'];

		$identity_refusal = AuthorizerSupport::check_identity( $driver, $context, true );

		if ( null !== $identity_refusal ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::REMOVE,
				$identity_refusal->precondition,
				$identity_refusal->transient
			);
		}

		if ( ! $context->has_ownership_authority() ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::REMOVE,
				'no_ownership_authority',
				false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::REMOVE,
				'fresh_proof_transient',
				true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::REMOVE,
				'fresh_proof_failed',
				false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'  => $driver,
			'context' => $context,
			'auth'    => new MutationAuthorization(
				MutationOperation::REMOVE,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::REMOVE ),
				false,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
