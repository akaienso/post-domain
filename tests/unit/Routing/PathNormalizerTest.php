<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PostDomain\Routing\PathNormalizer;

final class PathNormalizerTest extends TestCase {

	private function segments( string $path ): ?array {
		return ( new PathNormalizer() )->segments( $path );
	}

	public function test_a_simple_path_splits(): void {
		$this->assertSame( array( 'events', 'gala' ), $this->segments( 'events/gala' ) );
	}

	public function test_leading_and_trailing_slashes_are_stripped(): void {
		$this->assertSame( array( 'events', 'gala' ), $this->segments( '/events/gala/' ) );
	}

	public function test_repeated_slashes_collapse(): void {
		$this->assertSame( array( 'events', 'gala' ), $this->segments( 'events///gala' ) );
	}

	public function test_percent_encoded_segments_decode(): void {
		$this->assertSame( array( 'café' ), $this->segments( 'caf%C3%A9' ) );
	}

	public function test_an_encoded_slash_is_rejected(): void {
		$this->assertNull( $this->segments( 'events%2Fgala' ), 'an encoded separator is never a separator' );
	}

	public function test_an_encoded_backslash_is_rejected(): void {
		$this->assertNull( $this->segments( 'events%5Cgala' ) );
	}

	public function test_dot_segments_are_rejected(): void {
		$this->assertNull( $this->segments( 'events/./gala' ) );
		$this->assertNull( $this->segments( 'events/../gala' ) );
	}

	public function test_the_root_is_an_empty_segment_list(): void {
		$this->assertSame( array(), $this->segments( '' ) );
		$this->assertSame( array(), $this->segments( '/' ) );
	}

	public function test_trailing_empty_segments_collapse(): void {
		$this->assertSame( array( 'events' ), $this->segments( 'events//' ) );
	}
}
