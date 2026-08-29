<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class EnvironmentTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		$this->repo = new DbRepository();
	}

	private function owned(): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'cloudflare-saas',
				'cf-zone:zone-1',
				'ref-1'
			)
		);
	}

	public function test_the_installation_id_is_generated_once_and_persists(): void {
		$first = Environment::installation_id();

		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $first );
		$this->assertSame( $first, Environment::installation_id() );
	}

	public function test_no_mismatch_on_a_stable_host(): void {
		Environment::installation_id();
		Environment::remember_primary_host();

		$this->assertNull( Environment::check() );
		$this->assertFalse( Environment::is_blocked() );
	}

	public function test_a_changed_primary_host_blocks(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );

		$mismatch = Environment::check();

		$this->assertSame( 'old-host.test', $mismatch['stored'] );
		$this->assertTrue( Environment::is_blocked() );
	}

	public function test_restore_keeps_identity_ownership_and_challenges(): void {
		$m  = $this->owned();
		$id = Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_restore();

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( $id, Environment::installation_id() );
		$this->assertFalse( Environment::is_blocked() );
		$this->assertSame( OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( $m->challenge, $after?->challenge );
	}

	public function test_clone_replaces_identity_clears_ownership_and_rotates_challenges(): void {
		$m  = $this->owned();
		$id = Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_clone();

		$after = $this->repo->by_id( $m->id );

		$this->assertNotSame( $id, Environment::installation_id() );
		// Every field of the durable binding, not merely most of them.
		$this->assertNull( $after?->ssl_provider );
		$this->assertNull( $after?->ssl_provider_environment );
		$this->assertNull( $after?->ssl_ref );
		$this->assertNull( $after?->ssl_ownership_origin );
		$this->assertNull( $after?->ssl_owner_installation_id );
		$this->assertNull( $after?->ssl_adopted_at, 'a clone owns nothing, anywhere' );
		$this->assertSame( SslState::NONE, $after?->ssl_state );
		$this->assertNotSame( $m->challenge, $after?->challenge );
		$this->assertSame( VerificationState::UNVERIFIED, $after?->verification_state );
	}

	public function test_a_clone_holds_no_ownership_authority(): void {
		$m = $this->owned();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();
		Environment::resolve_as_clone();

		$after   = $this->repo->by_id( $m->id );
		$context = SslResourceContext::from_mapping(
			$after,
			Environment::installation_id(),
			'_post-domain-challenge.' . $after->host,
			'cloudflare-saas'
		);

		$this->assertFalse( $context->has_ownership_authority() );
	}
}
