<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Verification\Challenge;

/**
 * Reads provider state for a fenced mutation and decides what it means, per kind.
 * It calls only status() and identify(); it never mutates.
 */
final class DriverRecoveryResolver implements RecoveryResolver {

	// No constructor: there is one production source of drivers and this is not
	// a place that may disagree with it.

	/**
	 * The driver is supplied by LeaseRecovery from the lease's durable binding
	 * and is already verified to be pointing at the same provider environment the
	 * mutation began against. This class never resolves one for itself.
	 */
	public function resolve(
		Mapping $mapping,
		MutationKind $kind,
		string $recovery_token,
		SslDriver $driver
	): RecoveryOutcome {
		// The environment a recovered create or adoption promotes is the one the
		// lease bound, which is the one this driver was just proven to still be
		// pointed at. Never the current selection.
		$environment = (string) $mapping->ssl_mutation_environment;
		$name        = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return RecoveryOutcome::inconclusive( 'challenge name invalid' );
		}

		$context  = SslResourceContext::from_mapping(
			$mapping,
			Environment::installation_id(),
			$name,
			$driver->id(),
			$recovery_token
		);
		$identity = $driver->identify( $context );

		if ( ! $identity->read_complete || $identity->transient ) {
			return RecoveryOutcome::inconclusive( 'provider read incomplete' );
		}

		return match ( $kind ) {
			MutationKind::CREATE => $this->resolve_create( $identity, $context, $driver, $environment ),
			MutationKind::ADOPT  => $this->resolve_adopt( $identity, $context, $driver, $environment ),
			MutationKind::METHOD => $this->resolve_method( $driver, $context ),
			MutationKind::REMOVE => $this->resolve_remove( $identity ),
		};
	}

	private function resolve_create(
		IdentityResult $identity,
		SslResourceContext $ctx,
		SslDriver $driver,
		string $environment
	): RecoveryOutcome {
		$decision = CreateRecovery::decide( $identity, $ctx );

		return match ( $decision ) {
			CreateRecovery::BIND           => RecoveryOutcome::apply(
				LeaseOutcome::bound(
					SslState::REQUESTED,
					(string) $identity->observed_ref,
					$driver->id(),
					$environment,
					OwnershipOrigin::CREATED,
					$ctx->installation_id
				),
				'recovered an ambiguous create by matching marker'
			),
			CreateRecovery::RETRY          => RecoveryOutcome::apply(
				LeaseOutcome::checked(),
				'no resource exists; the create may be retried'
			),
			CreateRecovery::ADOPT_REQUIRED => RecoveryOutcome::apply(
				LeaseOutcome::failure(
					SslState::FAILED,
					'provider_create_ambiguous',
					'A resource may exist for this hostname; adopt it explicitly.'
				),
				'ambiguous create needs an explicit adopt'
			),
			CreateRecovery::UNOWNED        => RecoveryOutcome::apply(
				LeaseOutcome::failure(
					SslState::FAILED,
					'unowned_resource',
					'A resource exists carrying a marker from another installation.'
				),
				'foreign marker'
			),
			default                        => RecoveryOutcome::inconclusive( 'create state still unclear' ),
		};
	}

	private function resolve_adopt(
		IdentityResult $identity,
		SslResourceContext $ctx,
		SslDriver $driver,
		string $environment
	): RecoveryOutcome {
		if ( null === $identity->observed_ref || $identity->observed_hostname !== $ctx->host ) {
			return RecoveryOutcome::apply( LeaseOutcome::checked(), 'adoption did not take effect' );
		}

		if ( $identity->has_conflicting_marker( $ctx->installation_id, $ctx->mapping_id ) ) {
			return RecoveryOutcome::apply(
				LeaseOutcome::failure( SslState::FAILED, 'unowned_resource', 'The marker names another installation.' ),
				'adoption blocked by a foreign marker'
			);
		}

		return RecoveryOutcome::apply(
			LeaseOutcome::adopted(
				SslState::REQUESTED,
				$identity->observed_ref,
				$driver->id(),
				$environment,
				$ctx->installation_id,
				0
			),
			'adoption confirmed by identity'
		);
	}

	private function resolve_method( SslDriver $driver, SslResourceContext $ctx ): RecoveryOutcome {
		$status = $driver->status( $ctx );

		if ( $status->transient || null === $status->confirmed_method ) {
			return RecoveryOutcome::inconclusive( 'provider did not report a method' );
		}

		return RecoveryOutcome::apply(
			LeaseOutcome::method_confirmed( $status->confirmed_method ),
			'method confirmed by re-read'
		);
	}

	private function resolve_remove( IdentityResult $identity ): RecoveryOutcome {
		if ( IdentityVerdict::ABSENT === $identity->verdict ) {
			return RecoveryOutcome::delete( 'provider confirms the resource is gone' );
		}

		return RecoveryOutcome::apply(
			LeaseOutcome::state( SslState::PENDING_REMOVAL ),
			'the resource still exists; removal remains pending'
		);
	}
}
