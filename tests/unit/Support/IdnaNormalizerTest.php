<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\IdnaNormalizer;

final class IdnaNormalizerTest extends TestCase {

	/**
	 * @dataProvider uts46_vectors
	 */
	public function test_uts46_conformance_vectors( string $input, string $expected ): void {
		$this->assertSame( $expected, ( new IdnaNormalizer() )->to_ascii( $input ) );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function uts46_vectors(): array {
		$lines   = (array) file( __DIR__ . '/../fixtures/uts46.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$vectors = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			[ $input, $expected ] = explode( ';', $line, 2 );
			$vectors[]            = array( $input, $expected );
		}

		return $vectors;
	}

	public function test_unicode_and_punycode_input_converge_on_one_ascii_host(): void {
		$normalizer = new IdnaNormalizer();

		$this->assertSame(
			$normalizer->to_ascii( 'münchen.example' ),
			$normalizer->to_ascii( 'xn--mnchen-3ya.example' ),
			'the same domain typed two ways must produce one row key'
		);
	}

	public function test_invalid_punycode_is_rejected(): void {
		$this->assertNull( ( new IdnaNormalizer() )->to_ascii( 'xn--.example' ) );
	}

	public function test_display_form_returns_unicode(): void {
		$this->assertSame(
			'münchen.example',
			( new IdnaNormalizer() )->to_display( 'xn--mnchen-3ya.example' )
		);
	}

	public function test_the_global_idn_functions_are_never_called(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../src/Support/IdnaNormalizer.php' );

		// The invariant is that no UNQUALIFIED call exists: `Idn::idn_to_ascii(`
		// necessarily contains the substring `idn_to_ascii(`, so a plain
		// assertStringNotContainsString could never pass alongside the third
		// assertion below. Match a call not preceded by the class qualifier.
		$this->assertSame(
			0,
			preg_match_all( '/(?<!Idn::)\bidn_to_ascii\(/', $source ),
			'the global idn_to_ascii() must never be called'
		);
		$this->assertSame(
			0,
			preg_match_all( '/(?<!Idn::)\bidn_to_utf8\(/', $source ),
			'the global idn_to_utf8() must never be called'
		);
		$this->assertStringContainsString( 'Idn::idn_to_ascii', $source );
	}
}
