<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\AuthorityParser;

final class AuthorityParserTest extends TestCase {

	/**
	 * @dataProvider malformed_authorities
	 */
	public function test_malformed_authorities_are_rejected( string $raw, string $why ): void {
		$this->assertNull( ( new AuthorityParser() )->parse( $raw ), $why );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function malformed_authorities(): array {
		return array(
			'empty port'          => array( 'example.com:', 'a colon with no port is malformed' ),
			'zero port'           => array( 'example.com:0', 'port 0 is out of range' ),
			'port out of range'   => array( 'example.com:99999', 'ports stop at 65535' ),
			'non numeric port'    => array( 'example.com:abc', 'ports are decimal only' ),
			'hex port'            => array( 'example.com:0x50', 'ports are decimal only' ),
			'signed port'         => array( 'example.com:+80', 'ports carry no sign' ),
			'unclosed bracket'    => array( '[::1', 'an unbalanced bracket is malformed' ),
			'bare ipv6 with port' => array( '::1:80', 'unbracketed ipv6 with a port is ambiguous' ),
			'internal space'      => array( 'ex ample.com', 'internal whitespace is malformed' ),
			'internal tab'        => array( "ex\tample.com", 'internal whitespace is malformed' ),
			'userinfo'            => array( 'user@example.com', 'userinfo has no place in a Host header' ),
			'path'                => array( 'example.com/wp-admin', 'a path is not part of the authority' ),
			'backslash'           => array( 'example.com\\evil', 'a backslash is a path separator' ),
			'query'               => array( 'example.com?a=b', 'a query is not part of the authority' ),
			'fragment'            => array( 'example.com#x', 'a fragment is not part of the authority' ),
			'null byte'           => array( "example.com\0", 'NUL is a control character' ),
			'control character'   => array( "example.com\x01", 'control characters are malformed' ),
		);
	}

	public function test_a_plain_host_parses_with_identity_unchanged(): void {
		$authority = ( new AuthorityParser() )->parse( 'Example.COM' );

		$this->assertNotNull( $authority );
		$this->assertSame( 'Example.COM', $authority->host, 'parsing must not alter identity' );
		$this->assertNull( $authority->port );
		$this->assertFalse( $authority->is_ipv6_literal );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		$authority = ( new AuthorityParser() )->parse( "  example.com\t" );

		$this->assertNotNull( $authority );
		$this->assertSame( 'example.com', $authority->host );
	}

	public function test_a_valid_port_is_extracted(): void {
		$authority = ( new AuthorityParser() )->parse( 'example.com:8443' );

		$this->assertNotNull( $authority );
		$this->assertSame( 'example.com', $authority->host );
		$this->assertSame( 8443, $authority->port );
	}

	public function test_a_bracketed_ipv6_literal_parses(): void {
		$authority = ( new AuthorityParser() )->parse( '[2001:db8::1]:443' );

		$this->assertNotNull( $authority );
		$this->assertTrue( $authority->is_ipv6_literal );
		$this->assertSame( '2001:db8::1', $authority->host );
		$this->assertSame( '[2001:db8::1]', $authority->bracketed_form );
		$this->assertSame( 443, $authority->port );
	}

	public function test_an_invalid_bracketed_literal_is_rejected(): void {
		$this->assertNull( ( new AuthorityParser() )->parse( '[not:an:address:zz]' ) );
	}
}
