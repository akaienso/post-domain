<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\UnknownHostGuard;
use WP_UnitTestCase;

final class UnknownHostGuardTest extends WP_UnitTestCase {

	private function context( HostKind $kind, EndpointClass $endpoint = EndpointClass::ROUTED ): HostContext {
		return new HostContext( 'x', null, null, $kind, null, $endpoint, true, 'GET' );
	}

	public function test_a_malformed_authority_is_400(): void {
		$this->assertSame(
			400,
			( new UnknownHostGuard( '421' ) )->response_for( $this->context( HostKind::MALFORMED ) )
		);
	}

	public function test_an_unknown_host_is_421_by_default(): void {
		$this->assertSame(
			421,
			( new UnknownHostGuard( '421' ) )->response_for( $this->context( HostKind::UNKNOWN ) )
		);
	}

	public function test_passthrough_policy_lets_an_unknown_host_continue(): void {
		$this->assertNull(
			( new UnknownHostGuard( 'passthrough' ) )->response_for( $this->context( HostKind::UNKNOWN ) )
		);
	}

	public function test_passthrough_policy_still_rejects_a_malformed_authority(): void {
		$this->assertSame(
			400,
			( new UnknownHostGuard( 'passthrough' ) )->response_for( $this->context( HostKind::MALFORMED ) )
		);
	}

	public function test_primary_mapped_and_infrastructure_continue(): void {
		$guard = new UnknownHostGuard( '421' );

		foreach ( array( HostKind::PRIMARY, HostKind::MAPPED, HostKind::ALLOWED_INFRASTRUCTURE ) as $kind ) {
			$this->assertNull( $guard->response_for( $this->context( $kind ) ) );
		}
	}

	public function test_cli_never_fires_the_guard(): void {
		$this->assertNull(
			( new UnknownHostGuard( '421' ) )->response_for(
				$this->context( HostKind::UNKNOWN, EndpointClass::CLI )
			)
		);
	}

	public function test_cron_over_http_is_still_guarded(): void {
		$this->assertSame(
			421,
			( new UnknownHostGuard( '421' ) )->response_for(
				$this->context( HostKind::UNKNOWN, EndpointClass::CRON_HTTP )
			),
			'wp-cron.php over HTTP is an ordinary request with a Host header'
		);
	}

	public function test_an_unrecognised_policy_falls_back_to_421(): void {
		$this->assertSame(
			421,
			( new UnknownHostGuard( 'nonsense' ) )->response_for( $this->context( HostKind::UNKNOWN ) )
		);
	}
}
