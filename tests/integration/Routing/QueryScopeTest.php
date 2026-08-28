<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\EnumerationScopeProvider;
use PostDomain\Routing\MembershipFilter;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\QueryScope;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class QueryScopeTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Subtree $subtree;

	public function set_up(): void {
		parent::set_up();
		$this->subtree = new Subtree( new PathNormalizer() );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_query_scope' );
		parent::tear_down();
	}

	public function test_the_enumeration_provider_bounds_a_small_subtree(): void {
		$root  = $this->make_page( 'club', 0 );
		$one   = $this->make_page( 'one', $root );
		$two   = $this->make_page( 'two', $root );
		$scope = ( new EnumerationScopeProvider( $this->subtree, 500 ) )->scope( $this->serving_context( $root ) );

		$this->assertTrue( $scope->is_bounded );
		$this->assertEqualsCanonicalizing( array( $root, $one, $two ), $scope->post__in );
	}

	public function test_every_scope_ignores_sticky_posts(): void {
		$root  = $this->make_page( 'club', 0 );
		$scope = ( new EnumerationScopeProvider( $this->subtree, 500 ) )->scope( $this->serving_context( $root ) );

		$this->assertTrue( $scope->query_args['ignore_sticky_posts'] );
	}

	public function test_a_subtree_over_the_limit_is_unbounded_and_therefore_empty(): void {
		$root = $this->make_page( 'club', 0 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_page( "child-{$i}", $root );
		}

		$scope = ( new EnumerationScopeProvider( $this->subtree, 3 ) )->scope( $this->serving_context( $root ) );

		$this->assertFalse( $scope->is_bounded );
	}

	public function test_an_empty_inclusion_array_short_circuits(): void {
		$scope = QueryScope::of_ids( array() );

		$this->assertFalse(
			$scope->is_bounded,
			'an empty post__in is ignored by WP_Query, which turns nothing-matches into everything-matches'
		);
	}

	public function test_membership_is_enforced_over_supplied_ids(): void {
		$root    = $this->make_page( 'club', 0 );
		$inside  = $this->make_page( 'events', $root );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$kept = ( new MembershipFilter( $this->subtree ) )->keep_members(
			array( get_post( $inside ), get_post( $outside ) ),
			$context
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( $inside, $kept[0]->ID );
	}

	public function test_a_filter_supplied_scope_is_still_membership_checked(): void {
		$root    = $this->make_page( 'club', 0 );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		add_filter( 'pd_query_scope', static fn(): QueryScope => QueryScope::of_ids( array( $outside ) ) );

		$kept = ( new MembershipFilter( $this->subtree ) )->keep_members( array( get_post( $outside ) ), $context );

		$this->assertSame( array(), $kept, 'post__in scopes are validated too' );
	}

	public function test_a_non_scope_filter_return_is_replaced_with_unbounded(): void {
		$root = $this->make_page( 'club', 0 );

		add_filter( 'pd_query_scope', static fn(): string => 'nonsense' );

		$scope = ( new EnumerationScopeProvider( $this->subtree, 500 ) )->scope( $this->serving_context( $root ) );

		$this->assertFalse( $scope->is_bounded );
	}
}
