<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\QueryVarPolicy;
use WP_UnitTestCase;

final class QueryVarPolicyTest extends WP_UnitTestCase {

	private function mapping(): Mapping {
		return new Mapping(
			1,
			'mapped.test',
			null,
			42,
			1,
			VerificationState::VERIFIED,
			ActivationState::ACTIVE,
			SslState::NONE,
			null,
			str_repeat( 'a', 32 ),
			'_post-domain-challenge'
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_preserved_query_vars' );
		parent::tear_down();
	}

	public function test_the_default_allowlist(): void {
		$this->assertSame(
			array( 'paged', 'page', 'cpage', 'replytocom', 'feed', 'embed' ),
			QueryVarPolicy::preserved( $this->mapping() )
		);
	}

	public function test_preview_vars_are_absent_by_default(): void {
		$preserved = QueryVarPolicy::preserved( $this->mapping() );

		foreach ( array( 'preview', 'preview_id', 'preview_nonce', 'attachment' ) as $var ) {
			$this->assertNotContains( $var, $preserved );
		}
	}

	public function test_a_filter_may_add_a_harmless_var(): void {
		add_filter(
			'pd_preserved_query_vars',
			static fn( array $vars ): array => array_merge( $vars, array( 'utm_medium' ) )
		);

		$this->assertContains( 'utm_medium', QueryVarPolicy::preserved( $this->mapping() ) );
	}

	public function test_reserved_routing_vars_are_subtracted_unconditionally(): void {
		add_filter(
			'pd_preserved_query_vars',
			static fn( array $vars ): array => array_merge(
				$vars,
				array( 'p', 'page_id', 'post_type', 'name', 'pagename', 'rest_route', 'preview' )
			)
		);

		$preserved = QueryVarPolicy::preserved( $this->mapping() );

		foreach ( QueryVarPolicy::RESERVED as $reserved ) {
			$this->assertNotContains( $reserved, $preserved, "{$reserved} must never be reintroduced" );
		}
	}

	public function test_malformed_var_names_are_dropped(): void {
		add_filter(
			'pd_preserved_query_vars',
			static fn( array $vars ): array => array_merge(
				$vars,
				array( 'Bad-Name', 'ok_name', str_repeat( 'x', 40 ), '' )
			)
		);

		$preserved = QueryVarPolicy::preserved( $this->mapping() );

		$this->assertContains( 'ok_name', $preserved );
		$this->assertNotContains( 'Bad-Name', $preserved );
		$this->assertNotContains( str_repeat( 'x', 40 ), $preserved );
	}
}
