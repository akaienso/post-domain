<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\HostingReconciler;
use PostDomain\Hosting\WordifyHostingProvider;
use ReflectionMethod;

/**
 * `recheck` performs live DNS queries and can trigger a Let's Encrypt issuance
 * request, which is rate limited per registered domain per week. Nothing on a
 * registration or identification path may reach it.
 *
 * The behavioural tests assert it is not *called*; these assert it cannot be,
 * by reading the source of the methods themselves. A lexical check is the right
 * instrument here: the rule is about what the code may contain, not only about
 * what one scripted run happened to do.
 */
final class RecheckIsolationTest extends TestCase {

	private function body_of( string $fqcn, string $method ): string {
		$reflection = new ReflectionMethod( $fqcn, $method );
		$file       = (string) $reflection->getFileName();
		$lines      = (array) file( $file );
		$start      = (int) $reflection->getStartLine() - 1;
		$length     = (int) $reflection->getEndLine() - $start;

		return implode( '', array_slice( $lines, $start, $length ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function registration_paths(): array {
		return array(
			'register'           => array( WordifyHostingProvider::class, 'register' ),
			'identify'           => array( WordifyHostingProvider::class, 'identify' ),
			'resolve_by_reading' => array( WordifyHostingProvider::class, 'resolve_by_reading' ),
			'owning_site'        => array( WordifyHostingProvider::class, 'owning_site' ),
			'reconciler resolve' => array( HostingReconciler::class, 'resolve' ),
		);
	}

	/**
	 * @dataProvider registration_paths
	 */
	public function test_no_registration_path_mentions_recheck( string $fqcn, string $method ): void {
		$this->assertStringNotContainsString( 'recheck', $this->body_of( $fqcn, $method ) );
	}

	public function test_recheck_is_reachable_only_through_an_explicit_operator_method(): void {
		$source  = (string) file_get_contents( __DIR__ . '/../../../src/Hosting/WordifyHostingProvider.php' );
		$callers = preg_match_all( '/->recheck\(/', $source );

		$this->assertSame( 1, $callers, 'Exactly one call site, inside recheck_dns().' );
		$this->assertStringContainsString( 'recheck', $this->body_of( WordifyHostingProvider::class, 'recheck_dns' ) );
	}

	public function test_recheck_is_not_part_of_the_hosting_provider_interface(): void {
		$methods = array_map(
			static fn ( ReflectionMethod $method ): string => $method->getName(),
			( new \ReflectionClass( \PostDomain\Contracts\HostingProvider::class ) )->getMethods()
		);

		$this->assertNotContains( 'recheck', $methods );
		$this->assertNotContains( 'recheck_dns', $methods );
	}

	public function test_the_provider_exposes_no_detach_operation_of_its_own(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../src/Hosting/WordifyApiClient.php' );

		// No verified surface exposes domain detachment, so the client has no
		// method that could perform one.
		$this->assertStringNotContainsString( 'function detach', $source );
		$this->assertStringNotContainsString( "'DELETE'", $source );
	}
}
