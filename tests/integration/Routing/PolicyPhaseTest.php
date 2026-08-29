<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class PolicyPhaseTest extends WP_UnitTestCase {

	private DbRepository $repo;
	private AliasResolver $aliases;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->aliases = new AliasResolver( $this->repo );
		$this->post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_mapping_is_active' );
		remove_all_filters( 'pd_target_post_for_host' );
		remove_all_filters( 'pd_subtree_post_types' );
		parent::tear_down();
	}

	private function servable( string $host = 'mapped.test' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				$this->post_id,
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);
	}

	private function context( Mapping $m ): HostContext {
		return new HostContext(
			$m->host,
			null,
			$m->host,
			HostKind::MAPPED,
			$m,
			EndpointClass::ROUTED,
			true,
			'GET'
		);
	}

	public function test_a_verified_active_mapping_is_eligible(): void {
		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNotNull( $eligibility );
		$this->assertTrue( $eligibility->is_active );
		$this->assertSame( 'mapped.test', $eligibility->canonical_host );
	}

	public function test_the_active_filter_can_veto(): void {
		add_filter( 'pd_mapping_is_active', '__return_false' );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNotNull( $eligibility );
		$this->assertFalse( $eligibility->is_active );
	}

	public function test_the_active_filter_cannot_grant(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0,
				'pending.test',
				null,
				$this->post_id,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
			)
		);

		add_filter( 'pd_mapping_is_active', '__return_true' );

		$eligibility = ServingEligibility::decide( $this->context( $mapping ), $this->aliases );

		$this->assertNotNull( $eligibility );
		$this->assertFalse( $eligibility->is_active, 'stored state ANDs with the filter' );
	}

	public function test_an_alias_reports_its_canonical_host(): void {
		$canonical = $this->servable( 'canonical.test' );
		$alias     = $this->repo->save(
			new Mapping(
				0,
				'alias.test',
				$canonical->id,
				null,
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);

		$eligibility = ServingEligibility::decide( $this->context( $alias ), $this->aliases );

		$this->assertSame( 'alias.test', $eligibility?->requested_host );
		$this->assertSame( 'canonical.test', $eligibility?->canonical_host );
	}

	public function test_content_policy_freezes_the_effective_target(): void {
		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );
		$serving     = ContentPolicy::freeze( $eligibility, $this->aliases );

		$this->assertNotNull( $serving );
		$this->assertSame( $this->post_id, $serving->effective_post_id );
		$this->assertSame( array( 'page' ), $serving->subtree_post_types );
		$this->assertSame( array( 'publish' ), $serving->post_statuses );
		$this->assertSame( 10, $serving->max_depth );
	}

	public function test_the_target_filter_is_validated_against_the_allowed_types(): void {
		$other = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		add_filter( 'pd_target_post_for_host', static fn(): int => $other );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNull(
			ContentPolicy::freeze( $eligibility, $this->aliases ),
			'a target outside the allowed post types is invalid, and invalid means 503'
		);
	}

	public function test_a_trashed_target_is_invalid(): void {
		wp_trash_post( $this->post_id );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNull( ContentPolicy::freeze( $eligibility, $this->aliases ) );
	}

	public function test_max_depth_is_clamped(): void {
		add_filter( 'pd_max_subtree_depth', static fn(): int => 900 );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertSame( 25, ContentPolicy::freeze( $eligibility, $this->aliases )?->max_depth );
	}
}
