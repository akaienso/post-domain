<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class CreateService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly CreateAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$repo  = new DbRepository();

		// Production resolves drivers through DriverFactory, so tests install
		// theirs the same way a site would rather than injecting a registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new self(
			$repo,
			new CreateAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock )
		);
	}

	public function provision( Mapping $mapping ): MutationResult {
		$authorized = $this->authorizer->authorize( $mapping );

		if ( $authorized instanceof MutationRefusal ) {
			return MutationResult::refused( $authorized );
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return MutationResult::refused( $gated );
		}

		/** For the fencing race test: fires after the provider call, before finalize. */
		do_action( 'pd_test_after_provider_call' );

		/** @var SslStatus $status */
		$status = $gated->result;

		if ( ! $status->transient && null !== $status->ref ) {
			return $this->apply(
				$authorized,
				$gated,
				// The environment is promoted from the lease, never re-read from
				// configuration: this resource lives where the request went.
				LeaseOutcome::bound(
					$status->state,
					$status->ref,
					$authorized['lease']->driver,
					$authorized['lease']->environment,
					OwnershipOrigin::CREATED,
					$authorized['context']->installation_id
				),
				$status,
				'created'
			);
		}

		// Ambiguous: read before considering anything else. Never a second POST.
		$identity = $authorized['driver']->identify( $authorized['context'] );
		$decision = CreateRecovery::decide( $identity, $authorized['context'] );

		$outcome = match ( $decision ) {
			CreateRecovery::BIND           => LeaseOutcome::bound(
				SslState::REQUESTED,
				(string) $identity->observed_ref,
				$authorized['lease']->driver,
				$authorized['lease']->environment,
				OwnershipOrigin::CREATED,
				$authorized['context']->installation_id
			),
			CreateRecovery::ADOPT_REQUIRED => LeaseOutcome::failure(
				SslState::FAILED,
				'provider_create_ambiguous',
				'A resource may exist for this hostname; adopt it explicitly.'
			),
			CreateRecovery::UNOWNED        => LeaseOutcome::failure(
				SslState::FAILED,
				'unowned_resource',
				'A resource exists carrying a marker from another installation.'
			),
			default                        => LeaseOutcome::checked(),
		};

		$applied = $this->apply( $authorized, $gated, $outcome, $status, $decision );

		if ( ! $applied->succeeded() ) {
			return $applied;
		}

		// A recovered create is a completed mutation; every other ambiguous
		// decision leaves the truth with the provider, so say so rather than
		// dressing it up as a success.
		return CreateRecovery::BIND === $decision
			? $applied
			: MutationResult::ambiguous( $decision );
	}

	/**
	 * Applies the outcome and its event as one transition, and reports precisely
	 * what became of the attempt.
	 *
	 * @param array{auth: MutationAuthorization, context: SslResourceContext, driver: SslDriver, lease: LeaseOwner, mapping: Mapping} $authorized
	 */
	private function apply(
		array $authorized,
		GateResult $gated,
		LeaseOutcome $outcome,
		SslStatus $status,
		string $note
	): MutationResult {
		$mapping_id = $authorized['mapping']->id;

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize( $gated->owner, $outcome ),
			fn (): bool => EventLog::record(
				$mapping_id,
				$authorized['mapping']->host,
				'ssl',
				null,
				$note,
				'cron',
				array( 'create' => $note )
			)
		);

		if ( $applied->committed() ) {
			return MutationResult::committed( $status, $note );
		}

		// A lost CAS is fencing; anything else is a database failure that left the
		// provider ahead of us. The row itself distinguishes the first case.
		return $applied->cas_lost()
			? MutationResult::lost( $this->repo->by_id( $mapping_id ), $gated->owner->token )
			: MutationResult::not_persisted( $applied->detail );
	}
}
