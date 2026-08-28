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

final class MethodChangeService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly MethodChangeAuthorizer $authorizer,
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
			new MethodChangeAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock )
		);
	}

	public function change( Mapping $mapping, string $method ): MutationResult {
		$authorized = $this->authorizer->authorize( $mapping, $method );

		if ( $authorized instanceof MutationRefusal ) {
			return MutationResult::refused( $authorized );
		}

		$gated = $this->gate->execute(
			$authorized['driver'],
			$authorized['context'],
			$authorized['auth'],
			$method
		);

		if ( $gated instanceof MutationRefusal ) {
			return MutationResult::refused( $gated );
		}

		do_action( 'pd_test_after_provider_call' );

		/** @var SslStatus $status */
		$status    = $gated->result;
		$confirmed = $status->confirmed_method === $method;

		// Persist only what the provider's own re-read confirms.
		$outcome = $confirmed
			? LeaseOutcome::method_confirmed( $method )
			: LeaseOutcome::checked();

		$mapping_id = $authorized['mapping']->id;

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize( $gated->owner, $outcome ),
			fn (): bool => EventLog::record(
				$mapping_id,
				$authorized['mapping']->host,
				'ssl',
				$authorized['mapping']->ssl_method,
				$status->confirmed_method,
				'admin:' . get_current_user_id(),
				array(
					'requested' => $method,
					'confirmed' => $status->confirmed_method,
				)
			)
		);

		if ( ! $applied->committed() ) {
			return $applied->cas_lost()
				? MutationResult::lost( $this->repo->by_id( $mapping_id ), $gated->owner->token )
				: MutationResult::not_persisted( $applied->detail );
		}

		// The lease released cleanly, but the provider did not confirm the change
		// it was asked for. That is not a completed method change.
		return $confirmed
			? MutationResult::committed( $status, 'method_changed' )
			: MutationResult::ambiguous( 'the provider did not confirm the requested method' );
	}
}
