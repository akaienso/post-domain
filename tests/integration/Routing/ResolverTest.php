<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\PathDecomposer;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Resolver;
use PostDomain\Routing\Subtree;
use PostDomain\Routing\UnmatchedPolicy;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class ResolverTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Resolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new Resolver(
			new Subtree( new PathNormalizer() ),
			new PathDecomposer()
		);
	}

	private function wp( string $request ): \WP {
		$wp             = new \WP();
		$wp->request    = trim( $request, '/' );
		$wp->query_vars = array();

		return $wp;
	}

	public function test_the_root_sets_the_mapped_post(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/' );

		$this->assertSame( $root, $this->resolver->resolve( $context, $wp )?->post_id );
		$this->assertSame( $root, (int) $wp->query_vars['page_id'] );
	}

	public function test_a_descendant_sets_its_own_id(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/events/' );

		$this->assertSame( $child, $this->resolver->resolve( $context, $wp )?->post_id );
		$this->assertSame( $child, (int) $wp->query_vars['page_id'] );
	}

	public function test_a_descendant_feed_sets_the_feed_var(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/events/feed/atom/' );

		$this->assertSame( $child, $this->resolver->resolve( $context, $wp )?->post_id );
		$this->assertSame( 'atom', $wp->query_vars['feed'] );
	}

	public function test_pagination_is_promoted(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/page/3/' );

		$this->resolver->resolve( $context, $wp );

		$this->assertSame( 3, (int) $wp->query_vars['paged'] );
	}

	public function test_only_allowlisted_vars_are_promoted(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/' );

		$wp->query_vars = array(
			'preview' => 'true',
			'name'    => 'evil',
			'paged'   => '2',
		);

		$this->resolver->resolve( $context, $wp );

		$this->assertArrayNotHasKey( 'preview', $wp->query_vars );
		$this->assertArrayNotHasKey( 'name', $wp->query_vars );
		$this->assertSame( '2', $wp->query_vars['paged'] );
	}

	public function test_a_miss_returns_null(): void {
		$root = $this->make_page( 'club', 0 );
		$this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->resolver->resolve( $context, $this->wp( '/about-us/' ) ) );
	}

	public function test_unmatched_get_redirects_temporarily_with_the_query_intact(): void {
		$policy   = new UnmatchedPolicy( 'redirect', 'https://primary.test' );
		$response = $policy->response_for( 'GET', '/about-us/?utm_source=news' );

		$this->assertSame( 302, $response['status'] );
		$this->assertSame( 'https://primary.test/about-us/?utm_source=news', $response['url'] );
	}

	public function test_unmatched_post_is_404_rather_than_a_cross_host_bounce(): void {
		$response = ( new UnmatchedPolicy( 'redirect', 'https://primary.test' ) )
			->response_for( 'POST', '/about-us/' );

		$this->assertSame( 404, $response['status'] );
		$this->assertArrayNotHasKey( 'url', $response );
	}

	public function test_the_404_mode_never_redirects(): void {
		$this->assertSame(
			404,
			( new UnmatchedPolicy( '404', 'https://primary.test' ) )->response_for( 'GET', '/x/' )['status']
		);
	}

	public function test_the_passthrough_mode_returns_null(): void {
		$this->assertNull(
			( new UnmatchedPolicy( 'passthrough', 'https://primary.test' ) )->response_for( 'GET', '/x/' )
		);
	}

	public function test_an_unrecognised_mode_falls_back_to_redirect(): void {
		$this->assertSame(
			302,
			( new UnmatchedPolicy( 'nonsense', 'https://primary.test' ) )->response_for( 'GET', '/x/' )['status']
		);
	}
}
