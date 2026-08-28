<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Mapping;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\InvalidMapping;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\StaleRevision;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class RepositoryWriteTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
	}

	private function canonical( string $host, int $post_id = 42 ): Mapping {
		return new Mapping(
			0,
			$host,
			null,
			$post_id,
			1,
			VerificationState::UNVERIFIED,
			ActivationState::INACTIVE,
			SslState::NONE,
			null,
			str_repeat( 'a', 32 ),
			'_post-domain-challenge'
		);
	}

	public function test_a_canonical_row_saves_and_reads_back(): void {
		$saved = $this->repo->save( $this->canonical( 'example.test' ) );

		$this->assertGreaterThan( 0, $saved->id );
		$this->assertSame( 1, $saved->revision );
		$this->assertSame( 'example.test', $this->repo->by_id( $saved->id )?->host );
	}

	public function test_an_update_bumps_the_revision(): void {
		$saved   = $this->repo->save( $this->canonical( 'example.test' ) );
		$updated = $this->repo->save(
			new Mapping(
				$saved->id,
				$saved->host,
				null,
				43,
				$saved->revision,
				VerificationState::PENDING,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				$saved->challenge,
				$saved->challenge_label
			)
		);

		$this->assertSame( 2, $updated->revision );
		$this->assertSame( 43, $this->repo->by_id( $saved->id )?->post_id );
	}

	public function test_a_stale_revision_is_rejected(): void {
		$saved = $this->repo->save( $this->canonical( 'example.test' ) );
		$this->repo->save(
			new Mapping(
				$saved->id,
				$saved->host,
				null,
				43,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				$saved->challenge,
				$saved->challenge_label
			)
		);

		$this->expectException( StaleRevision::class );
		$this->repo->save(
			new Mapping(
				$saved->id,
				$saved->host,
				null,
				44,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				$saved->challenge,
				$saved->challenge_label
			)
		);
	}

	public function test_a_canonical_row_without_a_post_is_rejected(): void {
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0,
				'example.test',
				null,
				null,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_an_alias_carrying_a_post_is_rejected(): void {
		$parent = $this->repo->save( $this->canonical( 'example.test' ) );

		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0,
				'www.example.test',
				$parent->id,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_an_alias_of_an_alias_is_rejected(): void {
		$parent = $this->repo->save( $this->canonical( 'example.test' ) );
		$alias  = $this->repo->save(
			new Mapping(
				0,
				'www.example.test',
				$parent->id,
				null,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'd', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0,
				'deep.example.test',
				$alias->id,
				null,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'e', 32 ),
				'_post-domain-challenge'
			)
		);
	}

	public function test_a_partial_lease_is_rejected(): void {
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0,
				'example.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'f', 32 ),
				'_post-domain-challenge',
				null,
				null,
				null,
				null,
				null,
				null,
				str_repeat( '9', 32 ),
				MutationKind::CREATE,
				null,
				null
			)
		);
	}

	public function test_a_lease_without_its_provider_binding_is_rejected(): void {
		// The binding is what recovery reads to decide which environment it may
		// question at all, so a lease without it is not a lease.
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0,
				'example.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'i', 32 ),
				'_post-domain-challenge',
				null,
				null,
				null,
				null,
				null,
				null,
				str_repeat( '9', 32 ),
				MutationKind::CREATE,
				MutationPhase::RESERVED,
				gmdate( 'Y-m-d H:i:s', time() + 120 ),
				null,
				null
			)
		);
	}

	public function test_a_complete_lease_is_accepted(): void {
		$saved = $this->repo->save(
			new Mapping(
				0,
				'example.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'g', 32 ),
				'_post-domain-challenge',
				null,
				null,
				null,
				null,
				null,
				null,
				str_repeat( '9', 32 ),
				MutationKind::CREATE,
				MutationPhase::RESERVED,
				gmdate( 'Y-m-d H:i:s', time() + 120 ),
				'cloudflare-saas',
				'zone:abc123'
			)
		);

		$after = $this->repo->by_id( $saved->id );

		$this->assertSame( MutationPhase::RESERVED, $after?->ssl_mutation_phase );
		$this->assertSame( 'cloudflare-saas', $after?->ssl_mutation_driver );
		$this->assertSame( 'zone:abc123', $after?->ssl_mutation_environment );
	}

	public function test_the_completely_unbound_state_is_valid(): void {
		$saved = $this->repo->save(
			new Mapping(
				0,
				'unbound.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'n', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->assertNull( $this->repo->by_id( $saved->id )?->ssl_provider );
	}

	/**
	 * The binding is one fact in five columns, so every proper subset is a lie
	 * about the row. Thirty of the thirty-two combinations are rejected; the two
	 * that are not are covered above and below.
	 *
	 * @dataProvider partial_bindings
	 */
	public function test_every_partial_durable_binding_is_rejected( array $present ): void {
		$this->expectException( InvalidMapping::class );

		$this->repo->save(
			new Mapping(
				0,
				'partial.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'p', 32 ),
				'_post-domain-challenge',
				in_array( 'origin', $present, true ) ? \PostDomain\Mapping\OwnershipOrigin::CREATED : null,
				in_array( 'owner', $present, true ) ? 'install-a' : null,
				in_array( 'provider', $present, true ) ? 'cloudflare-saas' : null,
				in_array( 'environment', $present, true ) ? 'cf-zone:a' : null,
				in_array( 'ref', $present, true ) ? 'ref-1' : null
			)
		);
	}

	/** @return array<string, array{0: string[]}> */
	public static function partial_bindings(): array {
		$fields = array( 'provider', 'environment', 'ref', 'origin', 'owner' );
		$cases  = array();

		// Every subset except the empty one and the complete one.
		for ( $mask = 1; $mask < ( 1 << count( $fields ) ) - 1; $mask++ ) {
			$present = array();

			foreach ( $fields as $bit => $field ) {
				if ( $mask & ( 1 << $bit ) ) {
					$present[] = $field;
				}
			}

			$cases[ implode( '+', $present ) ] = array( $present );
		}

		return $cases;
	}

	public function test_an_adopted_binding_records_when_it_was_adopted(): void {
		global $wpdb;

		// Adoption goes through MutationGate, so the timestamp is written by the
		// lease CAS rather than by save(). A row claiming ADOPTED without it is
		// the forgery the invariant exists to catch (spec §12.2).
		$saved = $this->repo->save(
			new Mapping(
				0,
				'adopted.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'q', 32 ),
				'_post-domain-challenge',
				\PostDomain\Mapping\OwnershipOrigin::CREATED,
				'install-a',
				'cloudflare-saas',
				'cf-zone:a',
				'ref-1'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_ownership_origin' => 'adopted',
				'ssl_adopted_at'       => gmdate( 'Y-m-d H:i:s' ),
				'ssl_adopted_by'       => 1,
			),
			array( 'id' => $saved->id )
		);

		$adopted = $this->repo->by_id( $saved->id );

		$this->assertSame( \PostDomain\Mapping\OwnershipOrigin::ADOPTED, $adopted?->ssl_ownership_origin );
		$this->assertNotNull( $adopted?->ssl_adopted_at );

		// And it round-trips through the repository, which the invariant accepts.
		$this->assertNotNull( $this->repo->save( $adopted ) );
	}

	public function test_an_adopted_origin_without_an_adoption_timestamp_is_rejected(): void {
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0,
				'forged.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'r', 32 ),
				'_post-domain-challenge',
				\PostDomain\Mapping\OwnershipOrigin::ADOPTED,
				'install-a',
				'cloudflare-saas',
				'cf-zone:a',
				'ref-1'
			)
		);
	}

	public function test_saving_writes_every_column_it_claims_to(): void {
		// A prepared statement whose values drift from its columns fails silently
		// in the direction of writing the wrong thing, so the round trip is the
		// assertion: everything set comes back.
		$saved = $this->repo->save(
			new Mapping(
				0,
				'roundtrip.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'm', 32 ),
				'_pd',
				\PostDomain\Mapping\OwnershipOrigin::CREATED,
				'install-z',
				'cloudflare-saas',
				'cf-zone:z',
				'ref-z',
				'http'
			)
		);

		$after = $this->repo->by_id( $saved->id );

		$this->assertSame( 'roundtrip.test', $after?->host );
		$this->assertSame( \PostDomain\Mapping\OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( 'install-z', $after?->ssl_owner_installation_id );
		$this->assertSame( 'cloudflare-saas', $after?->ssl_provider );
		$this->assertSame( 'cf-zone:z', $after?->ssl_provider_environment );
		$this->assertSame( 'ref-z', $after?->ssl_ref );
		$this->assertSame( 'http', $after?->ssl_method );
		$this->assertSame( SslState::ACTIVE, $after?->ssl_state );
	}

	public function test_a_complete_created_binding_is_valid(): void {
		$saved = $this->repo->save(
			new Mapping(
				0,
				'example.test',
				null,
				42,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'l', 32 ),
				'_post-domain-challenge',
				\PostDomain\Mapping\OwnershipOrigin::CREATED,
				'install-a',
				'cloudflare-saas',
				'cf-zone:a',
				'ref-1'
			)
		);

		$after = $this->repo->by_id( $saved->id );

		$this->assertSame( 'cloudflare-saas', $after?->ssl_provider );
		$this->assertSame( 'cf-zone:a', $after?->ssl_provider_environment );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertSame( \PostDomain\Mapping\OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( 'install-a', $after?->ssl_owner_installation_id );
		$this->assertNull( $after?->ssl_adopted_at );
	}

	public function test_an_illegal_state_transition_is_rejected(): void {
		$saved = $this->repo->save( $this->canonical( 'example.test' ) );

		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				$saved->id,
				$saved->host,
				null,
				42,
				$saved->revision,
				VerificationState::VERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				$saved->challenge,
				$saved->challenge_label
			)
		);
	}
}
