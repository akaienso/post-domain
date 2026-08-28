<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Verification\CronWiring;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Contracts\DnsResolver;

/**
 * The on-demand probe behind POST /domains/{id}/verify. The REST request
 * schedules it rather than running it, so a hook with no listener would make
 * the whole route a no-op (spec §15.2).
 */
final class VerifyNowTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();

		add_filter(
			'pd_dns_resolver',
			static fn (): DnsResolver => new class() implements DnsResolver {
				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( DnsOutcome::MATCH );
				}
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_dns_resolver' );
		parent::tear_down();
	}

	private function pending(): Mapping {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0,
				'ondemand.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array( 'verification_state' => VerificationState::PENDING->value ),
			array( 'id' => $m->id )
		);

		return $this->repo->by_id( $m->id );
	}

	public function test_the_hook_has_a_listener(): void {
		CronWiring::register();

		$this->assertNotFalse( has_action( 'pd_verify_now' ), 'an unhandled hook makes the route a no-op' );
	}

	public function test_a_matching_record_verifies_the_named_mapping(): void {
		$m = $this->pending();

		CronWiring::verify_one( $m->id );

		$this->assertSame( VerificationState::VERIFIED, $this->repo->by_id( $m->id )?->verification_state );
	}

	public function test_a_mapping_that_no_longer_exists_is_not_an_error(): void {
		CronWiring::verify_one( 987654 );

		$this->assertNull( $this->repo->by_id( 987654 ) );
	}
}
