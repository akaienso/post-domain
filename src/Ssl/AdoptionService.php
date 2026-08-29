<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

/**
 * The public method is `take_ownership()`, not `adopt()`: `adopt` is a driver
 * method name, and the enforcement scan in Plan 07 flags that name anywhere
 * outside MutationGate. A service that borrowed it would make the scan noisy
 * exactly where it needs to be precise.
 */
final class AdoptionService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly AdoptionAuthorizer $authorizer,
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
			new AdoptionAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock )
		);
	}

	/**
	 * @param array{confirm?: bool, override_foreign_marker?: bool} $request
	 */
	public function take_ownership( Mapping $mapping, array $request ): MutationResult {
		$authorized = $this->authorizer->authorize( $mapping, $request );

		if ( $authorized instanceof MutationRefusal ) {
			return MutationResult::refused( $authorized );
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return MutationResult::refused( $gated );
		}

		do_action( 'pd_test_after_provider_call' );

		/** @var SslStatus $status */
		$status = $gated->result;

		$mapping_id = $authorized['mapping']->id;
		$actor      = 'admin:' . get_current_user_id();

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize(
				$gated->owner,
				LeaseOutcome::adopted(
					$status->state,
					$authorized['observed_ref'],
					$authorized['lease']->driver,
					$authorized['lease']->environment,
					$authorized['context']->installation_id,
					get_current_user_id()
				)
			),
			fn (): bool => EventLog::record(
				$mapping_id,
				$authorized['mapping']->host,
				'ssl',
				null,
				'adopted',
				$actor,
				array( 'observed_ref' => $authorized['observed_ref'] )
			)
		);

		// Claiming ownership is exactly the write that must not survive a lost
		// CAS, and no event may say it happened.
		if ( $applied->committed() ) {
			return MutationResult::committed( $status, 'adopted' );
		}

		return $applied->cas_lost()
			? MutationResult::lost( $this->repo->by_id( $mapping_id ), $gated->owner->token )
			: MutationResult::not_persisted( $applied->detail );
	}
}
