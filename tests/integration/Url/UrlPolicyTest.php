<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;
use WP_UnitTestCase;

final class UrlPolicyTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private UrlPolicy $policy;

	private \PostDomain\Routing\ServingContext $context;

	public function set_up(): void {
		parent::set_up();
		$this->policy  = new UrlPolicy( 'https://primary.test' );
		$this->context = $this->serving_context( $this->make_page( 'club', 0 ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_rebase_url' );
		remove_all_filters( 'pd_is_rebasable_path' );
		parent::tear_down();
	}

	public function test_a_primary_host_url_is_rebased(): void {
		$this->assertSame(
			'https://mapped.test/wp-json/wp/v2/posts',
			$this->policy->rebase( 'https://primary.test/wp-json/wp/v2/posts', $this->context, UrlKind::REST )
		);
	}

	public function test_a_url_already_on_the_mapped_host_is_untouched(): void {
		$this->assertSame(
			'https://mapped.test/events/',
			$this->policy->rebase( 'https://mapped.test/events/', $this->context, UrlKind::PERMALINK )
		);
	}

	public function test_a_third_party_url_is_untouched(): void {
		$this->assertSame(
			'https://example.org/x',
			$this->policy->rebase( 'https://example.org/x', $this->context, UrlKind::PERMALINK )
		);
	}

	/**
	 * @dataProvider protected_paths
	 */
	public function test_protected_paths_are_never_rebased( string $path ): void {
		$url = 'https://primary.test' . $path;

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::HOME ) );
	}

	/** @return array<string, array{0: string}> */
	public static function protected_paths(): array {
		return array(
			'admin'      => array( '/wp-admin/edit.php' ),
			'login'      => array( '/wp-login.php' ),
			'signup'     => array( '/wp-signup.php' ),
			'activate'   => array( '/wp-activate.php' ),
			'xmlrpc'     => array( '/xmlrpc.php' ),
			'cron'       => array( '/wp-cron.php' ),
			'management' => array( '/wp-json/post-domain/v1/domains' ),
		);
	}

	public function test_admin_ajax_is_exempt_from_the_admin_protection(): void {
		$this->assertSame(
			'https://mapped.test/wp-admin/admin-ajax.php',
			$this->policy->rebase( 'https://primary.test/wp-admin/admin-ajax.php', $this->context, UrlKind::AJAX )
		);
	}

	public function test_the_ajax_exemption_is_an_exact_match(): void {
		$url = 'https://primary.test/wp-admin/admin-ajax.php.bak';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::AJAX ) );
	}

	public function test_a_filter_cannot_make_a_protected_path_rebasable(): void {
		add_filter( 'pd_is_rebasable_path', '__return_true' );

		$url = 'https://primary.test/wp-admin/edit.php';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::HOME ) );
	}

	public function test_a_filter_returning_a_foreign_host_is_rejected(): void {
		add_filter( 'pd_rebase_url', static fn(): string => 'https://evil.test/x' );

		$url = 'https://primary.test/events/';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::PERMALINK ) );
	}

	public function test_a_filter_returning_a_relative_url_is_rejected(): void {
		add_filter( 'pd_rebase_url', static fn(): string => '/events/' );

		$url = 'https://primary.test/events/';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::PERMALINK ) );
	}

	public function test_a_filter_may_supply_a_mapped_host_url(): void {
		add_filter( 'pd_rebase_url', static fn(): string => 'https://mapped.test/custom/' );

		$this->assertSame(
			'https://mapped.test/custom/',
			$this->policy->rebase( 'https://primary.test/events/', $this->context, UrlKind::PERMALINK )
		);
	}
}
