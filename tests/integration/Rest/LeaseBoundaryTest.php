<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\MutationInProgress;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use WP_REST_Request;

/**
 * The six `ssl_mutation_*` columns describe an operation that may already have
 * been sent to a provider. An ordinary write must be incapable of touching them.
 *
 * The defect these exist for: `DbRepository::save()` carried all six in its
 * update data, and `ManagementController::update()` rebuilds a `Mapping` from
 * the request without them — so a routine PATCH with a current ETag wrote six
 * NULLs over a live lease and destroyed the fencing token and the recovery
 * record in one statement.
 */
final class LeaseBoundaryTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Plugin::boot();

		Plugin::instance()->context()->set_host(
			new HostContext( 'primary.test', null, 'primary.test', HostKind::PRIMARY, null, EndpointClass::ROUTED, true, 'GET' )
		);

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		Plugin::instance()->context()->set_host(
			new HostContext( 'mapped.test', null, 'mapped.test', HostKind::MAPPED, null, EndpointClass::ROUTED, true, 'GET' )
		);

		parent::tear_down();
	}

	private function mapping(): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"lease-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_pad( (string) $this->seq, 32, 'a', STR_PAD_LEFT ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	/** Writes a lease the way MutationLease would, bypassing save() entirely. */
	private function lease( int $id, string $phase, bool $expired ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => str_repeat( '7', 32 ),
				'ssl_mutation_kind'        => 'remove',
				'ssl_mutation_phase'       => $phase,
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + ( $expired ? -600 : 600 ) ),
				'ssl_mutation_driver'      => 'recording',
				'ssl_mutation_environment' => 'recording:default',
			),
			array( 'id' => $id )
		);
	}

	/** @return array<string, string|null> */
	private function row( int $id ): array {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table(), never caller input.
		/** @var array<string, string|null> $row */
		$row = (array) $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row;
	}

	private function patch( int $id, string $etag ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $id );
		$request->set_header( 'if_match', $etag );
		$request->set_body_params( array( 'activation_state' => 'inactive' ) );

		return rest_do_request( $request );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function every_phase(): array {
		return array(
			'reserved, unexpired'   => array( 'reserved', false ),
			'reserved, expired'     => array( 'reserved', true ),
			'in_flight, unexpired'  => array( 'in_flight', false ),
			'in_flight, expired'    => array( 'in_flight', true ),
			'recovering, unexpired' => array( 'recovering', false ),
			'recovering, expired'   => array( 'recovering', true ),
		);
	}

	/**
	 * @dataProvider every_phase
	 */
	public function test_a_patch_is_refused_and_the_lease_is_untouched( string $phase, bool $expired ): void {
		$m = $this->mapping();

		$this->lease( $m->id, $phase, $expired );

		$before = $this->row( $m->id );
		$etag   = Guard::etag( $this->repo->by_id( $m->id ) );

		$response = $this->patch( $m->id, $etag );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'pd_mutation_in_progress', $response->get_data()['code'] ?? null );

		$after = $this->row( $m->id );

		foreach (
			array(
				'ssl_mutation_token',
				'ssl_mutation_kind',
				'ssl_mutation_phase',
				'ssl_mutation_expires_at',
				'ssl_mutation_driver',
				'ssl_mutation_environment',
			) as $column
		) {
			$this->assertSame( $before[ $column ], $after[ $column ], "{$column} is untouched" );
		}

		$this->assertSame( $before['revision'], $after['revision'], 'the row did not move at all' );
		$this->assertSame( $before['activation_state'], $after['activation_state'] );
		$this->assertSame( $before['ssl_ref'], $after['ssl_ref'], 'no provider state was lost' );
	}

	/**
	 * The lease arrives after the request has read the row and passed its
	 * precondition, so the guard above cannot see it. The repository's own CAS
	 * is what has to lose the write.
	 */
	public function test_a_lease_taken_after_the_read_makes_the_write_lose(): void {
		$m    = $this->mapping();
		$etag = Guard::etag( $this->repo->by_id( $m->id ) );

		$id = $m->id;

		add_action(
			'pd_test_before_repository_update',
			function () use ( $id ): void {
				remove_all_actions( 'pd_test_before_repository_update' );
				$this->lease( $id, 'in_flight', false );
			}
		);

		$before   = $this->row( $id );
		$response = $this->patch( $id, $etag );

		$after = $this->row( $id );

		$this->assertSame( 409, $response->get_status(), 'the write lost' );
		$this->assertSame( 'pd_mutation_in_progress', $response->get_data()['code'] ?? null );
		$this->assertSame( str_repeat( '7', 32 ), $after['ssl_mutation_token'], 'the lease stands' );
		$this->assertSame( 'in_flight', $after['ssl_mutation_phase'] );
		$this->assertSame( $before['activation_state'], $after['activation_state'], 'and nothing was written' );

		remove_all_actions( 'pd_test_before_repository_update' );
	}

	public function test_an_ordinary_save_cannot_clear_a_lease(): void {
		$m = $this->mapping();

		$this->lease( $m->id, 'reserved', false );

		$current = $this->repo->by_id( $m->id );
		$before  = $this->row( $m->id );

		// A caller holding the current revision, asking for an ordinary change,
		// with the six lease fields at their null defaults — the exact shape a
		// Mapping rebuilt from a request body has.
		$this->expectException( MutationInProgress::class );

		try {
			$this->repo->save(
				new Mapping(
					$current->id,
					$current->host,
					null,
					$current->post_id,
					$current->revision,
					$current->verification_state,
					ActivationState::INACTIVE,
					$current->ssl_state,
					null,
					$current->challenge,
					$current->challenge_label,
					$current->ssl_ownership_origin,
					$current->ssl_owner_installation_id,
					$current->ssl_provider,
					$current->ssl_provider_environment,
					$current->ssl_ref
				)
			);
		} finally {
			$after = $this->row( $m->id );

			$this->assertSame( $before['ssl_mutation_token'], $after['ssl_mutation_token'] );
			$this->assertSame( $before['ssl_mutation_phase'], $after['ssl_mutation_phase'] );
			$this->assertSame( $before['ssl_mutation_driver'], $after['ssl_mutation_driver'] );
			$this->assertSame( $before['ssl_mutation_environment'], $after['ssl_mutation_environment'] );
			$this->assertSame( $before['revision'], $after['revision'] );
		}
	}

	public function test_a_lease_free_row_still_patches_under_its_etag(): void {
		$m    = $this->mapping();
		$etag = Guard::etag( $this->repo->by_id( $m->id ) );

		$response = $this->patch( $m->id, $etag );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ActivationState::INACTIVE, $this->repo->by_id( $m->id )?->activation_state );
	}

	public function test_a_stale_etag_is_still_a_precondition_failure(): void {
		$m    = $this->mapping();
		$etag = Guard::etag( $this->repo->by_id( $m->id ) );

		$this->patch( $m->id, $etag );

		$this->assertSame( 412, $this->patch( $m->id, $etag )->get_status(), 'the second use is stale' );
	}
}
