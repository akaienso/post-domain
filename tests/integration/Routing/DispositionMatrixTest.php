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
use PostDomain\Routing\Disposition;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\MappedHostGuard;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class DispositionMatrixTest extends WP_UnitTestCase {

	private DbRepository $repo;
	private AliasResolver $aliases;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->aliases = new AliasResolver( $this->repo );
		$this->post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_status' => 'publish' )
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_mapping_is_active' );
		parent::tear_down();
	}

	private function decide( HostContext $context ): Disposition {
		$eligibility = ServingEligibility::decide( $context, $this->aliases );
		$serving     = null === $eligibility || ! $eligibility->is_active
			? null
			: ContentPolicy::freeze( $eligibility, $this->aliases );

		return MappedHostGuard::decide( $context, $eligibility, $serving, '421' );
	}

	private function context( HostKind $kind, ?Mapping $mapping ): HostContext {
		return new HostContext(
			'x', null, $mapping?->host, $kind, $mapping, EndpointClass::ROUTED, true, 'GET'
		);
	}

	private function mapping( VerificationState $v, ActivationState $a, ?string $integrity = null, ?int $post = null ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped' . wp_rand( 1000, 9999 ) . '.test', null, $post ?? $this->post_id, 1,
				$v, $a, SslState::NONE, $integrity, str_repeat( wp_rand( 0, 9 ) . '', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_malformed_is_400(): void {
		$this->assertSame( Disposition::MALFORMED_400, $this->decide( $this->context( HostKind::MALFORMED, null ) ) );
	}

	public function test_unknown_is_421(): void {
		$this->assertSame( Disposition::UNKNOWN_421, $this->decide( $this->context( HostKind::UNKNOWN, null ) ) );
	}

	public function test_allowlisted_infrastructure_routes_as_primary(): void {
		$this->assertSame(
			Disposition::PRIMARY,
			$this->decide( $this->context( HostKind::ALLOWED_INFRASTRUCTURE, null ) )
		);
	}

	public function test_the_primary_host_routes_as_primary(): void {
		$this->assertSame( Disposition::PRIMARY, $this->decide( $this->context( HostKind::PRIMARY, null ) ) );
	}

	public function test_an_unverified_mapping_is_404(): void {
		$mapping = $this->mapping( VerificationState::UNVERIFIED, ActivationState::ACTIVE );

		$this->assertSame( Disposition::NOT_SERVING_404, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_an_inactive_mapping_is_404(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::INACTIVE );

		$this->assertSame( Disposition::NOT_SERVING_404, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_a_vetoed_mapping_is_404(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		add_filter( 'pd_mapping_is_active', '__return_false' );

		$this->assertSame( Disposition::NOT_SERVING_404, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_a_broken_content_policy_is_503(): void {
		$orphan  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, null, $orphan );
		wp_delete_post( $orphan, true );

		$this->assertSame( Disposition::BROKEN_503, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_an_integrity_error_is_503(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'corrupt.test', null, $this->post_id, 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				'challenge_label_invalid', str_repeat( 'z', 32 ), '_post-domain-challenge'
			)
		);

		$this->assertSame( Disposition::BROKEN_503, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_a_healthy_mapping_serves(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$this->assertSame( Disposition::SERVE, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}
}
