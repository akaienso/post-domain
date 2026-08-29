<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Routing\Disposition;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\Verifier;

final class AcceptanceTest extends OwnedSessionTestCase {

	use ServingContextFactory;

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();

		// Pretty permalinks: the plain-permalink default carries no trailing
		// slash, so user_trailingslashit() would have nothing to reflect.
		$this->set_permalink_structure( '/%postname%/' );

		Schema::install();
		Plugin::boot();
		$this->repo = new DbRepository();
	}

	private function matching_resolver(): DnsResolver {
		return new class() implements DnsResolver {
			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult( DnsOutcome::MATCH );
			}
		};
	}

	public function test_a_domain_goes_from_added_to_serving_and_back(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		// 1. Added: pending and inactive, so it does not serve.
		$mapping = $this->repo->save(
			new Mapping(
				0,
				'acceptance.test',
				null,
				$root,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'verification_state' => VerificationState::PENDING->value ),
			array( 'id' => $mapping->id )
		);

		// 2. Verified by a matching TXT record.
		( new Verifier( $this->repo, $this->matching_resolver(), new SystemClock() ) )
			->verify( $this->repo->by_id( $mapping->id ) );

		$this->assertSame(
			VerificationState::VERIFIED,
			$this->repo->by_id( $mapping->id )?->verification_state
		);

		// 3. Activated.
		$verified = $this->repo->by_id( $mapping->id );
		$this->repo->save(
			new Mapping(
				$verified->id,
				$verified->host,
				null,
				$verified->post_id,
				$verified->revision,
				$verified->verification_state,
				ActivationState::ACTIVE,
				$verified->ssl_state,
				null,
				$verified->challenge,
				$verified->challenge_label
			)
		);

		// 4. Serving: the subtree resolves and its links carry the mapped host.
		Plugin::instance()->context()->set_serving( $this->serving_context( $root, array( 'host' => 'acceptance.test' ) ) );

		// The resolver runs only for a routed request on a mapped host (spec §5.4),
		// so the host context is part of what "serving" means here.
		Plugin::instance()->context()->set_host(
			new HostContext( 'acceptance.test', null, 'acceptance.test', HostKind::MAPPED, null, EndpointClass::ROUTED, true, 'GET' )
		);
		Plugin::instance()->register_url_adapters();

		$wp             = new \WP();
		$wp->request    = 'events';
		$wp->query_vars = array();

		Plugin::instance()->resolve_request( $wp );

		$this->assertSame( $child, (int) $wp->query_vars['page_id'] );
		$this->assertSame( 'https://acceptance.test/events/', get_permalink( $child ) );

		// 5. Deactivated: it stops serving without being deleted.
		$active = $this->repo->by_id( $mapping->id );
		$this->repo->save(
			new Mapping(
				$active->id,
				$active->host,
				null,
				$active->post_id,
				$active->revision,
				$active->verification_state,
				ActivationState::INACTIVE,
				$active->ssl_state,
				null,
				$active->challenge,
				$active->challenge_label
			)
		);

		$this->assertSame(
			ActivationState::INACTIVE,
			$this->repo->by_id( $mapping->id )?->activation_state
		);
	}

	public function test_a_transient_resolver_failure_never_takes_a_live_domain_down(): void {
		$root = $this->make_page( 'club', 0 );

		$mapping = $this->repo->save(
			new Mapping(
				0,
				'resilient.test',
				null,
				$root,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'verification_state' => VerificationState::VERIFIED->value ),
			array( 'id' => $mapping->id )
		);

		$flaky = new class() implements DnsResolver {
			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult( DnsOutcome::TRANSIENT );
			}
		};

		for ( $i = 0; $i < 10; $i++ ) {
			( new Verifier( $this->repo, $flaky, new SystemClock() ) )
				->verify( $this->repo->by_id( $mapping->id ) );
		}

		$this->assertSame(
			VerificationState::VERIFIED,
			$this->repo->by_id( $mapping->id )?->verification_state,
			'ten transient failures in a row must not deactivate a live domain'
		);
	}

	public function test_the_five_dispositions_are_all_reachable(): void {
		$reached = array();

		foreach ( Disposition::cases() as $disposition ) {
			$reached[] = $disposition->value;
		}

		foreach (
			array( 'malformed_400', 'unknown_421', 'not_serving_404', 'broken_503', 'serve' ) as $expected
		) {
			$this->assertContains( $expected, $reached );
		}
	}

	public function test_uninstall_leaves_content_untouched(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Untouched' ) );

		$this->repo->save(
			new Mapping(
				0,
				'doomed.test',
				null,
				$post,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->assertSame( 'Untouched', get_post( $post )?->post_title );
	}
}
