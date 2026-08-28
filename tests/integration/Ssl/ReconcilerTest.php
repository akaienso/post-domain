<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\Reconciler;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

final class ReconcilerTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Environment::remember_primary_host();
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		$this->repo = new DbRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/** Installs a driver the way a site would, through the one factory. */
	private function install( SslDriver $driver ): void {

		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();
	}

	/** @param array<string, SslStatus> $statuses */
	private function driver( array $statuses, bool $complete, string $environment = 'recon:default' ): SslDriver {
		return new class( $statuses, $complete, $environment ) implements SslDriver {
			/** @param array<string, SslStatus> $statuses */
			public function __construct(
				private readonly array $statuses,
				private readonly bool $complete,
				private readonly string $environment
			) {}

			public function id(): string {
				return 'recon';
			}

			public function environment_id(): string {
				return $this->environment;
			}

			public function capabilities(): DriverCapabilities {
				return new DriverCapabilities( false, array( 'txt' ), false );
			}

			public function status( SslResourceContext $ctx ): SslStatus {
				return $this->statuses[ $ctx->host ] ?? new SslStatus( SslState::NONE );
			}

			public function identify( SslResourceContext $ctx ): IdentityResult {
				return new IdentityResult(
					IdentityVerdict::MATCH,
					$ctx->provider_ref,
					$ctx->provider_ref,
					$ctx->host,
					null,
					MarkerSupport::UNAVAILABLE,
					true,
					false
				);
			}

			public function create( SslResourceContext $c, ExecutionPermit $p ): SslStatus {
				throw new \LogicException( 'reconciliation must never mutate' );
			}

			public function adopt( SslResourceContext $c, ExecutionPermit $p ): SslStatus {
				throw new \LogicException( 'reconciliation must never mutate' );
			}

			public function change_validation_method( SslResourceContext $c, string $m, ExecutionPermit $p ): SslStatus {
				throw new \LogicException( 'reconciliation must never mutate' );
			}

			public function remove( SslResourceContext $c, ExecutionPermit $p ): RemovalResult {
				throw new \LogicException( 'reconciliation must never mutate' );
			}

			public function reconcile( array $contexts ): ReconcileReport {
				unset( $contexts );

				return new ReconcileReport(
					$this->statuses,
					$this->complete,
					$this->complete ? null : 'pagination_failed'
				);
			}

			public function validation_plan( SslResourceContext $c, ?object $a ): ValidationPlan {
				return new ValidationPlan( array(), array(), array(), array(), array() );
			}
		};
	}

	private function mapping( SslState $state, ?string $method = 'txt' ): Mapping {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0,
				'mapped.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				$state,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recon',
				'recon:default',
				'ref-1'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_method' => $method ),
			array( 'id' => $m->id )
		);

		return $this->repo->by_id( $m->id );
	}

	public function test_provider_truth_updates_the_local_state(): void {
		$m      = $this->mapping( SslState::PENDING_VALIDATION );
		$driver = $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true );

		$this->install( $driver );
		Reconciler::run( array( $m ) );

		$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_an_incomplete_snapshot_never_infers_a_missing_resource(): void {
		$m = $this->mapping( SslState::ACTIVE );

		$this->install( $this->driver( array(), false ) );
		Reconciler::run( array( $m ) );

		$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_a_transient_status_changes_nothing(): void {
		$m      = $this->mapping( SslState::ACTIVE );
		$driver = $this->driver(
			array( 'mapped.test' => new SslStatus( SslState::FAILED, 'ref-1', 'timeout', null, null, true ) ),
			true
		);

		$this->install( $driver );
		Reconciler::run( array( $m ) );

		$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_a_divergent_method_is_reported_not_patched(): void {
		$m      = $this->mapping( SslState::ACTIVE, 'txt' );
		$driver = $this->driver(
			array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1', null, null, 'http' ) ),
			true
		);

		$this->install( $driver );
		$result = Reconciler::run( array( $m ) );

		$this->assertSame( 'txt', $this->repo->by_id( $m->id )?->ssl_method );
		$this->assertGreaterThan( 0, $result['divergences'] );
		$this->assertNotEmpty( EventLog::for_domain( $m->id ) );
	}

	public function test_reconciliation_never_adopts_ownership(): void {
		// A genuinely unbound mapping — not a half-cleared one, which the
		// repository invariant forbids — for which the provider reports a
		// resource. Finding one is not a reason to claim it.
		$m = $this->repo->save(
			new Mapping(
				0,
				'unbound.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'u', 32 ),
				'_post-domain-challenge'
			)
		);

		$this->install( $this->driver( array( 'unbound.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );
		Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_ownership_origin );
		$this->assertNull( $after?->ssl_ref );
		$this->assertNull( $after?->ssl_provider_environment );
	}

	public function test_a_revision_race_is_not_counted_as_an_update(): void {
		global $wpdb;

		$m = $this->mapping( SslState::PENDING_VALIDATION );
		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );

		// The snapshot was read at revision N; someone else has since moved on.
		$stale = $this->repo->by_id( $m->id );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'revision' => $stale->revision + 7 ),
			array( 'id' => $m->id )
		);

		$result = Reconciler::run( array( $stale ) );

		$this->assertSame( 0, $result['updated'], 'a zero-row write is not an update' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( SslState::PENDING_VALIDATION, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_a_lease_acquired_after_the_snapshot_blocks_the_write(): void {
		global $wpdb;

		$m     = $this->mapping( SslState::PENDING_VALIDATION );
		$stale = $this->repo->by_id( $m->id );

		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '8', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			),
			array( 'id' => $m->id )
		);

		$result = Reconciler::run( array( $stale ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( SslState::PENDING_VALIDATION, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_a_discarded_update_records_no_transition_event(): void {
		global $wpdb;

		$m     = $this->mapping( SslState::PENDING_VALIDATION );
		$stale = $this->repo->by_id( $m->id );

		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'revision' => $stale->revision + 7 ),
			array( 'id' => $m->id )
		);

		Reconciler::run( array( $stale ) );

		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'active' === $e['to_state']
			)
		);
	}

	public function test_a_bound_resource_in_another_environment_is_never_read(): void {
		$m = $this->mapping( SslState::ACTIVE );
		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::FAILED, 'ref-1' ) ), true, 'recon:somewhere-else' ) );

		$result = Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['drifted'] );
		$this->assertSame(
			SslState::ACTIVE,
			$this->repo->by_id( $m->id )?->ssl_state,
			'zone B has never heard of this certificate; its answer is evidence about nothing'
		);
	}

	public function test_reconciliation_resumes_once_the_environment_is_restored(): void {
		$m = $this->mapping( SslState::PENDING_VALIDATION );

		$this->install( $this->driver( array(), true, 'recon:somewhere-else' ) );
		Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		remove_all_filters( 'pd_ssl_drivers' );
		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );

		$result = Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $m->id )?->ssl_state );
	}

	public function test_a_mapping_whose_driver_is_unavailable_is_skipped(): void {
		$m = $this->mapping( SslState::PENDING_VALIDATION );

		// No driver installed at all: the stored provider resolves to nothing.
		$result = Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_leased_rows_are_skipped(): void {
		global $wpdb;

		$m = $this->mapping( SslState::PENDING_VALIDATION );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '4', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			),
			array( 'id' => $m->id )
		);

		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );
		$result = Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		$this->assertSame( SslState::PENDING_VALIDATION, $this->repo->by_id( $m->id )?->ssl_state );
		$this->assertSame( 1, $result['skipped'], 'a leased row is not even read' );
	}
}
