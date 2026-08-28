<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Support\BackgroundContext;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class BackgroundContextTest extends WP_UnitTestCase {

	private int $mapping_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Plugin::boot();
		Plugin::instance()->register_url_adapters();

		$post = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->mapping_id = ( new DbRepository() )->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				$post,
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		)->id;
	}

	public function test_without_a_wrapper_urls_are_primary(): void {
		Plugin::instance()->context()->set_serving( null );

		$this->assertStringNotContainsString( 'mapped.test', home_url( '/' ) );
	}

	public function test_inside_the_wrapper_urls_are_mapped(): void {
		Plugin::instance()->context()->set_serving( null );

		$url = pd_with_mapping( $this->mapping_id, static fn(): string => home_url( '/' ) );

		$this->assertStringContainsString( 'mapped.test', (string) $url );
	}

	public function test_the_previous_context_is_restored_afterwards(): void {
		Plugin::instance()->context()->set_serving( null );

		pd_with_mapping( $this->mapping_id, static fn(): string => home_url( '/' ) );

		$this->assertNull( Plugin::instance()->context()->serving() );
	}

	public function test_the_context_is_restored_even_when_the_callback_throws(): void {
		Plugin::instance()->context()->set_serving( null );

		try {
			pd_with_mapping(
				$this->mapping_id,
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertNull( Plugin::instance()->context()->serving() );
	}

	public function test_an_unknown_mapping_runs_the_callback_with_primary_context(): void {
		Plugin::instance()->context()->set_serving( null );

		$url = pd_with_mapping( 999999, static fn(): string => home_url( '/' ) );

		$this->assertStringNotContainsString( 'mapped.test', (string) $url );
	}

	public function test_the_cli_host_flag_is_parsed(): void {
		$this->assertSame(
			'mapped.test',
			BackgroundContext::from_cli_flag( array( 'wp', 'post', 'list', '--pd-host=mapped.test' ) )
		);
		$this->assertNull( BackgroundContext::from_cli_flag( array( 'wp', 'post', 'list' ) ) );
	}
}
