<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Verification\Challenge;

/**
 * The precondition core every authorizer shares, including the rule that a
 * refusal after acquisition releases the reservation — and records that refusal
 * only if the release actually won.
 */
final class AuthorizerSupport {

	/**
	 * @return array{driver: SslDriver, context: SslResourceContext, lease: LeaseOwner, mapping: Mapping}|MutationRefusal
	 */
	public static function open_window(
		MappingRepository $repo,
		MutationLease $lease,
		Mapping $mapping,
		MutationOperation $operation
	) {
		if ( Environment::is_blocked() ) {
			return new MutationRefusal( 'environment_unresolved', false );
		}

		// BoundResource, never DriverFactory directly. For an unbound mapping this
		// is the configured selection; for a bound one it additionally proves the
		// driver is still pointed at the environment the resource lives in. That
		// check must happen HERE, before a lease is acquired and before any
		// provider read — a lease taken against the wrong environment would then
		// be self-consistent all the way through the gate (spec §12.6).
		$driver = BoundResource::driver_for( $mapping );

		if ( $driver instanceof DriverUnavailable ) {
			return new MutationRefusal( $driver->reason, false, $driver->detail() );
		}

		if ( Cooldown::active_for( $driver->id() ) ) {
			return new MutationRefusal( 'provider_cooldown', true );
		}

		// Acquisition writes the durable binding to this driver and this provider
		// environment, before anything can be sent (spec §12.6).
		$held = $lease->acquire( $mapping->id, $mapping->revision, $operation->kind(), $driver );

		if ( null === $held ) {
			return new MutationRefusal( 'lease_unavailable', true );
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return self::refuse( $lease, $mapping, $held, $operation->kind(), 'challenge_name_invalid', false );
		}

		$leased = $repo->by_id( $mapping->id );

		if ( null === $leased ) {
			return new MutationRefusal( 'mapping_vanished', true );
		}

		return array(
			'driver'  => $driver,
			'context' => SslResourceContext::from_mapping(
				$leased,
				Environment::installation_id(),
				$name,
				$driver->id(),
				$held->token
			),
			'lease'   => $held,
			'mapping' => $leased,
		);
	}

	/**
	 * Records the refusal and releases the reservation.
	 *
	 */
	public static function refuse(
		MutationLease $lease,
		Mapping $mapping,
		?LeaseOwner $held,
		MutationKind $kind,
		string $precondition,
		bool $transient
	): MutationRefusal {
		$event = static fn(): bool => EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			null,
			'refused',
			'cron',
			array(
				'refused'   => $precondition,
				'transient' => $transient,
			)
		);

		if ( null === $held ) {
			// Nothing was reserved, so there is no CAS to pair the event with.
			$event();

			return new MutationRefusal( $precondition, $transient );
		}

		// The release is the transition; the refusal event belongs to it. If the
		// release loses — someone else already owns the row — no event is written,
		// because this worker's refusal is no longer part of that row's history.
		// The result is deliberately unused: the refusal stands either way, and
		// an unreleased reservation is recovery's problem, not the caller's.
		AtomicTransition::commit(
			static fn(): bool => $lease->release_reserved( $held ),
			$event
		);

		return new MutationRefusal( $precondition, $transient );
	}

	public static function check_identity(
		SslDriver $driver,
		SslResourceContext $context,
		bool $require_bound_match
	): ?MutationRefusal {
		$identity = $driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return new MutationRefusal( 'identity_incomplete', true );
		}

		if ( $identity->has_conflicting_marker( $context->installation_id, $context->mapping_id ) ) {
			return new MutationRefusal( 'conflicting_marker', false );
		}

		if ( $require_bound_match && ! $identity->is_usable_for_mutation( $context->host ) ) {
			return new MutationRefusal( 'identity_not_confirmed', false );
		}

		return null;
	}

	public static function binding_for( Mapping $leased, LeaseOwner $held, MutationKind $kind ): LeaseBinding {
		return new LeaseBinding(
			$leased->id,
			$held->revision,
			$held->token,
			$kind,
			$leased->host,
			$leased->ssl_provider,
			$leased->ssl_ref,
			$leased->challenge,
			$leased->ssl_method,
			$leased->ssl_ownership_origin,
			$leased->ssl_owner_installation_id,
			$held->driver,
			$held->environment
		);
	}
}
