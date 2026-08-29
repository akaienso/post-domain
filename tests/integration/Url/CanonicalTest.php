<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\Canonical\CanonicalPolicy;
use WP_UnitTestCase;

final class CanonicalTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private int $root;

	private int $child;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::boot();
		$this->root  = $this->make_page( 'club', 0 );
		$this->child = $this->make_page( 'events', $this->root );
		Plugin::instance()->context()->set_serving( $this->serving_context( $this->root ) );
		Plugin::instance()->register_url_adapters();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_canonical_url' );
		parent::tear_down();
	}

	private function query_for( int $post_id ): \WP_Query {
		$query              = new \WP_Query( array( 'page_id' => $post_id ) );
		$query->is_singular = true;

		return $query;
	}

	public function test_canonical_uses_the_mapped_host(): void {
		$canonical = CanonicalPolicy::for_request(
			Plugin::instance()->context()->host(),
			Plugin::instance()->context()->serving(),
			$this->query_for( $this->child )
		);

		$this->assertSame( 'https://mapped.test/events/', $canonical?->url );
	}

	public function test_canonical_is_computed_fresh_each_call(): void {
		$serving = Plugin::instance()->context()->serving();
		$host    = Plugin::instance()->context()->host();
		$query   = $this->query_for( $this->child );

		$first = CanonicalPolicy::for_request( $host, $serving, $query );

		add_filter(
			'pd_canonical_url',
			static fn(): \PostDomain\Url\Canonical\CanonicalUrl
			=> new \PostDomain\Url\Canonical\CanonicalUrl( 'https://mapped.test/override/' )
		);

		$second = CanonicalPolicy::for_request( $host, $serving, $query );

		$this->assertNotSame( $first?->url, $second?->url, 'nothing is cached between calls' );
	}

	public function test_a_filter_returning_a_foreign_host_is_rejected(): void {
		add_filter(
			'pd_canonical_url',
			static fn(): \PostDomain\Url\Canonical\CanonicalUrl
			=> new \PostDomain\Url\Canonical\CanonicalUrl( 'https://evil.test/x' )
		);

		$canonical = CanonicalPolicy::for_request(
			Plugin::instance()->context()->host(),
			Plugin::instance()->context()->serving(),
			$this->query_for( $this->child )
		);

		$this->assertSame( 'https://mapped.test/events/', $canonical?->url );
	}

	public function test_redirect_canonical_rewrites_a_primary_permalink_proposal(): void {
		$guard = new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard(
			Plugin::instance()->context()
		);

		$proposal = home_url( '/club/events/' );
		$result   = $guard->filter_proposal( $proposal, 'https://mapped.test/events/' );

		$this->assertStringStartsWith( 'https://mapped.test', (string) $result );
	}

	public function test_redirect_canonical_returns_false_when_the_result_matches_the_request(): void {
		$guard = new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard(
			Plugin::instance()->context()
		);

		$this->assertFalse(
			$guard->filter_proposal( 'https://mapped.test/events/', 'https://mapped.test/events/' )
		);
	}

	public function test_an_unrelated_core_proposal_stands(): void {
		$guard = new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard(
			Plugin::instance()->context()
		);

		$this->assertSame(
			'https://mapped.test/events/?paged=2',
			$guard->filter_proposal( 'https://mapped.test/events/?paged=2', 'https://mapped.test/events' ),
			'trailing-slash and pagination corrections keep working'
		);
	}

	public function test_no_canonical_off_a_mapped_host(): void {
		Plugin::instance()->context()->set_serving( null );

		$this->assertNull(
			CanonicalPolicy::for_request(
				Plugin::instance()->context()->host(),
				null,
				$this->query_for( $this->child )
			)
		);
	}
}
