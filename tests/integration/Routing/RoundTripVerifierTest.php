<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Contracts\RoutingContract;
use PostDomain\Routing\Resolution;
use PostDomain\Routing\RoundTripVerifier;
use PostDomain\Routing\ServingContext;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class RoundTripVerifierTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_a_path_that_round_trips_is_returned(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		$verifier = new RoundTripVerifier(
			new \PostDomain\Routing\Subtree( new \PostDomain\Routing\PathNormalizer() )
		);

		$this->assertSame(
			'events',
			$verifier->verified_path( $this->serving_context( $root ), get_post( $child ) )
		);
	}

	public function test_a_path_that_does_not_round_trip_is_rejected(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		$liar = new class() implements RoutingContract {
			public function resolve_path( ServingContext $context, string $path ): ?Resolution {
				return null;
			}

			public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
				return 'a-path-that-resolves-to-nothing';
			}

			public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
				return true;
			}
		};

		$this->assertNull(
			( new RoundTripVerifier( $liar ) )->verified_path( $this->serving_context( $root ), get_post( $child ) ),
			'a path the resolver would not accept must never be emitted'
		);
	}

	public function test_a_path_resolving_to_a_different_post_is_rejected(): void {
		$root = $this->make_page( 'club', 0 );
		$one  = $this->make_page( 'one', $root );
		$two  = $this->make_page( 'two', $root );

		$crossed = new class( $one ) implements RoutingContract {
			public function __construct( private readonly int $always ) {}

			public function resolve_path( ServingContext $context, string $path ): ?Resolution {
				return new Resolution( $this->always, 'page', 1, $path );
			}

			public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
				return 'two';
			}

			public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
				return true;
			}
		};

		$this->assertNull(
			( new RoundTripVerifier( $crossed ) )->verified_path( $this->serving_context( $root ), get_post( $two ) )
		);
	}

	public function test_the_result_is_memoized_within_one_request(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		$counter = new class() implements RoutingContract {
			public int $calls = 0;

			public function resolve_path( ServingContext $context, string $path ): ?Resolution {
				return new Resolution( $context->effective_post_id, 'page', 0, $path );
			}

			public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
				++$this->calls;

				return '';
			}

			public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
				return true;
			}
		};

		$verifier = new RoundTripVerifier( $counter );
		$context  = $this->serving_context( $root );
		$post     = get_post( $root );

		$verifier->verified_path( $context, $post );
		$verifier->verified_path( $context, $post );
		$verifier->verified_path( $context, $post );

		$this->assertSame( 1, $counter->calls, 'a pure function inside one request is memoized once' );
		unset( $child );
	}

	public function test_the_memo_key_separates_mappings(): void {
		$root_a = $this->make_page( 'club-a', 0 );
		$root_b = $this->make_page( 'club-b', 0 );
		$child  = $this->make_page( 'events', $root_a );

		$verifier = new RoundTripVerifier(
			new \PostDomain\Routing\Subtree( new \PostDomain\Routing\PathNormalizer() )
		);

		$this->assertSame(
			'events',
			$verifier->verified_path( $this->serving_context( $root_a, array( 'host' => 'a.test' ) ), get_post( $child ) )
		);
		$this->assertNull(
			$verifier->verified_path( $this->serving_context( $root_b, array( 'host' => 'b.test' ) ), get_post( $child ) ),
			'the same post has a different answer under a different mapping'
		);
	}
}
