<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;

/**
 * A typed finalization payload with an explicit column allowlist, so no caller
 * can put an arbitrary column name into SQL and every row invariant stays here.
 */
final class LeaseOutcome {

	public const ALLOWED_COLUMNS = array(
		'ssl_state',
		'ssl_ref',
		'ssl_provider',
		'ssl_provider_environment',
		'ssl_ownership_origin',
		'ssl_owner_installation_id',
		'ssl_adopted_at',
		'ssl_adopted_by',
		'ssl_method',
		'ssl_method_requested_at',
		'ssl_marker_support',
		'ssl_checked_at',
		'ssl_next_attempt_at',
		'ssl_transient_count',
		'ssl_provider_state',
		'ssl_error',
		'deletion_attempts',
		'deletion_next_attempt_at',
		'ssl_removal_scope',
	);

	/** @param array<string, string|int|null> $columns */
	private function __construct( private readonly array $columns ) {
		foreach ( array_keys( $columns ) as $column ) {
			if ( ! in_array( $column, self::ALLOWED_COLUMNS, true ) ) {
				throw new \InvalidArgumentException( "Column {$column} may not be finalized through a lease." );
			}
		}
	}

	/** @param array<string, string|int|null> $columns */
	public static function raw( array $columns ): self {
		return new self( $columns );
	}

	/**
	 * The same outcome with extra columns folded in, so a caller can add what
	 * only it knows without rebuilding what a shared helper already decided.
	 * The added columns go through the same allowlist.
	 *
	 * @param array<string, string|int|null> $columns
	 */
	public function with( array $columns ): self {
		return new self( array_merge( $this->columns, $columns ) );
	}

	public static function state( SslState $state ): self {
		return new self(
			array(
				'ssl_state'      => $state->value,
				'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * The environment is promoted here, in the same CAS as the reference and the
	 * provider: a resource that exists somewhere must record where, or the next
	 * ordinary read falls back to current configuration (spec §12.6).
	 */
	public static function bound(
		SslState $state,
		string $ref,
		string $provider_id,
		string $environment_id,
		OwnershipOrigin $origin,
		string $installation_id
	): self {
		return new self(
			array(
				'ssl_state'                 => $state->value,
				'ssl_ref'                   => $ref,
				'ssl_provider'              => $provider_id,
				'ssl_provider_environment'  => $environment_id,
				'ssl_ownership_origin'      => $origin->value,
				'ssl_owner_installation_id' => $installation_id,
				'ssl_checked_at'            => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function adopted(
		SslState $state,
		string $ref,
		string $provider_id,
		string $environment_id,
		string $installation_id,
		int $user_id
	): self {
		return new self(
			array(
				'ssl_state'                 => $state->value,
				'ssl_ref'                   => $ref,
				'ssl_provider'              => $provider_id,
				'ssl_provider_environment'  => $environment_id,
				'ssl_ownership_origin'      => OwnershipOrigin::ADOPTED->value,
				'ssl_owner_installation_id' => $installation_id,
				'ssl_adopted_at'            => gmdate( 'Y-m-d H:i:s' ),
				'ssl_adopted_by'            => $user_id,
				'ssl_checked_at'            => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function method_confirmed( string $method ): self {
		return new self(
			array(
				'ssl_method'              => $method,
				'ssl_method_requested_at' => gmdate( 'Y-m-d H:i:s' ),
				'ssl_checked_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function failure( SslState $state, string $code, string $message ): self {
		return new self(
			array(
				'ssl_state'      => $state->value,
				'ssl_error'      => (string) wp_json_encode(
					array(
						'code'    => $code,
						'message' => mb_substr( $message, 0, 500 ),
						'at'      => gmdate( 'Y-m-d H:i:s' ),
					)
				),
				'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function checked(): self {
		return new self( array( 'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public static function attempted( int $attempts, int $next_attempt_in ): self {
		return new self(
			array(
				'deletion_attempts'        => $attempts,
				'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $next_attempt_in ),
			)
		);
	}

	/** @param array<string, mixed> $state */
	public static function provider_state( array $state ): self {
		return new self( array( 'ssl_provider_state' => (string) wp_json_encode( $state ) ) );
	}

	public function merge( self $other ): self {
		return new self( array_merge( $this->columns, $other->columns ) );
	}

	/** @return array<string, string|int|null> */
	public function columns(): array {
		return $this->columns;
	}
}
