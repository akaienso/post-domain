<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Plugin;
use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

	public function test_hooks_register_at_the_specified_priorities(): void {
		Plugin::boot();

		$this->assertSame( 0, has_action( 'plugins_loaded', array( Plugin::instance(), 'build_host_context' ) ) );
		$this->assertSame( 1, has_action( 'plugins_loaded', array( Plugin::instance(), 'guard_unknown_host' ) ) );
		$this->assertSame( 2, has_action( 'plugins_loaded', array( Plugin::instance(), 'redirect_admin' ) ) );
		$this->assertSame( 11, has_action( 'plugins_loaded', array( Plugin::instance(), 'freeze_eligibility' ) ) );
		$this->assertSame( 99, has_action( 'init', array( Plugin::instance(), 'freeze_content_policy' ) ) );
		$this->assertSame( 0, has_action( 'parse_request', array( Plugin::instance(), 'enforce_disposition' ) ) );
	}

	public function test_the_container_exposes_the_context_holder_and_repository(): void {
		Plugin::boot();

		$this->assertInstanceOf( \PostDomain\Routing\ContextHolder::class, Plugin::instance()->context() );
		$this->assertInstanceOf( \PostDomain\Contracts\MappingRepository::class, Plugin::instance()->repository() );
	}

	public function test_booting_twice_does_not_double_register(): void {
		Plugin::boot();
		Plugin::boot();

		$this->assertSame(
			1,
			count(
				array_filter(
					$GLOBALS['wp_filter']['init'][99] ?? array(),
					static fn( array $entry ): bool =>
					is_array( $entry['function'] ) && $entry['function'][0] instanceof Plugin
				)
			)
		);
	}
}
