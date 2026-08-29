<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\CteSubtreeAdapter;
use PostDomain\Routing\DatabaseCapability;
use PostDomain\Routing\EnumerationScopeProvider;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class CteCapabilityTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_the_adapter_is_disabled_unless_explicitly_enabled(): void {
		$this->assertFalse(
			CteSubtreeAdapter::is_enabled(),
			'PD_ENABLE_CTE_SUBTREE is undefined until the capability matrix is evidenced'
		);
	}

	public function test_the_probe_reports_a_server_description(): void {
		$this->assertMatchesRegularExpression(
			'/\d+\.\d+/',
			DatabaseCapability::server_description(),
			'the probe must be able to name what it probed'
		);
	}

	public function test_the_probe_result_is_a_boolean_and_does_not_throw(): void {
		$this->assertIsBool( DatabaseCapability::supports_recursive_cte() );
	}

	public function test_the_evidence_document_exists_and_names_what_is_required(): void {
		$evidence = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/cte-capability-evidence.md' );

		$this->assertStringContainsString( 'SELECT VERSION()', $evidence );
		$this->assertStringContainsString( 'PD_ENABLE_CTE_SUBTREE', $evidence );
	}

	public function test_the_enumeration_fallback_produces_the_same_ids_as_the_adapter(): void {
		if ( ! DatabaseCapability::supports_recursive_cte() ) {
			$this->markTestSkipped( 'this database does not support recursive CTEs' );
		}

		$root = $this->make_page( 'club', 0 );
		$one  = $this->make_page( 'one', $root );
		$two  = $this->make_page( 'two', $one );

		$context    = $this->serving_context( $root );
		$subtree    = new Subtree( new PathNormalizer() );
		$enumerated = ( new EnumerationScopeProvider( $subtree, 500 ) )->scope( $context );
		$via_cte    = ( new CteSubtreeAdapter() )->scope( $context );

		$this->assertEqualsCanonicalizing( $enumerated->post__in, $via_cte->post__in );
		$this->assertEqualsCanonicalizing( array( $root, $one, $two ), $via_cte->post__in );
	}

	public function test_an_unsupported_database_yields_an_unbounded_scope_not_a_query(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );

		$adapter = new CteSubtreeAdapter( false );

		$this->assertFalse(
			$adapter->scope( $context )->is_bounded,
			'without capability the adapter must decline, never emit unbounded SQL'
		);
	}
}
