<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

/**
 * A memo of a pure function within one request, not a cache: there is no window
 * in which the inputs change and the stored answer persists.
 */
final class RoundTripVerifier {

	/** @var array<string, string|null> */
	private array $memo = array();

	public function __construct( private readonly RoutingContract $routing ) {}

	public function verified_path( ServingContext $context, \WP_Post $post ): ?string {
		$key = sprintf( '%d:%d:%d', $context->mapping->id, $context->effective_post_id, $post->ID );

		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$path = $this->routing->path_for_post( $context, $post );

		if ( null === $path ) {
			$this->memo[ $key ] = null;

			return null;
		}

		$resolved = $this->routing->resolve_path( $context, $path );

		$this->memo[ $key ] = ( null !== $resolved && $resolved->post_id === $post->ID ) ? $path : null;

		return $this->memo[ $key ];
	}
}
