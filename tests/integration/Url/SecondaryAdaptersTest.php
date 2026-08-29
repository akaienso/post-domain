<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class SecondaryAdaptersTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private int $root;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot();
		$this->root = $this->make_page( 'club', 0 );
		Plugin::instance()->context()->set_serving( $this->serving_context( $this->root ) );
		Plugin::instance()->register_url_adapters();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_filter_home_option' );
		parent::tear_down();
	}

	public function test_feed_links_are_rebased(): void {
		$this->assertStringStartsWith( 'https://mapped.test/', get_feed_link() );
	}

	public function test_the_comment_form_action_stays_on_the_mapped_host(): void {
		$defaults = apply_filters( 'comment_form_defaults', array( 'action' => site_url( '/wp-comments-post.php' ) ) );

		$this->assertStringStartsWith(
			'https://mapped.test/',
			$defaults['action'],
			'a visitor must never leave the domain to comment'
		);
	}

	public function test_the_comment_post_redirect_returns_to_the_mapped_host(): void {
		$redirect = apply_filters(
			'comment_post_redirect',
			home_url( '/events/#comment-1' ),
			new \stdClass()
		);

		$this->assertStringStartsWith( 'https://mapped.test/', $redirect );
	}

	public function test_sitemap_entries_are_rebased(): void {
		$entry = apply_filters(
			'wp_sitemaps_index_entry',
			array( 'loc' => home_url( '/wp-sitemap-posts-page-1.xml' ) ),
			'post',
			'page',
			1
		);

		$this->assertStringStartsWith( 'https://mapped.test/', $entry['loc'] );
	}

	public function test_the_home_option_filter_is_off_by_default(): void {
		$this->assertStringNotContainsString(
			'mapped.test',
			(string) get_option( 'home' ),
			'pre_option_home is opt-in because it fires for everything, including cron and mail'
		);
	}

	public function test_the_home_option_filter_applies_when_opted_in(): void {
		add_filter( 'pd_filter_home_option', '__return_true' );
		Plugin::instance()->register_url_adapters();

		$this->assertStringContainsString( 'mapped.test', (string) get_option( 'home' ) );
	}

	public function test_the_home_option_filter_never_applies_in_admin(): void {
		add_filter( 'pd_filter_home_option', '__return_true' );
		set_current_screen( 'dashboard' );
		Plugin::instance()->register_url_adapters();

		$this->assertStringNotContainsString( 'mapped.test', (string) get_option( 'home' ) );

		set_current_screen( 'front' );
	}
}
