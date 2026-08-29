<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Routing\ServingContext;
use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;
use WP_UnitTestCase;

/**
 * `pd_rebase_url` hands a filter complete control of a link's absolute form, so
 * what comes back is untrusted input to the mapped-host contract, not a result.
 * Anything the contract would refuse falls back to the original URL.
 */
final class RebaseFilterTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private const ORIGINAL = 'https://primary.test/events/';

	private UrlPolicy $policy;

	private ServingContext $context;

	public function set_up(): void {
		parent::set_up();
		$this->policy  = new UrlPolicy( 'https://primary.test' );
		$this->context = $this->serving_context( $this->make_page( 'club', 0 ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_rebase_url' );
		parent::tear_down();
	}

	private function supplying( string $url ): string {
		add_filter( 'pd_rebase_url', static fn (): string => $url );

		return $this->policy->rebase( self::ORIGINAL, $this->context, UrlKind::PERMALINK );
	}

	public function test_an_https_mapped_host_url_is_accepted(): void {
		$this->assertSame(
			'https://mapped.test/somewhere/',
			$this->supplying( 'https://mapped.test/somewhere/' )
		);
	}

	/** @dataProvider refused */
	public function test_a_refused_filter_result_falls_back_to_the_original( string $supplied, string $why ): void {
		$this->assertSame( self::ORIGINAL, $this->supplying( $supplied ), $why );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function refused(): array {
		return array(
			'http downgrade'    => array( 'http://mapped.test/somewhere/', 'a mapped host is addressed over HTTPS only' ),
			'userinfo'          => array( 'https://user:pass@mapped.test/x', 'credentials in a link are never the plugin\'s to emit' ),
			'control character' => array( "https://mapped.test/x\r\nSet-Cookie: a=b", 'a control character can split a header' ),
			'foreign host'      => array( 'https://evil.test/somewhere/', 'only the requested or canonical mapped host' ),
			'relative url'      => array( '/somewhere/', 'a rebased link must be absolute' ),
			'arbitrary port'    => array( 'https://mapped.test:8443/somewhere/', 'the mapped-host contract has no port to offer' ),
		);
	}
}
