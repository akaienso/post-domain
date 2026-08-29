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
use PostDomain\Ssl\CronWiring;
use PostDomain\Ssl\DeletionSchedule;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalScope;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;

/**
 * The scope column decides which workflow finishes a removal, and one of those
 * workflows ends in a hard delete.
 *
 * The defect these exist for: the dispatcher named `RESOURCE` explicitly and
 * sent *everything else* to mapping deletion, while the selector accepted any
 * non-null scope. A single corrupted byte in that column was therefore a
 * deleted domain.
 */
final class RemovalScopeDispatchTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private RecordingDriver $driver;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo   = new DbRepository();
		$this->driver = RecordingDriver::removing( RemovalOutcome::REMOVED );

		$driver = $this->driver;

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
			static fn(): DnsResolver => new class() implements DnsResolver {
				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( DnsOutcome::MATCH );
				}
			}
		);

		Plugin::boot();
		CronWiring::register();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_filters( 'pd_dns_resolver' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/** Writes a scope directly, so a value no enum case covers can be tested. */
	private function due_with_scope( ?string $scope ): Mapping {
		global $wpdb;

		++$this->seq;

		$m = $this->repo->save(
			new Mapping(
				0,
				"scope-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::INACTIVE,
				SslState::PENDING_REMOVAL,
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

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::domains_table(),
			array(
				'ssl_removal_scope'        => $scope,
				'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			),
			array( 'id' => $m->id )
		);

		return $this->repo->by_id( $m->id );
	}

	private function events_for( int $id ): int {
		global $wpdb;

		$table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::events_table(), never caller input.
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE domain_id = %d AND type = 'integrity'", $id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_the_resource_scope_removes_only_the_certificate(): void {
		$m = $this->due_with_scope( RemovalScope::RESOURCE->value );

		do_action( 'pd_ssl_sweep' );

		$after = $this->repo->by_id( $m->id );

		$this->assertNotNull( $after, 'the mapping stays' );
		$this->assertNull( $after->ssl_ref, 'and the binding goes' );
		$this->assertSame( 1, $this->driver->remove_calls );
	}

	public function test_the_mapping_scope_deletes_the_mapping(): void {
		$m = $this->due_with_scope( RemovalScope::MAPPING->value );

		do_action( 'pd_ssl_sweep' );

		$this->assertNull( $this->repo->by_id( $m->id ) );
		$this->assertSame( 1, $this->driver->remove_calls );
	}

	/**
	 * @dataProvider unreadable_scopes
	 */
	public function test_an_unreadable_scope_touches_nothing( string $scope ): void {
		$m = $this->due_with_scope( $scope );

		do_action( 'pd_ssl_sweep' );

		$after = $this->repo->by_id( $m->id );

		$this->assertNotNull( $after, 'a scope nobody can read is never a reason to delete' );
		$this->assertSame( 0, $this->driver->remove_calls, 'and never a reason to call a provider' );
		$this->assertSame( 'ref-1', $after->ssl_ref, 'nothing local changed either' );
		$this->assertSame( $m->revision, $after->revision );
	}

	/** @return array<string, array{0: string}> */
	public static function unreadable_scopes(): array {
		return array(
			'a value from a newer build' => array( 'certificate_only' ),
			'a truncated value'          => array( 'mappin' ),
			'corruption'                 => array( "\x01\x02" ),
			'an empty string'            => array( '' ),
		);
	}

	public function test_an_unreadable_scope_is_reported_for_diagnosis(): void {
		$m = $this->due_with_scope( 'certificate_only' );

		do_action( 'pd_ssl_sweep' );

		$this->assertSame( 1, $this->events_for( $m->id ), 'reported' );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'and not repaired' );
	}

	public function test_the_selector_leaves_an_unreadable_scope_out_of_ordinary_work(): void {
		$clock = new SystemClock();

		$this->due_with_scope( 'certificate_only' );

		$this->assertSame( array(), DeletionSchedule::due( 50, $clock ), 'not ordinary work' );
		$this->assertCount( 1, DeletionSchedule::undecidable( 50, $clock ), 'but not invisible either' );
	}

	public function test_a_null_scope_is_not_due_work_at_all(): void {
		$m = $this->due_with_scope( null );

		do_action( 'pd_ssl_sweep' );

		$this->assertNotNull( $this->repo->by_id( $m->id ) );
		$this->assertSame( 0, $this->driver->remove_calls );
		$this->assertSame( 0, $this->events_for( $m->id ), 'a null scope is ordinary, not an integrity problem' );
	}

	/**
	 * Safety that depends on the caller routing correctly is not safety. Each
	 * service refuses the other's scope on its own account.
	 */
	public function test_deletion_service_refuses_a_resource_scope_directly(): void {
		$m       = $this->due_with_scope( RemovalScope::RESOURCE->value );
		$service = SslServices::production();

		$this->assertSame( 'scope_conflict', $service->delete->process( $m ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'no hard delete' );
		$this->assertSame( 0, $this->driver->remove_calls, 'and no provider call' );
	}

	public function test_resource_removal_refuses_a_mapping_scope_directly(): void {
		$m       = $this->due_with_scope( RemovalScope::MAPPING->value );
		$service = SslServices::production();

		$this->assertSame( 'scope_conflict', $service->remove_resource->process( $m ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
		$this->assertSame( 0, $this->driver->remove_calls );
	}

	/** @dataProvider unreadable_scopes */
	public function test_both_services_refuse_an_unreadable_scope_directly( string $scope ): void {
		$m       = $this->due_with_scope( $scope );
		$service = SslServices::production();

		$this->assertSame( 'scope_conflict', $service->delete->process( $m ), 'mapping deletion refuses' );
		$this->assertSame( 'scope_conflict', $service->remove_resource->process( $m ), 'so does resource removal' );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'no malformed value reaches a hard delete' );
		$this->assertSame( 0, $this->driver->remove_calls );
	}
}
