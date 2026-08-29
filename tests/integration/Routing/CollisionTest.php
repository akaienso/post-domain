<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\AmbiguousPath;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class CollisionTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Subtree $subtree;

	public function set_up(): void {
		parent::set_up();
		register_post_type(
			'pd_event',
			array(
				'public'       => true,
				'hierarchical' => true,
			)
		);
		$this->subtree = new Subtree( new PathNormalizer() );
		AmbiguousPath::reset();
	}

	public function tear_down(): void {
		unregister_post_type( 'pd_event' );
		remove_all_filters( 'pd_resolve_ambiguity' );
		parent::tear_down();
	}

	private function collide(): array {
		$root = $this->make_page( 'club', 0 );

		$page = $this->make_page( 'gala', $root );

		$event = self::factory()->post->create(
			array(
				'post_type'   => 'pd_event',
				'post_status' => 'publish',
				'post_name'   => 'gala',
				'post_parent' => $root,
			)
		);

		$context = $this->serving_context( $root );
		$context = new \PostDomain\Routing\ServingContext(
			$context->mapping,
			$context->requested_host,
			$context->canonical_host,
			true,
			$root,
			array( 'page', 'pd_event' ),
			array( 'publish' ),
			10,
			$context->preserved_query_vars
		);

		return array( $context, $page, $event );
	}

	public function test_an_ambiguous_segment_does_not_resolve(): void {
		[ $context ] = $this->collide();

		$this->assertNull( $this->subtree->resolve_path( $context, 'gala' ) );
	}

	public function test_every_colliding_candidate_loses_its_path(): void {
		[ $context, $page, $event ] = $this->collide();

		$this->assertNull( $this->subtree->path_for_post( $context, get_post( $page ) ) );
		$this->assertNull( $this->subtree->path_for_post( $context, get_post( $event ) ) );
	}

	public function test_the_collision_is_recorded_for_diagnostics(): void {
		[ $context ] = $this->collide();
		$this->subtree->resolve_path( $context, 'gala' );

		$collisions = AmbiguousPath::all();

		$this->assertCount( 1, $collisions );
		$this->assertSame( 'gala', $collisions[0]['segment'] );
		$this->assertCount( 2, $collisions[0]['candidates'] );
	}

	public function test_an_integrator_may_arbitrate(): void {
		[ $context, $page ] = $this->collide();

		add_filter(
			'pd_resolve_ambiguity',
			static fn( $winner, $mapping, array $candidates ): \WP_Post => $candidates[0],
			10,
			3
		);

		$this->assertSame( $page, $this->subtree->resolve_path( $context, 'gala' )?->post_id );
	}

	public function test_the_shipped_default_arbitrates_nothing(): void {
		[ $context ] = $this->collide();

		$this->assertNull(
			apply_filters( 'pd_resolve_ambiguity', null, $context->mapping, array(), 'gala' )
		);
	}
}
