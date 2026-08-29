<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class SubtreeTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Subtree $subtree;

	public function set_up(): void {
		parent::set_up();
		$this->subtree = new Subtree( new PathNormalizer() );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_path_segment_for_post' );
		remove_all_filters( 'pd_subtree_children' );
		parent::tear_down();
	}

	public function test_the_root_path_resolves_to_the_mapped_post(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );

		$resolution = $this->subtree->resolve_path( $context, '' );

		$this->assertSame( $root, $resolution?->post_id );
		$this->assertSame( 0, $resolution?->depth );
	}

	public function test_a_child_resolves_one_level_down(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );

		$this->assertSame( $child, $this->subtree->resolve_path( $context, 'events' )?->post_id );
	}

	public function test_a_grandchild_resolves_two_levels_down(): void {
		$root       = $this->make_page( 'club', 0 );
		$child      = $this->make_page( 'events', $root );
		$grandchild = $this->make_page( 'gala', $child );
		$context    = $this->serving_context( $root );

		$resolution = $this->subtree->resolve_path( $context, 'events/gala' );

		$this->assertSame( $grandchild, $resolution?->post_id );
		$this->assertSame( 2, $resolution?->depth );
	}

	public function test_a_post_outside_the_subtree_does_not_resolve(): void {
		$root = $this->make_page( 'club', 0 );
		$this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->subtree->resolve_path( $context, 'about-us' ) );
	}

	public function test_an_unpublished_child_does_not_resolve(): void {
		$root = $this->make_page( 'club', 0 );
		$this->make_page( 'draft-event', $root, 'draft' );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->subtree->resolve_path( $context, 'draft-event' ) );
	}

	public function test_the_depth_cap_stops_the_walk(): void {
		$root   = $this->make_page( 'club', 0 );
		$parent = $root;
		$path   = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$parent = $this->make_page( "level-{$i}", $parent );
			$path[] = "level-{$i}";
		}

		$context = $this->serving_context( $root, array( 'max_depth' => 3 ) );

		$this->assertNull( $this->subtree->resolve_path( $context, implode( '/', $path ) ) );
	}

	public function test_path_for_post_walks_back_up(): void {
		$root       = $this->make_page( 'club', 0 );
		$child      = $this->make_page( 'events', $root );
		$grandchild = $this->make_page( 'gala', $child );
		$context    = $this->serving_context( $root );

		$this->assertSame( 'events/gala', $this->subtree->path_for_post( $context, get_post( $grandchild ) ) );
		$this->assertSame( '', $this->subtree->path_for_post( $context, get_post( $root ) ) );
	}

	public function test_path_for_post_is_null_outside_the_subtree(): void {
		$root    = $this->make_page( 'club', 0 );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->subtree->path_for_post( $context, get_post( $outside ) ) );
	}

	public function test_belongs_to_mapping(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertTrue( $this->subtree->belongs_to_mapping( $context, get_post( $child ) ) );
		$this->assertFalse( $this->subtree->belongs_to_mapping( $context, get_post( $outside ) ) );
	}

	public function test_the_segment_filter_changes_both_directions(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );

		add_filter(
			'pd_path_segment_for_post',
			static function ( string $segment, \WP_Post $post ): string {
				unset( $post );

				return 'x-' . $segment;
			},
			10,
			2
		);

		$this->assertSame( 'x-events', $this->subtree->path_for_post( $context, get_post( $child ) ) );
		$this->assertSame( $child, $this->subtree->resolve_path( $context, 'x-events' )?->post_id );
	}

	public function test_the_round_trip_invariant_holds_over_a_generated_tree(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$ids     = array( $root );
		$parent  = $root;

		for ( $level = 0; $level < 4; $level++ ) {
			$parent = $this->make_page( "branch-{$level}", $parent );
			$ids[]  = $parent;

			for ( $leaf = 0; $leaf < 3; $leaf++ ) {
				$ids[] = $this->make_page( "leaf-{$level}-{$leaf}", $parent );
			}
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$path = $this->subtree->path_for_post( $context, $post );

			$this->assertNotNull( $path, "post {$id} belongs to the subtree and must have a path" );
			$this->assertSame(
				$id,
				$this->subtree->resolve_path( $context, $path )?->post_id,
				"round trip failed for post {$id} at path '{$path}'"
			);
		}
	}
}
