<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Url;

use PHPUnit\Framework\TestCase;
use PostDomain\Url\AbsoluteUrl;

final class AbsoluteUrlTest extends TestCase {

	private const PERMITTED = array( 'primary.test', 'mapped.test' );

	public function test_a_permitted_https_url_passes(): void {
		$this->assertSame(
			'https://mapped.test/x',
			AbsoluteUrl::validated( 'https://mapped.test/x', self::PERMITTED, true )
		);
	}

	public function test_a_foreign_host_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( 'https://evil.test/x', self::PERMITTED, true ) );
	}

	public function test_a_relative_url_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( '/x', self::PERMITTED, true ) );
	}

	public function test_a_non_http_scheme_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( 'javascript:alert(1)', self::PERMITTED, true ) );
		$this->assertNull( AbsoluteUrl::validated( 'ftp://mapped.test/x', self::PERMITTED, true ) );
	}

	public function test_userinfo_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( 'https://user@mapped.test/x', self::PERMITTED, true ) );
	}

	public function test_control_characters_are_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( "https://mapped.test/x\n", self::PERMITTED, true ) );
		$this->assertNull( AbsoluteUrl::validated( "https://mapped.test/\tx", self::PERMITTED, true ) );
	}

	public function test_an_https_request_may_not_yield_an_http_result(): void {
		$this->assertNull(
			AbsoluteUrl::validated( 'http://mapped.test/x', self::PERMITTED, true ),
			'no scheme downgrade'
		);
	}

	public function test_an_http_request_may_yield_either_scheme(): void {
		$this->assertSame(
			'http://mapped.test/x',
			AbsoluteUrl::validated( 'http://mapped.test/x', self::PERMITTED, false )
		);
		$this->assertSame(
			'https://mapped.test/x',
			AbsoluteUrl::validated( 'https://mapped.test/x', self::PERMITTED, false )
		);
	}
}
