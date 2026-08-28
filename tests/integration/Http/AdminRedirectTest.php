<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Http;

use PostDomain\Http\AdminRedirect;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use WP_UnitTestCase;

final class AdminRedirectTest extends WP_UnitTestCase {

	private function context( EndpointClass $endpoint, string $method = 'GET', HostKind $kind = HostKind::MAPPED ): HostContext {
		return new HostContext( 'mapped.test', null, 'mapped.test', $kind, null, $endpoint, true, $method );
	}

	public function test_admin_on_a_mapped_host_redirects_temporarily(): void {
		$redirect = ( new AdminRedirect( 'https://primary.test', true ) )
			->redirect_for( $this->context( EndpointClass::ADMIN ), '/wp-admin/edit.php?post_type=page' );

		$this->assertSame( 302, $redirect['status'] );
		$this->assertSame( 'https://primary.test/wp-admin/edit.php?post_type=page', $redirect['url'] );
	}

	public function test_a_non_idempotent_method_uses_307(): void {
		$redirect = ( new AdminRedirect( 'https://primary.test', true ) )
			->redirect_for( $this->context( EndpointClass::LOGIN, 'POST' ), '/wp-login.php' );

		$this->assertSame( 307, $redirect['status'], '307 preserves method and body' );
	}

	public function test_admin_ajax_is_exempt(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::AJAX ), '/wp-admin/admin-ajax.php' )
		);
	}

	public function test_the_primary_host_is_never_redirected(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::ADMIN, 'GET', HostKind::PRIMARY ), '/wp-admin/' )
		);
	}

	public function test_a_pending_mapping_still_redirects(): void {
		$this->assertNotNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::ADMIN ), '/wp-admin/' ),
			'a mapping that cannot serve is still not the primary host'
		);
	}

	public function test_the_redirect_can_be_disabled(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', false ) )
				->redirect_for( $this->context( EndpointClass::ADMIN ), '/wp-admin/' )
		);
	}

	public function test_a_routed_request_is_not_redirected(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::ROUTED ), '/events/' )
		);
	}
}
