<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BuildConfigTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_scoper_prefixes_into_the_plugin_namespace(): void {
		$config = (string) file_get_contents( $this->root() . '/scoper.inc.php' );

		// The invariant is the prefix value, not its whitespace: WPCS aligns the
		// double arrows in this array, so an exact-spacing match would break the
		// moment a longer key is added.
		$this->assertMatchesRegularExpression(
			"/'prefix'\s*=>\s*'PostDomain\\\\\\\\Vendor'/",
			$config
		);
	}

	public function test_the_idn_polyfill_is_pinned_exactly(): void {
		/** @var array{require: array<string, string>} $composer */
		$composer = json_decode( (string) file_get_contents( $this->root() . '/composer.json' ), true );

		$this->assertSame( '1.38.1', $composer['require']['symfony/polyfill-intl-idn'] );
	}

	public function test_the_composer_lock_is_committed(): void {
		$this->assertFileExists( $this->root() . '/composer.lock' );
	}

	public function test_phpstan_runs_at_level_8(): void {
		$config = (string) file_get_contents( $this->root() . '/phpstan.neon.dist' );

		$this->assertStringContainsString( 'level: 8', $config );
	}

	public function test_ci_runs_lint_analyse_and_both_test_suites(): void {
		$workflow = (string) file_get_contents( $this->root() . '/.github/workflows/ci.yml' );

		$this->assertStringContainsString( 'composer lint', $workflow );
		$this->assertStringContainsString( 'composer analyse', $workflow );
		$this->assertStringContainsString( 'composer test', $workflow );
		$this->assertStringContainsString( 'composer audit', $workflow );
	}
}
