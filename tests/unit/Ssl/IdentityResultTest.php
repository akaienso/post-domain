<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\ProviderMarker;

final class IdentityResultTest extends TestCase {

	/** @param array<string, mixed> $overrides */
	private function result( array $overrides = array() ): IdentityResult {
		return new IdentityResult(
			$overrides['verdict'] ?? IdentityVerdict::MATCH,
			array_key_exists( 'expected_ref', $overrides ) ? $overrides['expected_ref'] : 'ref-1',
			$overrides['observed_ref'] ?? 'ref-1',
			$overrides['observed_hostname'] ?? 'mapped.test',
			$overrides['marker'] ?? null,
			$overrides['marker_support'] ?? MarkerSupport::UNAVAILABLE,
			$overrides['read_complete'] ?? true,
			$overrides['transient'] ?? false
		);
	}

	public function test_a_complete_exact_match_is_usable(): void {
		$this->assertTrue( $this->result()->is_usable_for_mutation( 'mapped.test' ) );
	}

	/**
	 * @dataProvider unusable_shapes
	 * @param array<string, mixed> $overrides
	 */
	public function test_anything_less_than_an_exact_match_is_unusable( array $overrides ): void {
		$this->assertFalse( $this->result( $overrides )->is_usable_for_mutation( 'mapped.test' ) );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function unusable_shapes(): array {
		return array(
			'incomplete read'    => array( array( 'read_complete' => false ) ),
			'transient'          => array( array( 'transient' => true ) ),
			'reference mismatch' => array( array( 'observed_ref' => 'ref-2' ) ),
			'hostname mismatch'  => array( array( 'observed_hostname' => 'other.test' ) ),
			'unbound reference'  => array( array( 'expected_ref' => null ) ),
			'verdict mismatch'   => array( array( 'verdict' => IdentityVerdict::MISMATCH ) ),
			'verdict absent'     => array( array( 'verdict' => IdentityVerdict::ABSENT ) ),
			'verdict ambiguous'  => array( array( 'verdict' => IdentityVerdict::AMBIGUOUS ) ),
			'verdict unknown'    => array( array( 'verdict' => IdentityVerdict::UNKNOWN ) ),
			'verdict recover'    => array( array( 'verdict' => IdentityVerdict::RECOVERABLE_CREATE ) ),
		);
	}

	public function test_a_marker_names_an_installation_and_mapping(): void {
		$marker = new ProviderMarker( 'install-a', 12, array() );

		$this->assertTrue( $marker->names( 'install-a', 12 ) );
		$this->assertFalse( $marker->names( 'install-b', 12 ) );
		$this->assertFalse( $marker->names( 'install-a', 13 ) );
	}

	public function test_a_foreign_marker_conflicts_and_a_matching_one_does_not(): void {
		$this->assertTrue(
			$this->result( array( 'marker' => new ProviderMarker( 'other', 12, array() ) ) )
				->has_conflicting_marker( 'install-a', 12 )
		);
		$this->assertFalse(
			$this->result( array( 'marker' => new ProviderMarker( 'install-a', 12, array() ) ) )
				->has_conflicting_marker( 'install-a', 12 )
		);
	}

	public function test_an_absent_marker_never_conflicts(): void {
		$this->assertFalse(
			$this->result( array( 'marker' => null ) )->has_conflicting_marker( 'install-a', 12 ),
			'an absent marker establishes nothing either way'
		);
	}

	public function test_recoverable_create_requires_an_unbound_reference_and_a_naming_marker(): void {
		$valid = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE,
			null,
			'ref-9',
			'mapped.test',
			new ProviderMarker( 'install-a', 12, array() ),
			MarkerSupport::SUPPORTED,
			true,
			false
		);
		$this->assertTrue( $valid->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );

		$bound = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE,
			'ref-1',
			'ref-9',
			'mapped.test',
			new ProviderMarker( 'install-a', 12, array() ),
			MarkerSupport::SUPPORTED,
			true,
			false
		);
		$this->assertFalse( $bound->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );

		$unmarked = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE,
			null,
			'ref-9',
			'mapped.test',
			null,
			MarkerSupport::UNAVAILABLE,
			true,
			false
		);
		$this->assertFalse( $unmarked->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );
	}
}
