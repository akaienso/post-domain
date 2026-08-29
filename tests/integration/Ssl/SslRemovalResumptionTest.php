<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Rest\Guard;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\CronWiring;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalScope;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use WP_REST_Request;

/**
 * An SSL-only removal that the provider does not finish immediately has to be
 * picked up again, and picked up by the right workflow.
 *
 * The defect these exist for was that it could not be: the only selector keyed
 * on `ssl_state = pending_removal` and always dispatched mapping deletion, so an
 * unfinished `DELETE /ssl` was either invisible forever or — had it been visible
 * — would have hard-deleted a domain nobody asked to delete.
 */
final class SslRemovalResumptionTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( 'pd_settings' );
		DriverFactory::reset();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		// The plugin instance is a singleton, so the primary host this class
		// pinned must not outlive it and decide another class's routes.
		Plugin::instance()->context()->set_host(
			new HostContext( 'mapped.test', null, 'mapped.test', HostKind::MAPPED, null, EndpointClass::ROUTED, true, 'GET' )
		);

		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_filters( 'pd_dns_resolver' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/** Installs a driver the way a site would; call again to change the answer. */
	private function boot( RecordingDriver $driver, DnsOutcome $outcome = DnsOutcome::MATCH ): RecordingDriver {
		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_filters( 'pd_dns_resolver' );

		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		add_filter(
			'pd_dns_resolver',
			static fn(): DnsResolver => new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);

		Plugin::boot();
		CronWiring::register();

		// The management routes exist only on the primary host, by design. The
		// plugin instance is a singleton, so a host context left behind by an
		// earlier test would otherwise decide whether these routes exist at all.
		Plugin::instance()->context()->set_host(
			new HostContext( 'primary.test', null, 'primary.test', HostKind::PRIMARY, null, EndpointClass::ROUTED, true, 'GET' )
		);

		// The REST server is built *after* the driver and resolver filters are in
		// place. Routes are registered once and the first registration answers, so
		// a server raised before the filters would serve a controller wired to the
		// null driver and refuse every mutation for the wrong reason.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		return $driver;
	}

	private function owned(): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"resume-{$this->seq}.test",
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

	private function delete_ssl( Mapping $mapping ): \WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $mapping->id . '/ssl' );
		$request->set_header( 'if_match', Guard::etag( $this->repo->by_id( $mapping->id ) ) );

		return rest_do_request( $request );
	}

	private function delete_mapping( Mapping $mapping ): \WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $mapping->id );
		$request->set_header( 'if_match', Guard::etag( $this->repo->by_id( $mapping->id ) ) );

		return rest_do_request( $request );
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

	private function make_due( int $id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array( 'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $id )
		);
	}

	public function test_a_pending_ssl_removal_claims_the_resource_scope(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m = $this->owned();

		$this->delete_ssl( $m );

		$row = $this->row( $m->id );

		$this->assertSame( RemovalScope::RESOURCE->value, $row['ssl_removal_scope'] );
		$this->assertNull( $row['deletion_requested_at'], 'nobody asked for the mapping to go' );
		$this->assertNotNull( $row['deletion_next_attempt_at'] );
		$this->assertGreaterThan(
			gmdate( 'Y-m-d H:i:s' ),
			(string) $row['deletion_next_attempt_at'],
			'an unfinished removal is scheduled forward, never left due'
		);
		$this->assertSame( 'ref-1', $row['ssl_ref'], 'the binding survives until removal is confirmed' );
	}

	public function test_a_pending_ssl_removal_is_resumed_by_the_sweep_when_due(): void {
		$driver = $this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m      = $this->owned();

		$this->delete_ssl( $m );
		$after_rest = $driver->remove_calls;

		// Not yet due: the sweep must leave it alone.
		do_action( 'pd_ssl_sweep' );
		$this->assertSame( $after_rest, $driver->remove_calls, 'a future retry time is not an invitation' );

		$this->make_due( $m->id );
		do_action( 'pd_ssl_sweep' );

		$this->assertGreaterThan( $after_rest, $driver->remove_calls, 'the removal is resumed once due' );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'resuming must never delete the mapping' );
	}

	public function test_a_later_removed_result_clears_the_binding_and_keeps_the_mapping(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m = $this->owned();

		$this->delete_ssl( $m );
		$this->make_due( $m->id );

		// The provider finishes between sweeps.
		$this->boot( RecordingDriver::removing( RemovalOutcome::REMOVED ) );
		do_action( 'pd_ssl_sweep' );

		$after = $this->repo->by_id( $m->id );

		$this->assertNotNull( $after, 'an SSL-only removal never deletes the mapping' );
		$this->assertSame( "resume-{$this->seq}.test", $after->host );
		$this->assertSame( VerificationState::VERIFIED, $after->verification_state );
		$this->assertSame( SslState::REVOKED, $after->ssl_state );

		$row = $this->row( $m->id );

		foreach (
			array(
				'ssl_provider',
				'ssl_provider_environment',
				'ssl_ref',
				'ssl_ownership_origin',
				'ssl_owner_installation_id',
			) as $column
		) {
			$this->assertNull( $row[ $column ], "{$column} is cleared with the rest of the binding" );
		}

		$this->assertNull( $row['ssl_removal_scope'], 'nothing is outstanding, so nothing selects it again' );
		$this->assertNull( $row['deletion_next_attempt_at'] );
	}

	/** @dataProvider transient_drivers */
	public function test_a_transient_ssl_removal_is_resumed_only_when_due( string $factory, int $seconds ): void {
		$driver = $this->boot(
			'retry_after' === $factory
				? RecordingDriver::removing_with_retry_after( RemovalOutcome::TRANSIENT, $seconds )
				: RecordingDriver::removing( RemovalOutcome::TRANSIENT )
		);
		$m      = $this->owned();

		$this->delete_ssl( $m );

		$row = $this->row( $m->id );
		$this->assertSame( RemovalScope::RESOURCE->value, $row['ssl_removal_scope'] );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $row['deletion_next_attempt_at'] );
		$this->assertSame( '0', (string) $row['deletion_attempts'], 'transient is not evidence of failure' );

		$before = $driver->remove_calls;
		do_action( 'pd_ssl_sweep' );
		$this->assertSame( $before, $driver->remove_calls, 'not due yet' );

		$this->make_due( $m->id );
		do_action( 'pd_ssl_sweep' );
		$this->assertGreaterThan( $before, $driver->remove_calls, 'due now' );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function transient_drivers(): array {
		return array(
			'with retry-after'    => array( 'retry_after', 120 ),
			'without retry-after' => array( 'plain', 0 ),
		);
	}

	public function test_whole_mapping_deletion_still_hard_deletes_through_its_own_workflow(): void {
		$this->boot( RecordingDriver::removing( RemovalOutcome::REMOVED ) );
		$m = $this->owned();

		$this->delete_mapping( $m );

		$this->assertSame(
			RemovalScope::MAPPING->value,
			$this->row( $m->id )['ssl_removal_scope'],
			'the requesting CAS claims the scope'
		);

		do_action( 'pd_ssl_sweep' );

		$this->assertNull( $this->repo->by_id( $m->id ), 'the mapping goes once the provider confirms' );
	}

	public function test_a_leased_row_is_never_selected(): void {
		global $wpdb;

		$driver = $this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m      = $this->owned();

		$this->delete_ssl( $m );
		$this->make_due( $m->id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array( 'ssl_mutation_token' => str_repeat( '9', 32 ) ),
			array( 'id' => $m->id )
		);

		$before = $driver->remove_calls;
		do_action( 'pd_ssl_sweep' );

		$this->assertSame( $before, $driver->remove_calls, 'a leased row belongs to whoever holds the lease' );
	}

	public function test_an_ssl_removal_is_refused_while_the_mapping_is_already_going(): void {
		$driver = $this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m      = $this->owned();

		$this->delete_mapping( $m );
		$before = $driver->remove_calls;

		$response = $this->delete_ssl( $this->repo->by_id( $m->id ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $before, $driver->remove_calls, 'no second provider mutation' );
		$this->assertSame(
			RemovalScope::MAPPING->value,
			$this->row( $m->id )['ssl_removal_scope'],
			'the scope cannot be downgraded underneath a requested deletion'
		);
	}

	public function test_a_stale_scope_loses_its_cas_and_calls_no_provider(): void {
		$driver = $this->boot( RecordingDriver::removing( RemovalOutcome::PENDING ) );
		$m      = $this->owned();

		$this->delete_ssl( $m );
		$this->make_due( $m->id );

		// A stale read: what the sweep would have selected a moment ago, now
		// overtaken by a writer that moved the row on.
		$stale = $this->repo->by_id( $m->id );

		$this->repo->save(
			new Mapping(
				$stale->id,
				$stale->host,
				null,
				$stale->post_id,
				$stale->revision,
				$stale->verification_state,
				ActivationState::INACTIVE,
				$stale->ssl_state,
				null,
				$stale->challenge,
				$stale->challenge_label,
				$stale->ssl_ownership_origin,
				$stale->ssl_owner_installation_id,
				$stale->ssl_provider,
				$stale->ssl_provider_environment,
				$stale->ssl_ref
			)
		);

		$before  = $driver->remove_calls;
		$service = SslServices::production()->remove_resource;

		$this->assertSame(
			'refused',
			$service->process( $stale ),
			'a lease pinned to a revision that has moved cannot be acquired'
		);
		$this->assertSame( $before, $driver->remove_calls, 'and so no provider call is made' );
	}
}
