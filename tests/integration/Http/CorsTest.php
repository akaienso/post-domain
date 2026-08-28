<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Http;

use PostDomain\Http\Cors;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class CorsTest extends WP_UnitTestCase {

	private Cors $cors;

	public function set_up(): void {
		parent::set_up();
		Schema::install();

		$repo = new DbRepository();
		$repo->save(
			new Mapping(
				0,
				'served.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);
		$repo->save(
			new Mapping(
				0,
				'pending.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->cors = new Cors( $repo );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_cors_allowed_origin' );
		parent::tear_down();
	}

	public function test_a_verified_active_origin_is_authorized(): void {
		$this->assertSame(
			'https://served.test',
			$this->cors->allowed_origin( 'https://served.test', true )
		);
	}

	public function test_an_unverified_origin_is_not_authorized(): void {
		$this->assertNull( $this->cors->allowed_origin( 'https://pending.test', true ) );
	}

	public function test_an_unknown_origin_is_not_authorized(): void {
		$this->assertNull( $this->cors->allowed_origin( 'https://stranger.test', true ) );
	}

	/**
	 * @dataProvider malformed_origins
	 */
	public function test_malformed_origins_are_rejected( string $origin ): void {
		$this->assertNull( $this->cors->allowed_origin( $origin, true ) );
	}

	/** @return array<string, array{0: string}> */
	public static function malformed_origins(): array {
		return array(
			'literal null'   => array( 'null' ),
			'trailing slash' => array( 'https://served.test/' ),
			'with a path'    => array( 'https://served.test/x' ),
			'with a query'   => array( 'https://served.test?a=b' ),
			'with userinfo'  => array( 'https://user@served.test' ),
			'wrong scheme'   => array( 'ftp://served.test' ),
			'bare host'      => array( 'served.test' ),
			'wildcard'       => array( '*' ),
		);
	}

	public function test_an_http_origin_is_rejected_for_an_https_request(): void {
		$this->assertNull( $this->cors->allowed_origin( 'http://served.test', true ) );
	}

	public function test_a_filter_cannot_return_a_wildcard(): void {
		add_filter( 'pd_cors_allowed_origin', static fn(): string => '*' );

		$this->assertNull( $this->cors->allowed_origin( 'https://served.test', true ) );
	}

	public function test_a_filter_cannot_return_a_different_origin(): void {
		add_filter( 'pd_cors_allowed_origin', static fn(): string => 'https://evil.test' );

		$this->assertNull( $this->cors->allowed_origin( 'https://served.test', true ) );
	}

	public function test_a_filter_may_withhold_authorization(): void {
		add_filter( 'pd_cors_allowed_origin', static fn(): ?string => null );

		$this->assertNull( $this->cors->allowed_origin( 'https://served.test', true ) );
	}

	public function test_no_source_file_performs_an_outbound_diagnostic_fetch(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() || 'WpHttpClient.php' === $file->getFilename() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $source, 'wp_remote_get(' ) || str_contains( $source, 'file_get_contents( \'http' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame( array(), $offenders, 'the CORS probe runs in the browser, not on the server' );
	}
}
