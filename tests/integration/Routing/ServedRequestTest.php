<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Plugin;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class ServedRequestTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_parse_request_is_registered_after_the_guard(): void {
		Plugin::boot();

		$this->assertSame( 0, has_action( 'parse_request', array( Plugin::instance(), 'enforce_disposition' ) ) );
		$this->assertSame( 1, has_action( 'parse_request', array( Plugin::instance(), 'resolve_request' ) ) );
	}

	public function test_a_mapped_root_request_sets_the_mapped_post(): void {
		Plugin::boot();

		$root = $this->make_page( 'club', 0 );
		Plugin::instance()->context()->set_serving( $this->serving_context( $root ) );

		// The resolver runs only for a routed request on a mapped host (spec §5.4).
		Plugin::instance()->context()->set_host(
			new HostContext(
				'mapped.test',
				null,
				'mapped.test',
				HostKind::MAPPED,
				null,
				EndpointClass::ROUTED,
				true,
				'GET'
			)
		);

		$wp             = new \WP();
		$wp->request    = '';
		$wp->query_vars = array();

		Plugin::instance()->resolve_request( $wp );

		$this->assertSame( $root, (int) $wp->query_vars['page_id'] );
		$this->assertNotNull( Plugin::instance()->context()->serving()?->resolution );
	}

	public function test_a_request_off_a_mapped_host_is_untouched(): void {
		Plugin::boot();
		Plugin::instance()->context()->set_serving( null );

		$wp             = new \WP();
		$wp->request    = 'about-us';
		$wp->query_vars = array( 'pagename' => 'about-us' );

		Plugin::instance()->resolve_request( $wp );

		$this->assertSame( array( 'pagename' => 'about-us' ), $wp->query_vars );
	}
}
