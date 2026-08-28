<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Mapping;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class RepositoryReadTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
	}

	private function seed( string $host, int $post_id ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'host'            => $host,
				'post_id'         => $post_id,
				'challenge'       => str_repeat( substr( md5( $host ), 0, 1 ), 32 ),
				'challenge_label' => '_post-domain-challenge',
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_by_host_finds_a_row(): void {
		$id      = $this->seed( 'example.test', 42 );
		$mapping = $this->repo->by_host( 'example.test' );

		$this->assertNotNull( $mapping );
		$this->assertSame( $id, $mapping->id );
		$this->assertSame( 42, $mapping->post_id );
		$this->assertSame( VerificationState::UNVERIFIED, $mapping->verification_state );
		$this->assertSame( ActivationState::INACTIVE, $mapping->activation_state );
		$this->assertSame( 1, $mapping->revision );
	}

	public function test_by_host_is_exact_and_case_sensitive(): void {
		$this->seed( 'example.test', 42 );

		$this->assertNull( $this->repo->by_host( 'EXAMPLE.TEST' ) );
		$this->assertNull( $this->repo->by_host( 'sub.example.test' ) );
	}

	public function test_by_id_finds_a_row(): void {
		$id = $this->seed( 'example.test', 42 );

		$this->assertSame( $id, $this->repo->by_id( $id )?->id );
	}

	public function test_a_missing_row_is_null(): void {
		$this->assertNull( $this->repo->by_host( 'absent.test' ) );
		$this->assertNull( $this->repo->by_id( 999999 ) );
	}

	public function test_all_returns_every_row(): void {
		$this->seed( 'one.test', 1 );
		$this->seed( 'two.test', 2 );

		$this->assertCount( 2, $this->repo->all() );
	}
}
