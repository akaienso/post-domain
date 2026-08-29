<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\HostContextFactory;
use PostDomain\Routing\HostKind;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\Schema;
use PostDomain\Support\TrustedProxy;
use WP_UnitTestCase;

final class HostContextFactoryTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
	}

	private function host_factory( array $allowlist = array() ): HostContextFactory {
		return new HostContextFactory(
			new TrustedProxy( array() ),
			new AuthorityParser(),
			new InfrastructureAllowlist( $allowlist ),
			new HostNormalizer( new IdnaNormalizer() ),
			new Classifier( 'wp-json' ),
			$this->repo,
			'primary.test'
		);
	}

	private function build( string $host, array $allowlist = array(), string $path = '/' ): \PostDomain\Routing\HostContext {
		return $this->host_factory( $allowlist )->build(
			array(
				'HTTP_HOST'      => $host,
				'REQUEST_URI'    => $path,
				'REQUEST_METHOD' => 'GET',
			),
			array()
		);
	}

	private function seed_mapping( string $host ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_the_primary_host_is_primary(): void {
		$this->assertSame( HostKind::PRIMARY, $this->build( 'primary.test' )->kind );
	}

	public function test_a_mapped_host_is_mapped_whatever_its_state(): void {
		$this->seed_mapping( 'mapped.test' );
		$context = $this->build( 'mapped.test' );

		$this->assertSame( HostKind::MAPPED, $context->kind );
		$this->assertTrue( $context->has_row() );
		$this->assertFalse( $context->may_serve(), 'unverified and inactive must not serve' );
	}

	public function test_an_unknown_host_is_unknown(): void {
		$this->assertSame( HostKind::UNKNOWN, $this->build( 'stranger.test' )->kind );
	}

	public function test_a_malformed_authority_is_malformed(): void {
		$this->assertSame( HostKind::MALFORMED, $this->build( 'bad host:' )->kind );
	}

	public function test_an_allowlisted_host_is_infrastructure(): void {
		$this->assertSame(
			HostKind::ALLOWED_INFRASTRUCTURE,
			$this->build( 'health.internal', array( 'health.internal' ) )->kind
		);
	}

	public function test_an_allowlisted_ip_literal_is_infrastructure(): void {
		$this->assertSame(
			HostKind::ALLOWED_INFRASTRUCTURE,
			$this->build( '10.0.0.4', array( '10.0.0.4' ) )->kind
		);
	}

	public function test_a_malformed_near_match_of_an_allowlisted_host_is_still_malformed(): void {
		$this->assertSame(
			HostKind::MALFORMED,
			$this->build( 'health.internal:', array( 'health.internal' ) )->kind,
			'a malformed authority must never be reshaped into an allowlisted host'
		);
	}

	public function test_a_unicode_host_matches_its_punycode_row(): void {
		$this->seed_mapping( 'xn--mnchen-3ya.example' );
		$context = $this->build( 'münchen.example' );

		$this->assertSame( HostKind::MAPPED, $context->kind );
		$this->assertSame( 'xn--mnchen-3ya.example', $context->ascii_host );
	}

	public function test_the_endpoint_class_is_carried(): void {
		$this->assertSame(
			\PostDomain\Routing\EndpointClass::ADMIN,
			$this->build( 'primary.test', array(), '/wp-admin/edit.php' )->endpoint
		);
	}
}
