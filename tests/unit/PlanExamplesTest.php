<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlanExamplesTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @param string[] $args
	 * @return array{code: int, output: string}
	 */
	private function run_check( array $args = array() ): array {
		$command = 'php ' . escapeshellarg( $this->root() . '/bin/check-plan-examples.php' );

		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$output = (string) shell_exec( $command . ' 2>&1; echo "EXIT:$?"' );

		preg_match( '/EXIT:(\d+)\s*$/', $output, $m );

		return array(
			'code'   => (int) ( $m[1] ?? 1 ),
			'output' => $output,
		);
	}

	private function fixture( string $name ): array {
		return $this->run_check( array( '--only=' . $this->root() . '/tests/fixtures/plan-examples/' . $name . '.md' ) );
	}

	public function test_every_prescribed_example_parses_and_resolves(): void {
		$result = $this->run_check();

		$this->assertSame( 0, $result['code'], $result['output'] );
	}

	public function test_the_report_states_what_it_inspected(): void {
		$result = $this->run_check();

		$this->assertMatchesRegularExpression( '/complete examples inspected: \d+/', $result['output'] );
		$this->assertMatchesRegularExpression( '/fragments NOT inspected: \d+/', $result['output'] );
		$this->assertMatchesRegularExpression( '/types declared: \d+/', $result['output'] );
	}

	public function test_every_skipped_fragment_is_listed_not_just_counted(): void {
		$result = $this->run_check();

		preg_match( '/fragments NOT inspected: (\d+)/', $result['output'], $m );

		$listed = preg_match_all( '/^ {2}skipped /m', $result['output'] );

		$this->assertSame(
			(int) $m[1],
			$listed,
			'a count alone cannot tell an operator which examples went unchecked'
		);
	}

	/**
	 * @dataProvider defect_fixtures
	 */
	public function test_each_defect_class_fails_the_check( string $fixture, string $marker ): void {
		$result = $this->fixture( $fixture );

		$this->assertSame( 1, $result['code'], $result['output'] );
		$this->assertStringContainsString( $marker, $result['output'] );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function defect_fixtures(): array {
		return array(
			'syntax error'       => array( 'syntax', '[syntax]' ),
			'unresolved import'  => array( 'unresolved-import', '[unresolved-import]' ),
			'missing import'     => array( 'missing-import', '[missing-import]' ),
			'duplicate import'   => array( 'duplicate-import', '[duplicate-import]' ),
			'duplicate type'     => array( 'duplicate-declaration', '[duplicate-declaration]' ),
			'wrong arity'        => array( 'arity', '[arity]' ),
			'uncovered fragment' => array( 'uncovered-fragment', '[uncovered-critical-fragment]' ),
		);
	}

	public function test_the_missing_import_fixture_reproduces_the_defect_that_reached_review(): void {
		$result = $this->fixture( 'missing-import' );

		// The same shape as Reconciler calling AtomicTransition::commit() with no
		// import for it: a known short name, used from another namespace, unimported.
		$this->assertStringContainsString( 'AtomicTransition', $result['output'] );
	}

	public function test_a_replacement_block_is_not_a_duplicate_declaration(): void {
		$result = $this->fixture( 'replacement' );

		$this->assertSame( 0, $result['code'], $result['output'] );
	}

	public function test_a_critical_fragment_without_a_coverage_marker_fails(): void {
		$result = $this->fixture( 'uncovered-fragment' );

		$this->assertSame( 1, $result['code'], $result['output'] );
		$this->assertStringContainsString( '[uncovered-critical-fragment]', $result['output'] );
	}

	public function test_a_critical_fragment_with_a_coverage_marker_passes(): void {
		$result = $this->fixture( 'covered-fragment' );

		$this->assertSame( 0, $result['code'], $result['output'] );
		$this->assertStringContainsString( '[covered]', $result['output'] );
	}

	public function test_every_critical_skipped_fragment_in_the_suite_names_its_test(): void {
		// The real suite, not a fixture: this is the assertion that keeps the
		// coverage claims honest as the plans change.
		$this->assertSame( 0, $this->run_check()['code'] );
	}
}
