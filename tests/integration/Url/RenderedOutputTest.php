<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\Compatibility;
use WP_UnitTestCase;

final class RenderedOutputTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private int $root;

	private int $child;

	public function set_up(): void {
		parent::set_up();

		// Pretty permalinks, so user_trailingslashit() reflects a real site's
		// trailing-slash preference rather than the plain-permalink default.
		$this->set_permalink_structure( '/%postname%/' );

		Plugin::boot();

		$this->root  = $this->make_page( 'club', 0 );
		$this->child = $this->make_page( 'events', $this->root );

		Plugin::instance()->context()->set_serving( $this->serving_context( $this->root ) );
		Plugin::instance()->register_url_adapters();
	}

	public function test_every_matrix_surface_is_declared_with_its_hook(): void {
		foreach ( Compatibility::SURFACES as $surface ) {
			$this->assertArrayHasKey( 'hook', $surface );
			$this->assertArrayHasKey( 'rebased', $surface );
			$this->assertNotSame( '', $surface['hook'] );
		}
	}

	public function test_home_url_is_rebased(): void {
		$this->assertStringStartsWith( 'https://mapped.test', home_url( '/' ) );
	}

	public function test_site_url_is_not_rebased(): void {
		$this->assertStringStartsWith(
			'http://',
			site_url( '/' ),
			'site_url addresses the installation, not the served domain'
		);
		$this->assertStringNotContainsString( 'mapped.test', site_url( '/' ) );
	}

	public function test_a_descendant_permalink_is_rebased_and_uses_the_subtree_path(): void {
		$this->assertSame( 'https://mapped.test/events/', get_permalink( $this->child ) );
	}

	public function test_the_mapped_post_permalink_is_the_mapped_root(): void {
		$this->assertSame( 'https://mapped.test/', get_permalink( $this->root ) );
	}

	public function test_a_post_outside_the_subtree_keeps_its_primary_permalink(): void {
		$outside = $this->make_page( 'about-us', 0 );

		$this->assertStringNotContainsString(
			'mapped.test',
			get_permalink( $outside ),
			'a correct URL on the wrong domain beats a wrong URL on the right one'
		);
	}

	public function test_rest_url_is_rebased(): void {
		$this->assertStringStartsWith( 'https://mapped.test/', rest_url( 'wp/v2/posts' ) );
	}

	public function test_admin_ajax_is_rebased_but_the_rest_of_admin_is_not(): void {
		$this->assertStringStartsWith( 'https://mapped.test/', admin_url( 'admin-ajax.php' ) );
		$this->assertStringNotContainsString( 'mapped.test', admin_url( 'edit.php' ) );
	}

	public function test_the_rendered_page_carries_no_absolute_primary_host_url_in_its_links(): void {
		$html = '<a href="' . esc_url( get_permalink( $this->child ) ) . '">events</a>'
			. '<link rel="alternate" href="' . esc_url( get_feed_link() ) . '">';

		$this->assertStringNotContainsString( 'primary.test', $html );
	}

	public function test_adapters_no_op_without_a_serving_context(): void {
		Plugin::instance()->context()->set_serving( null );

		$this->assertStringNotContainsString( 'mapped.test', home_url( '/' ) );
	}
}
