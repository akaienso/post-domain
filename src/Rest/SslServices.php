<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Ssl\AdoptionAuthorizer;
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\CreateAuthorizer;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\DeletionService;
use PostDomain\Ssl\BoundResource;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Ssl\MethodChangeAuthorizer;
use PostDomain\Ssl\MethodChangeService;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationLease;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;
use PostDomain\Verification\ResolverFactory;

/**
 * The exact set of SSL operations a REST handler may reach. The controller never
 * constructs a driver, a lease, or an authorization of its own.
 */
final class SslServices {

	public function __construct(
		public readonly CreateService $create,
		public readonly AdoptionService $adopt,
		public readonly MethodChangeService $method,
		public readonly DeletionService $delete
	) {}

	public static function production(): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$gate  = new MutationGate( $lease, $clock );
		$repo  = new DbRepository();
		$proof = new FreshProof( ResolverFactory::from_filters() );

		// No registry is built here. Every service resolves its driver through
		// DriverFactory, which is also what cron uses, so the two cannot differ.
		return new self(
			new CreateService( $repo, new CreateAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate ),
			new AdoptionService( $repo, new AdoptionAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate ),
			new MethodChangeService( $repo, new MethodChangeAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate ),
			// DeletionService owns the clock instead of a repository: its writes are
			// CAS statements it issues itself, not repository saves.
			new DeletionService( new DeletionAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate, $clock )
		);
	}

	/**
	 * BoundResource, not DriverFactory: for a mapping that already has a resource
	 * this also proves the driver still points at the environment it lives in.
	 *
	 * @return SslDriver|DriverUnavailable
	 */
	public function driver_for( Mapping $mapping ) {
		return BoundResource::driver_for( $mapping );
	}
}
