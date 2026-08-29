<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class FeedMembershipTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_an_injected_non_member_is_removed_before_output(): void {
		Plugin::boot();

		$root    = $this->make_page( 'club', 0 );
		$inside  = $this->make_page( 'events', $root );
		$outside = $this->make_page( 'about-us', 0 );

		Plugin::instance()->context()->set_serving( $this->serving_context( $root ) );

		$query = new \WP_Query();
		$query->set( 'feed', 'rss2' );
		$query->is_feed = true;

		$kept = Plugin::instance()->enforce_membership(
			array( get_post( $inside ), get_post( $outside ) ),
			$query
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( $inside, $kept[0]->ID );
	}

	public function test_membership_is_not_applied_off_a_mapped_host(): void {
		Plugin::boot();

		$root    = $this->make_page( 'club', 0 );
		$outside = $this->make_page( 'about-us', 0 );

		Plugin::instance()->context()->set_serving( null );

		$query          = new \WP_Query();
		$query->is_feed = true;

		$this->assertCount(
			1,
			Plugin::instance()->enforce_membership( array( get_post( $outside ) ), $query ),
			'the primary host keeps its own behaviour'
		);
		unset( $root );
	}

	public function test_an_unbounded_scope_yields_an_empty_feed_rather_than_everything(): void {
		Plugin::boot();

		$root = $this->make_page( 'club', 0 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_page( "child-{$i}", $root );
		}

		add_filter( 'pd_scope_enumeration_limit', static fn(): int => 2 );

		Plugin::instance()->context()->set_serving( $this->serving_context( $root ) );

		$query          = new \WP_Query();
		$query->is_feed = true;

		Plugin::instance()->scope_feed_query( $query );

		$this->assertSame( array( 0 ), $query->get( 'post__in' ), 'an impossible id yields an empty result set' );

		remove_all_filters( 'pd_scope_enumeration_limit' );
	}
}
