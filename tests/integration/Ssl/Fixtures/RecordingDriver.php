<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl\Fixtures;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\ProviderMarker;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;

/** A configurable driver double shared by Plans 07, 08, and 09. */
final class RecordingDriver implements SslDriver {

	public int $create_calls = 0;

	public int $adopt_calls = 0;

	public int $method_calls = 0;

	public int $remove_calls = 0;

	public int $identify_calls = 0;

	public int $status_calls = 0;

	public int $plan_calls = 0;

	/** @var string[] */
	public array $phases_observed = array();

	private function __construct(
		private readonly ?string $created_ref,
		private readonly bool $create_is_ambiguous,
		private readonly IdentityVerdict $verdict,
		private readonly ?string $observed_ref,
		private readonly ?string $marker_installation,
		private readonly MarkerSupport $marker_support,
		private readonly bool $identity_complete = true,
		private readonly RemovalOutcome $removal = RemovalOutcome::REMOVED,
		private readonly ?string $confirmed_method = 'txt',
		private readonly string $environment = 'recording:default'
	) {}

	/** Same driver id, different provider account or zone. */
	public function in_environment( string $environment ): self {
		return new self(
			$this->created_ref,
			$this->create_is_ambiguous,
			$this->verdict,
			$this->observed_ref,
			$this->marker_installation,
			$this->marker_support,
			$this->identity_complete,
			$this->removal,
			$this->confirmed_method,
			$environment
		);
	}

	public function environment_id(): string {
		return $this->environment;
	}

	public static function succeeding( string $ref ): self {
		return new self( $ref, false, IdentityVerdict::MATCH, $ref, null, MarkerSupport::UNAVAILABLE );
	}

	public static function with_identity( IdentityVerdict $verdict ): self {
		return new self( 'ref-1', false, $verdict, 'ref-1', null, MarkerSupport::UNAVAILABLE );
	}

	public static function with_incomplete_identity(): self {
		return new self( 'ref-1', false, IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, false );
	}

	public static function with_foreign_marker(): self {
		return new self( 'ref-1', false, IdentityVerdict::MATCH, 'ref-1', 'someone-else', MarkerSupport::SUPPORTED );
	}

	public static function ambiguous_then_marked( string $ref ): self {
		return new self( null, true, IdentityVerdict::RECOVERABLE_CREATE, $ref, 'self', MarkerSupport::SUPPORTED );
	}

	public static function ambiguous_then_unmarked( string $ref ): self {
		return new self( null, true, IdentityVerdict::MISMATCH, $ref, null, MarkerSupport::UNAVAILABLE );
	}

	public static function ambiguous_then_foreign( string $ref ): self {
		return new self( null, true, IdentityVerdict::MISMATCH, $ref, 'someone-else', MarkerSupport::SUPPORTED );
	}

	public static function ambiguous_then_absent(): self {
		return new self( null, true, IdentityVerdict::ABSENT, null, null, MarkerSupport::SUPPORTED );
	}

	public static function removing( RemovalOutcome $outcome ): self {
		return new self( 'ref-1', false, IdentityVerdict::MATCH, 'ref-1', null, MarkerSupport::UNAVAILABLE, true, $outcome );
	}

	public static function confirming_method( string $method ): self {
		return new self(
			'ref-1',
			false,
			IdentityVerdict::MATCH,
			'ref-1',
			null,
			MarkerSupport::UNAVAILABLE,
			true,
			RemovalOutcome::REMOVED,
			$method
		);
	}

	public function id(): string {
		return 'recording';
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities(
			MarkerSupport::SUPPORTED === $this->marker_support,
			array( 'txt', 'http' ),
			false
		);
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		++$this->status_calls;

		return new SslStatus( SslState::REQUESTED, $ctx->provider_ref, null, null, $this->confirmed_method );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		++$this->identify_calls;

		$marker = null;

		if ( 'self' === $this->marker_installation ) {
			$marker = new ProviderMarker( $ctx->installation_id, $ctx->mapping_id, array() );
		} elseif ( null !== $this->marker_installation ) {
			$marker = new ProviderMarker( $this->marker_installation, $ctx->mapping_id, array() );
		}

		return new IdentityResult(
			$this->verdict,
			$ctx->provider_ref,
			$this->observed_ref,
			$ctx->host,
			$marker,
			$this->marker_support,
			$this->identity_complete,
			! $this->identity_complete
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CREATE, $ctx );
		++$this->create_calls;
		$this->observe_phase( $ctx );

		if ( $this->create_is_ambiguous ) {
			return new SslStatus( SslState::NONE, null, 'timeout', 'ambiguous', null, true );
		}

		return new SslStatus( SslState::REQUESTED, $this->created_ref );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::ADOPT, $ctx );
		++$this->adopt_calls;
		$this->observe_phase( $ctx );

		return new SslStatus( SslState::REQUESTED, $this->observed_ref );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CHANGE_METHOD, $ctx );
		++$this->method_calls;
		$this->observe_phase( $ctx );
		unset( $method );

		return new SslStatus( SslState::REQUESTED, $ctx->provider_ref, null, null, $this->confirmed_method );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_for( MutationOperation::REMOVE, $ctx );
		++$this->remove_calls;
		$this->observe_phase( $ctx );

		return new RemovalResult( $this->removal );
	}

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport {
		unset( $contexts );

		return new ReconcileReport( array(), true );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		unset( $ctx, $apex );

		++$this->plan_calls;

		return new ValidationPlan( array(), array(), array(), array(), array() );
	}

	private function observe_phase( SslResourceContext $ctx ): void {
		$row                     = ( new DbRepository() )->by_id( $ctx->mapping_id );
		$this->phases_observed[] = (string) $row?->ssl_mutation_phase?->value;
	}
}
