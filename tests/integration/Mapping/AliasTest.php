<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Mapping;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasInUse;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class AliasTest extends WP_UnitTestCase {

	private DbRepository $repo;
	private AliasResolver $aliases;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->aliases = new AliasResolver( $this->repo );
	}

	private function make( string $host, ?int $alias_of, ?int $post_id, string $challenge ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				$host,
				$alias_of,
				$post_id,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( $challenge, 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_an_alias_derives_its_target_from_the_canonical_row(): void {
		$canonical = $this->make( 'example.test', null, 42, 'a' );
		$alias     = $this->make( 'www.example.test', $canonical->id, null, 'b' );

		$this->assertSame( 42, $this->aliases->effective_post_id( $alias ) );
		$this->assertSame( 'example.test', $this->aliases->canonical_host( $alias ) );
	}

	public function test_a_canonical_row_is_its_own_canonical(): void {
		$canonical = $this->make( 'example.test', null, 42, 'c' );

		$this->assertSame( $canonical->id, $this->aliases->canonical_for( $canonical )?->id );
		$this->assertSame( 'example.test', $this->aliases->canonical_host( $canonical ) );
	}

	public function test_aliases_carry_their_own_challenge(): void {
		$canonical = $this->make( 'example.test', null, 42, 'd' );
		$alias     = $this->make( 'www.example.test', $canonical->id, null, 'e' );

		$this->assertNotSame(
			$canonical->challenge,
			$alias->challenge,
			'ownership proof is per host and cannot be inherited'
		);
	}

	public function test_aliases_of_lists_the_children(): void {
		$canonical = $this->make( 'example.test', null, 42, 'f' );
		$this->make( 'www.example.test', $canonical->id, null, 'g' );
		$this->make( 'shop.example.test', $canonical->id, null, 'h' );

		$this->assertCount( 2, $this->aliases->aliases_of( $canonical->id ) );
	}

	public function test_deleting_a_canonical_row_with_aliases_is_refused(): void {
		$canonical = $this->make( 'example.test', null, 42, 'i' );
		$this->make( 'www.example.test', $canonical->id, null, 'j' );

		$this->expectException( AliasInUse::class );
		$this->repo->delete( $canonical->id );
	}

	public function test_deleting_an_alias_then_its_canonical_succeeds(): void {
		$canonical = $this->make( 'example.test', null, 42, 'k' );
		$alias     = $this->make( 'www.example.test', $canonical->id, null, 'l' );

		$this->repo->delete( $alias->id );
		$this->repo->delete( $canonical->id );

		$this->assertNull( $this->repo->by_id( $canonical->id ) );
	}
}
