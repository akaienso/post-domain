<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\EndpointClass;

final class ClassifierTest extends TestCase {

	private function classify( string $path, array $server = array(
		'HTTP_HOST' => 'example.test',
		'PD_SAPI'   => 'fpm-fcgi',
	), array $get = array() ): EndpointClass {
		return ( new Classifier( 'wp-json' ) )->classify( $path, $server, $get );
	}

	/**
	 * @dataProvider paths
	 */
	public function test_paths_classify( string $path, EndpointClass $expected ): void {
		$this->assertSame( $expected, $this->classify( $path ) );
	}

	/**
	 * @return array<string, array{0: string, 1: EndpointClass}>
	 */
	public static function paths(): array {
		return array(
			'admin'            => array( '/wp-admin/edit.php', EndpointClass::ADMIN ),
			'admin root'       => array( '/wp-admin/', EndpointClass::ADMIN ),
			'ajax'             => array( '/wp-admin/admin-ajax.php', EndpointClass::AJAX ),
			'ajax not prefix'  => array( '/wp-admin/admin-ajax.php.bak', EndpointClass::ADMIN ),
			'login'            => array( '/wp-login.php', EndpointClass::LOGIN ),
			'signup'           => array( '/wp-signup.php', EndpointClass::INFRASTRUCTURE ),
			'rest management'  => array( '/wp-json/post-domain/v1/domains', EndpointClass::REST_MANAGEMENT ),
			'rest content'     => array( '/wp-json/wp/v2/posts', EndpointClass::REST_CONTENT ),
			'comment post'     => array( '/wp-comments-post.php', EndpointClass::COMMENT_POST ),
			'trackback'        => array( '/wp-trackback.php', EndpointClass::TRACKBACK ),
			'xmlrpc'           => array( '/xmlrpc.php', EndpointClass::XMLRPC ),
			'cron over http'   => array( '/wp-cron.php', EndpointClass::CRON_HTTP ),
			'opml'             => array( '/wp-links-opml.php', EndpointClass::INFRASTRUCTURE ),
			'uploads'          => array( '/wp-content/uploads/a.woff2', EndpointClass::ASSET ),
			'includes'         => array( '/wp-includes/js/a.js', EndpointClass::ASSET ),
			'robots'           => array( '/robots.txt', EndpointClass::WELL_KNOWN ),
			'favicon'          => array( '/favicon.ico', EndpointClass::WELL_KNOWN ),
			'well known'       => array( '/.well-known/post-domain-probe', EndpointClass::WELL_KNOWN ),
			'core sitemap'     => array( '/wp-sitemap.xml', EndpointClass::SITEMAP ),
			'plugin sitemap'   => array( '/sitemap_index.xml', EndpointClass::SITEMAP ),
			'ordinary content' => array( '/events/gala/', EndpointClass::ROUTED ),
			'root'             => array( '/', EndpointClass::ROUTED ),
		);
	}

	public function test_rest_route_query_form_is_rest_even_at_the_root(): void {
		$this->assertSame(
			EndpointClass::REST_CONTENT,
			$this->classify( '/', array( 'HTTP_HOST' => 'example.test' ), array( 'rest_route' => '/wp/v2/posts' ) )
		);
	}

	public function test_rest_route_query_form_detects_the_management_namespace(): void {
		$this->assertSame(
			EndpointClass::REST_MANAGEMENT,
			$this->classify( '/', array( 'HTTP_HOST' => 'example.test' ), array( 'rest_route' => '/post-domain/v1/domains' ) )
		);
	}

	public function test_cli_is_detected_from_hostlessness_not_from_doing_cron(): void {
		$this->assertSame(
			EndpointClass::CLI,
			$this->classify( '/wp-cron.php', array( 'PD_SAPI' => 'cli' ) )
		);

		$this->assertSame(
			EndpointClass::CRON_HTTP,
			$this->classify(
				'/wp-cron.php',
				array(
					'HTTP_HOST' => 'example.test',
					'PD_SAPI'   => 'fpm-fcgi',
				)
			),
			'wp-cron.php over HTTP stays host-validated'
		);
	}

	public function test_a_custom_rest_prefix_is_honoured(): void {
		$this->assertSame(
			EndpointClass::REST_CONTENT,
			( new Classifier( 'api' ) )->classify( '/api/wp/v2/posts', array( 'HTTP_HOST' => 'example.test' ), array() )
		);
	}
}
