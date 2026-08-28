<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;

/**
 * Mutating methods take an ExecutionPermit and are invoked only by MutationGate.
 * Each asserts the permit against its own operation and context before acting.
 */
interface SslDriver {

	public function id(): string;

	/**
	 * A non-secret, stable identity for the provider environment this instance is
	 * configured against — the account, zone, or endpoint that actually holds the
	 * resources. Written into the lease before any mutation and compared on
	 * recovery (spec §12.6). It is shown to operators, so it must never encode a
	 * credential.
	 */
	public function environment_id(): string;

	public function capabilities(): DriverCapabilities;

	public function status( SslResourceContext $ctx ): SslStatus;

	public function identify( SslResourceContext $ctx ): IdentityResult;

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus;

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus;

	public function change_validation_method(
		SslResourceContext $ctx,
		string $method,
		ExecutionPermit $permit
	): SslStatus;

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult;

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport;

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan;
}
