<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class MethodChangeAuthorizer {

	public const METHODS = array( 'http', 'txt', 'email' );

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver, lease: LeaseOwner, mapping: Mapping}|MutationRefusal
	 */
	public function authorize( Mapping $mapping, string $method ) {
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return new MutationRefusal( 'method_unsupported', false );
		}

		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::CHANGE_METHOD
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver  = $window['driver'];
		$context = $window['context'];
		$held    = $window['lease'];
		$leased  = $window['mapping'];

		if ( ! in_array( $method, $driver->capabilities()->validation_methods, true ) ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::METHOD,
				'method_unsupported',
				false
			);
		}

		$identity_refusal = AuthorizerSupport::check_identity( $driver, $context, true );

		if ( null !== $identity_refusal ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::METHOD,
				$identity_refusal->precondition,
				$identity_refusal->transient
			);
		}

		if ( ! $context->has_ownership_authority() ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::METHOD,
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
				MutationKind::METHOD,
				'fresh_proof_transient',
				true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::METHOD,
				'fresh_proof_failed',
				false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'  => $driver,
			'context' => $context,
			'lease'   => $held,
			'mapping' => $leased,
			'auth'    => new MutationAuthorization(
				MutationOperation::CHANGE_METHOD,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::METHOD ),
				false,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
