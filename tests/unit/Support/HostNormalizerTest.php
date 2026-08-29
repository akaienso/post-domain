<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;

final class HostNormalizerTest extends TestCase {

	private function normalize( string $raw ): ?string {
		$authority = ( new AuthorityParser() )->parse( $raw );
		$this->assertNotNull( $authority, 'the fixture authority must parse' );

		return ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );
	}

	public function test_a_plain_host_lowercases(): void {
		$this->assertSame( 'example.com', $this->normalize( 'Example.COM' ) );
	}

	public function test_one_trailing_dot_is_stripped(): void {
		$this->assertSame( 'example.com', $this->normalize( 'example.com.' ) );
	}

	public function test_unicode_becomes_punycode(): void {
		$this->assertSame( 'xn--mnchen-3ya.example', $this->normalize( 'münchen.example' ) );
	}

	public function test_ip_literals_are_not_mappable_hosts(): void {
		$this->assertNull( $this->normalize( '10.0.0.4' ) );
		$this->assertNull( $this->normalize( '[2001:db8::1]' ) );
	}

	public function test_wildcard_labels_are_rejected(): void {
		$this->assertNull( $this->normalize( '*.example.com' ), 'wildcard mappings are out of scope' );
		$this->assertNull( $this->normalize( '*example.com' ) );
	}

	public function test_a_label_over_63_bytes_is_rejected(): void {
		$this->assertNull( $this->normalize( str_repeat( 'a', 64 ) . '.example' ) );
	}

	public function test_a_host_over_253_bytes_is_rejected(): void {
		$long = implode( '.', array_fill( 0, 10, str_repeat( 'a', 25 ) ) ) . '.example';

		$this->assertGreaterThan( 253, strlen( $long ) );
		$this->assertNull( $this->normalize( $long ) );
	}

	public function test_a_leading_hyphen_label_is_rejected(): void {
		$this->assertNull( $this->normalize( '-bad.example' ) );
	}

	public function test_a_trailing_hyphen_label_is_rejected(): void {
		$this->assertNull( $this->normalize( 'bad-.example' ) );
	}
}
