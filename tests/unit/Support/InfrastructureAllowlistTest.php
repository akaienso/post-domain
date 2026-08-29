<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\InfrastructureAllowlist;

final class InfrastructureAllowlistTest extends TestCase {

	private function allows( array $entries, string $raw ): bool {
		$authority = ( new AuthorityParser() )->parse( $raw );
		$this->assertNotNull( $authority, 'the fixture authority must parse' );

		return ( new InfrastructureAllowlist( $entries ) )->allows( $authority );
	}

	public function test_an_exact_hostname_matches_case_insensitively(): void {
		$this->assertTrue( $this->allows( array( 'origin.example.com' ), 'ORIGIN.Example.com' ) );
	}

	public function test_localhost_matches(): void {
		$this->assertTrue( $this->allows( array( 'localhost' ), 'localhost' ) );
	}

	public function test_an_ipv4_literal_matches(): void {
		$this->assertTrue( $this->allows( array( '10.0.0.4' ), '10.0.0.4' ) );
	}

	public function test_a_bracketed_ipv6_literal_matches_in_bracketed_form(): void {
		$this->assertTrue( $this->allows( array( '[2001:db8::1]' ), '[2001:db8::1]' ) );
	}

	public function test_a_port_on_the_request_does_not_defeat_the_match(): void {
		$this->assertTrue( $this->allows( array( 'origin.example.com' ), 'origin.example.com:8443' ) );
	}

	public function test_a_different_host_does_not_match(): void {
		$this->assertFalse( $this->allows( array( 'origin.example.com' ), 'evil.example.com' ) );
	}

	public function test_no_suffix_matching(): void {
		$this->assertFalse( $this->allows( array( 'example.com' ), 'sub.example.com' ) );
	}

	public function test_wildcard_entries_are_dropped(): void {
		$list = new InfrastructureAllowlist( array( '*.example.com', 'origin.example.com' ) );

		$this->assertSame( array( 'origin.example.com' ), $list->entries() );
	}

	public function test_entries_carrying_a_port_are_dropped(): void {
		$list = new InfrastructureAllowlist( array( 'origin.example.com:8443', 'localhost' ) );

		$this->assertSame( array( 'localhost' ), $list->entries() );
	}

	public function test_unparseable_entries_are_dropped(): void {
		$list = new InfrastructureAllowlist( array( 'bad host', '[::1', 'localhost' ) );

		$this->assertSame( array( 'localhost' ), $list->entries() );
	}
}
