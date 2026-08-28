<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl\Fixtures;

use PostDomain\Contracts\SslDriver;
use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;

final class IdentityDriver implements SslDriver {

	private int $environment_calls = 0;

	private int $id_calls = 0;

	public function __construct(
		private readonly string $id,
		private readonly string $environment,
		private readonly bool $environment_moves = false,
		private readonly bool $id_moves = false
	) {}

	public static function unstable_environment( string $id ): self {
		return new self( $id, 'zone:one', true );
	}

	public static function unstable_id(): self {
		return new self( 'cf', 'zone:one', false, true );
	}

	public function id(): string {
		++$this->id_calls;

		return $this->id_moves ? $this->id . $this->id_calls : $this->id;
	}

	public function environment_id(): string {
		++$this->environment_calls;

		return $this->environment_moves ? 'zone:' . $this->environment_calls : $this->environment;
	}

	public function capabilities(): DriverCapabilities {
		return ( new NullDriver() )->capabilities();
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		return ( new NullDriver() )->status( $ctx );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		return ( new NullDriver() )->identify( $ctx );
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		return ( new NullDriver() )->create( $ctx, $permit );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		return ( new NullDriver() )->adopt( $ctx, $permit );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		return ( new NullDriver() )->change_validation_method( $ctx, $method, $permit );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		return ( new NullDriver() )->remove( $ctx, $permit );
	}

	public function reconcile( array $contexts ): ReconcileReport {
		return ( new NullDriver() )->reconcile( $contexts );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		return ( new NullDriver() )->validation_plan( $ctx, $apex );
	}
}
