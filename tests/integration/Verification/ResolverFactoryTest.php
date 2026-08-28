<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DohResolver;
use PostDomain\Verification\ResolverFactory;
use WP_UnitTestCase;

/**
 * `pd_doh_endpoints` must not be able to weaken the two-endpoint rule.
 *
 * No DNS is resolved here: every case is decided before a request would be made,
 * and the one case that would reach the network is not exercised.
 */
final class ResolverFactoryTest extends WP_UnitTestCase {

	/** @var callable|null */
	private $filter;

	public function tear_down(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'pd_doh_endpoints', $this->filter );
			$this->filter = null;
		}

		parent::tear_down();
	}

	/** @param mixed $value */
	private function filter_endpoints( $value ): void {
		$this->filter = static fn(): mixed => $value;

		add_filter( 'pd_doh_endpoints', $this->filter );
	}

	public function test_the_default_is_doh_with_two_distinct_endpoints(): void {
		$this->assertInstanceOf( DohResolver::class, ResolverFactory::from_filters() );
	}

	public function test_a_single_filtered_endpoint_cannot_produce_a_hard_outcome(): void {
		$this->filter_endpoints( array( 'https://cloudflare-dns.com/dns-query' ) );

		$result = ResolverFactory::from_filters()->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
		$this->assertStringContainsString( 'two distinct https endpoints', (string) $result->error );
	}

	public function test_a_filtered_duplicate_pair_cannot_produce_a_hard_outcome(): void {
		$this->filter_endpoints(
			array( 'https://cloudflare-dns.com/dns-query', 'https://CLOUDFLARE-DNS.com:443/dns-query/' )
		);

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			ResolverFactory::from_filters()->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_an_emptied_filter_cannot_produce_a_hard_outcome(): void {
		$this->filter_endpoints( array() );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			ResolverFactory::from_filters()->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_junk_in_the_filtered_list_is_not_counted_as_an_endpoint(): void {
		$this->filter_endpoints( array( 'https://one.example/dns-query', 42, null, 'not a url' ) );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			ResolverFactory::from_filters()->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}
}
