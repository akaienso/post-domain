<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class CreateAuthorizer {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver, lease: LeaseOwner, mapping: Mapping}|MutationRefusal
	 */
	public function authorize( Mapping $mapping ) {
		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::CREATE
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver  = $window['driver'];
		$context = $window['context'];
		$held    = $window['lease'];
		$leased  = $window['mapping'];

		// The reference may legitimately be null here, so a bound match is not
		// required — but the read must still be complete and unconflicted.
		$identity_refusal = AuthorizerSupport::check_identity( $driver, $context, false );

		if ( null !== $identity_refusal ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::CREATE,
				$identity_refusal->precondition,
				$identity_refusal->transient
			);
		}

		$method = $leased->ssl_method ?? Credentials::ssl_method();

		if ( array() !== $driver->capabilities()->validation_methods
			&& ! in_array( $method, $driver->capabilities()->validation_methods, true ) ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::CREATE,
				'method_unsupported',
				false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::CREATE,
				'fresh_proof_transient',
				true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease,
				$leased,
				$held,
				MutationKind::CREATE,
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
				MutationOperation::CREATE,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::CREATE ),
				false,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
