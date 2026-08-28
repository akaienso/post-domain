<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\SslDriver;

/**
 * The only component that invokes a mutating driver method. Services hand it a
 * driver, a context, and an authorization; it consumes the authorization, issues
 * the permit, and dispatches.
 */
final class MutationGate {

	public function __construct(
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/** @return GateResult|MutationRefusal */
	public function execute(
		SslDriver $driver,
		SslResourceContext $context,
		MutationAuthorization $auth,
		?string $argument = null
	) {
		if ( $auth->is_expired( $this->clock->now() ) ) {
			$this->release( $auth );

			return new MutationRefusal( 'authorization_expired', true );
		}

		// The context now always carries the resolved driver id, so this is a
		// plain equality check with no placeholder to except. The binding is
		// re-checked again inside consume(), against the row itself.
		if ( $driver->id() !== $context->provider_id ) {
			$this->release( $auth );

			return new MutationRefusal( 'driver_context_mismatch', false );
		}

		if ( $driver->id() !== $auth->binding->mutation_driver
			|| $driver->environment_id() !== $auth->binding->mutation_environment ) {
			// The configuration moved between acquisition and here. Nothing has
			// been sent yet, so refuse rather than send it to the wrong account.
			$this->release( $auth );

			return new MutationRefusal( 'provider_environment_changed', false );
		}

		$in_flight = $this->lease->consume( $auth->binding );

		if ( null === $in_flight ) {
			// The provider is never called. The lease is left alone: this worker
			// no longer owns it, so releasing it would be someone else's write.
			return new MutationRefusal( 'authorization_not_consumable', true, 'the mapping changed underneath' );
		}

		$permit = ExecutionPermit::issue(
			$auth->operation,
			$in_flight->mapping_id,
			$in_flight->revision,
			$in_flight->token,
			$auth->expires_at
		);

		$result = match ( $auth->operation ) {
			MutationOperation::CREATE        => $driver->create( $context, $permit ),
			MutationOperation::ADOPT         => $driver->adopt( $context, $permit ),
			MutationOperation::CHANGE_METHOD => $driver->change_validation_method( $context, (string) $argument, $permit ),
			MutationOperation::REMOVE        => $driver->remove( $context, $permit ),
		};

		return new GateResult( $result, $in_flight, $auth->operation );
	}

	/** Releases the reservation held by an authorization that will never be consumed. */
	public function release( MutationAuthorization $auth ): void {
		$this->lease->release_reserved( $auth->binding->owner() );
	}
}
