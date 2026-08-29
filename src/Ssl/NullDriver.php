<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\SslState;

/**
 * The default. Where certificates are handled outside the plugin, the plugin has
 * no idea what the domain should point at, so it contributes no records.
 */
final class NullDriver implements SslDriver {

	/** There is one "no provider" environment and it holds nothing. */
	public function environment_id(): string {
		return 'none';
	}

	public function id(): string {
		return 'null';
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities( false, array(), false );
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		unset( $ctx );

		return new SslStatus(
			SslState::NONE,
			null,
			'handled_externally',
			'Certificates are handled outside this plugin.'
		);
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		return new IdentityResult(
			IdentityVerdict::ABSENT,
			$ctx->provider_ref,
			null,
			null,
			null,
			MarkerSupport::UNAVAILABLE,
			true,
			false
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CREATE, $ctx );

		return $this->status( $ctx );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::ADOPT, $ctx );

		return $this->status( $ctx );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CHANGE_METHOD, $ctx );
		unset( $method );

		return $this->status( $ctx );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_for( MutationOperation::REMOVE, $ctx );

		return new RemovalResult( RemovalOutcome::REMOVED, 'nothing_to_remove' );
	}

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport {
		unset( $contexts );

		return new ReconcileReport( array(), true );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		unset( $ctx, $apex );

		return new ValidationPlan( array(), array(), array(), array(), array() );
	}
}
