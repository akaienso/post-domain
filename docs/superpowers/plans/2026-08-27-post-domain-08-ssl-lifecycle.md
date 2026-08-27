# post-domain 08 — SSL lifecycle: create, adopt, method change, delete, reconcile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every provider operation runs the full precondition set, goes through
`MutationGate`, treats a failed finalization as fencing, and resolves every
ambiguous outcome by reading rather than repeating.

**Architecture:** Four authorizers share one precondition core
(`AuthorizerSupport`) and one release rule. Four services hand the gate a driver,
a context, and an authorization; none of them names a mutating driver method or
holds a permit. Recovery is a `RecoveryResolver` that reads and decides per
mutation kind.

**Tech Stack:** As Plans 01–07.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–07, and add:

- **Every operation runs the full precondition set** before consumption:
  environment resolved, driver matches, `RESERVED` lease held, fresh complete
  identity, no conflicting marker, fresh two-resolver DNS proof, method supported
  where applicable, and the consumption CAS (spec §14.4).
- **A refusal after acquisition releases the reservation** (spec §12.6).
- **A failed `finalize()` means fenced:** discard the local result, write nothing
  further, delete nothing, retry nothing (spec §12.6) — and **return a fenced
  result, not the provider's status**. A provider call that succeeded is not the
  same fact as a mutation that took effect.
- **Every service returns `MutationResult`** (Plan 07 Task 4), which carries an
  `SslStatus` only for `COMMITTED`. `FENCED` and `CONFIRMED_NOT_PERSISTED` are
  told apart by re-reading the row: a replaced token or a `RECOVERING` phase
  means recovery took it; anything else means the write simply did not land.
- **The in-flight revision comes from `GateResult`,** never from
  `lease revision + 1` (spec §12.6).
- **A state change and its event are written together** through
  `AtomicTransition::commit()` (spec §12.3). No success event is ever written
  before the CAS that establishes it.
- **Drivers come from `DriverFactory`** and nowhere else, so REST, cron,
  reconciliation, and recovery cannot disagree about which driver owns a row.
- **Every CAS result is checked.** A zero-row write is never counted, reported,
  or logged as though it had happened.
- **Adoption is never automatic** (spec §14.7).
- **The normal deletion path never deletes the local row before external cleanup
  succeeds;** force-local-delete is the only exception and issues no provider
  deletion (spec §14.15).
- **Reconciliation never adopts ownership and never auto-patches a method**
  (spec §14.17).

---

## File map

| File | Responsibility |
|---|---|
| `src/Ssl/CreateAuthorizer.php`, `CreateService.php`, `CreateRecovery.php` | Provisioning and ambiguous-create resolution |
| `src/Ssl/AdoptionAuthorizer.php`, `AdoptionService.php` | Explicit adoption |
| `src/Ssl/MethodChangeAuthorizer.php`, `MethodChangeService.php` | DCV method change |
| `src/Ssl/DeletionService.php`, `ForceLocalDelete.php` | Durable removal and the local-only exception |
| `src/Ssl/DriverRecoveryResolver.php` | Per-kind recovery reads |
| `src/Ssl/Reconciler.php` | Daily provider-truth adoption for state only |

---

### Task 1: Ambiguous-create decisions

**Files:**
- Create: `src/Ssl/CreateRecovery.php`
- Test: `tests/unit/Ssl/CreateRecoveryTest.php`

**Interfaces:**
- Consumes: `IdentityResult`, `SslResourceContext` (Plan 07).
- Produces: `PostDomain\Ssl\CreateRecovery::BIND`, `::RETRY`, `::ADOPT_REQUIRED`, `::UNOWNED`, `::WAIT`, and `::decide( IdentityResult $identity, SslResourceContext $ctx ): string`.

The six cases of spec §14.6. Only one binds a reference without an explicit
adoption, and it needs a marker naming this installation **and** this mapping.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/CreateRecoveryTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\CreateRecovery;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\ProviderMarker;
use PostDomain\Ssl\SslResourceContext;

final class CreateRecoveryTest extends TestCase {

	private function context( ?string $ref = null ): SslResourceContext {
		return new SslResourceContext(
			12, 'mapped.test', 'install-a', 'test-driver', $ref, null, null,
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 'abc', 3
		);
	}

	private function identity(
		IdentityVerdict $verdict,
		?string $observed_ref,
		?ProviderMarker $marker,
		MarkerSupport $support,
		bool $complete = true,
		bool $transient = false
	): IdentityResult {
		return new IdentityResult(
			$verdict, null, $observed_ref, 'mapped.test', $marker, $support, $complete, $transient
		);
	}

	public function test_case_c_conclusive_absence_is_retryable(): void {
		$this->assertSame(
			CreateRecovery::RETRY,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::ABSENT, null, null, MarkerSupport::SUPPORTED ),
				$this->context()
			)
		);
	}

	public function test_case_d_a_marker_naming_this_install_and_mapping_binds(): void {
		$this->assertSame(
			CreateRecovery::BIND,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::RECOVERABLE_CREATE,
					'ref-9',
					new ProviderMarker( 'install-a', 12, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context()
			)
		);
	}

	public function test_case_d_does_not_apply_to_another_mapping(): void {
		$this->assertSame(
			CreateRecovery::UNOWNED,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::RECOVERABLE_CREATE,
					'ref-9',
					new ProviderMarker( 'install-a', 99, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context()
			)
		);
	}

	public function test_case_e_markers_unavailable_requires_explicit_adoption(): void {
		$this->assertSame(
			CreateRecovery::ADOPT_REQUIRED,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::MISMATCH, 'ref-9', null, MarkerSupport::UNAVAILABLE ),
				$this->context()
			),
			'the plugin refuses to guess which unbound resource is its own'
		);
	}

	public function test_case_e_an_absent_marker_also_requires_adoption(): void {
		$this->assertSame(
			CreateRecovery::ADOPT_REQUIRED,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::MISMATCH, 'ref-9', null, MarkerSupport::SUPPORTED ),
				$this->context()
			)
		);
	}

	public function test_case_f_a_foreign_marker_is_unowned(): void {
		$this->assertSame(
			CreateRecovery::UNOWNED,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::MISMATCH,
					'ref-9',
					new ProviderMarker( 'other-install', 12, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context()
			)
		);
	}

	public function test_an_incomplete_or_transient_read_waits(): void {
		$this->assertSame(
			CreateRecovery::WAIT,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, false ),
				$this->context()
			)
		);
		$this->assertSame(
			CreateRecovery::WAIT,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, true, true ),
				$this->context()
			)
		);
	}

	public function test_recovery_never_applies_once_a_reference_is_bound(): void {
		$this->assertSame(
			CreateRecovery::WAIT,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::RECOVERABLE_CREATE,
					'ref-9',
					new ProviderMarker( 'install-a', 12, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context( 'ref-1' )
			),
			'a bound reference uses the strict MATCH rule, not recovery'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter CreateRecoveryTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\CreateRecovery" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/CreateRecovery.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Resolves an ambiguous first create by reading, never by repeating the POST.
 * Only one outcome binds a reference without an explicit adoption, and it needs
 * a marker naming this installation AND this mapping.
 */
final class CreateRecovery {

	public const BIND           = 'bind';
	public const RETRY          = 'retry';
	public const ADOPT_REQUIRED = 'adopt_required';
	public const UNOWNED        = 'unowned';
	public const WAIT           = 'wait';

	public static function decide( IdentityResult $identity, SslResourceContext $ctx ): string {
		if ( ! $identity->read_complete || $identity->transient ) {
			return self::WAIT;
		}

		if ( null !== $ctx->provider_ref ) {
			// Already bound: the strict MATCH rule applies, not recovery.
			return self::WAIT;
		}

		if ( IdentityVerdict::ABSENT === $identity->verdict ) {
			return self::RETRY;
		}

		if ( $identity->is_recoverable_create( $ctx->installation_id, $ctx->mapping_id, $ctx->host ) ) {
			return self::BIND;
		}

		if ( null !== $identity->marker ) {
			// A marker that does not name this installation and mapping.
			return self::UNOWNED;
		}

		return self::ADOPT_REQUIRED;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter CreateRecoveryTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/CreateRecovery.php tests/unit/Ssl/CreateRecoveryTest.php
git commit -m "Resolve ambiguous creates by reading, and refuse to guess

Only a marker naming this installation and this mapping binds a reference. With
markers unavailable the operation stops and asks for explicit adoption rather
than claiming a resource it cannot identify."
```

---

### Task 2: Provisioning

**Files:**
- Create: `src/Ssl/CreateAuthorizer.php`, `src/Ssl/CreateService.php`
- Test: `tests/integration/Ssl/CreateServiceTest.php`

**Interfaces:**
- Consumes: `AuthorizerSupport`, `MutationGate`, `MutationLease`, `DriverFactory`, `MutationResult`, `TimingPolicy` (Plan 07); `AtomicTransition` (Plan 02); `FreshProof` (Plan 06); `CreateRecovery` (Task 1).
- Produces:
  - `PostDomain\Ssl\CreateAuthorizer::authorize( Mapping $m ): array{auth: MutationAuthorization, context: SslResourceContext, driver: SslDriver, lease: array{token: string, revision: int}, mapping: Mapping}|MutationRefusal`.
  - `PostDomain\Ssl\CreateService::__construct( MappingRepository $repo, CreateAuthorizer $authorizer, MutationLease $lease, MutationGate $gate, Clock $clock )` with `::provision( Mapping $m ): MutationResult` and the static test helper `::for_tests( SslDriver $driver, FreshProof $proof ): self`.

Create runs the **same** precondition set as every other operation, minus the
bound-identity requirement (the reference may legitimately be null) and plus the
method-capability check.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/CreateServiceTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class CreateServiceTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
	}

	private function proof( DnsOutcome $outcome ): FreshProof {
		return new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);
	}

	private function mapping(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				null, null, 'recording', null
			)
		);
	}

	private function assert_released( int $id ): void {
		$this->assertNull( $this->repo->by_id( $id )?->ssl_mutation_token );
	}

	public function test_a_successful_create_binds_the_reference_and_records_provenance(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( SslState::REQUESTED, $after?->ssl_state );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertSame( OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( Environment::installation_id(), $after?->ssl_owner_installation_id );
		$this->assertSame( 'recording', $after?->ssl_provider );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_environment_unresolved_refuses_without_calling_the_provider(): void {
		update_option( 'pd_environment_mismatch', array( 'stored' => 'a', 'current' => 'b' ), false );

		$driver = RecordingDriver::succeeding( 'ref-1' );
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assert_released( $m->id );
	}

	public function test_a_failed_fresh_proof_refuses_and_releases(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::NO_RECORD ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'fresh_proof_failed', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->create_calls, 'cached verification is not a fresh proof' );
		$this->assert_released( $m->id );
	}

	public function test_a_transient_proof_refuses_transiently(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::TRANSIENT ) )
			->provision( $this->mapping() );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertTrue( $result->refusal?->transient );
		$this->assertSame( 0, $driver->create_calls );
	}

	public function test_an_incomplete_identity_read_refuses(): void {
		$driver = RecordingDriver::with_incomplete_identity();
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assert_released( $m->id );
	}

	public function test_a_conflicting_marker_refuses(): void {
		$driver = RecordingDriver::with_foreign_marker();
		$m      = $this->mapping();

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'conflicting_marker', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->create_calls );
	}

	public function test_a_second_concurrent_provision_sends_no_second_post(): void {
		$driver  = RecordingDriver::succeeding( 'ref-1' );
		$service = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) );
		$m       = $this->mapping();

		$service->provision( $m );
		$second = $service->provision( $m );

		$this->assertSame( MutationDisposition::REFUSED, $second->disposition );
		$this->assertSame( 1, $driver->create_calls, 'exactly one POST' );
	}

	public function test_an_ambiguous_create_with_a_matching_marker_binds_without_adoption(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_marked( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'ref-9', $after?->ssl_ref );
		$this->assertSame(
			OwnershipOrigin::CREATED,
			$after?->ssl_ownership_origin,
			'a recovered create is created, not adopted'
		);
		$this->assertNull( $after?->ssl_adopted_at );
	}

	public function test_an_ambiguous_create_without_markers_requires_adoption(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( SslState::FAILED, $after?->ssl_state );
		$this->assertNull( $after?->ssl_ref, 'nothing is bound without an explicit adoption' );
		$this->assertStringContainsString( 'provider_create_ambiguous', (string) $after?->ssl_error );
	}

	public function test_an_ambiguous_create_with_a_foreign_marker_is_unowned(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_foreign( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertStringContainsString( 'unowned_resource', (string) $after?->ssl_error );
		$this->assertNull( $after?->ssl_ref );
	}

	public function test_a_conclusive_absence_leaves_the_mapping_retryable(): void {
		$m = $this->mapping();

		CreateService::for_tests( RecordingDriver::ambiguous_then_absent(), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_mutation_token, 'the lease is released so a retry can acquire' );
		$this->assertNull( $after?->ssl_ref );
	}

	public function test_the_post_is_never_repeated_after_an_ambiguous_outcome(): void {
		$driver = RecordingDriver::ambiguous_then_absent();

		CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $this->mapping() );

		$this->assertSame( 1, $driver->create_calls );
		$this->assertGreaterThanOrEqual( 2, $driver->identify_calls, 'a read precedes any retry' );
	}

	public function test_a_successful_create_reports_a_committed_result(): void {
		$result = CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $this->mapping() );

		$this->assertSame( MutationDisposition::COMMITTED, $result->disposition );
		$this->assertNotNull( $result->status );
	}

	public function test_an_ambiguous_create_is_reported_as_retained_not_successful(): void {
		$result = CreateService::for_tests( RecordingDriver::ambiguous_then_absent(), $this->proof( DnsOutcome::MATCH ) )
			->provision( $this->mapping() );

		$this->assertSame( MutationDisposition::AMBIGUOUS_RETAINED, $result->disposition );
		$this->assertNull( $result->status, 'nothing here is confirmed enough to report as a status' );
	}

	public function test_a_refusal_carries_no_status(): void {
		$result = CreateService::for_tests(
			RecordingDriver::succeeding( 'ref-1' ),
			$this->proof( DnsOutcome::NO_RECORD )
		)->provision( $this->mapping() );

		$this->assertNull( $result->status );
		$this->assertFalse( $result->succeeded() );
	}

	public function test_a_fenced_worker_writes_nothing(): void {
		global $wpdb;

		$m      = $this->mapping();
		$driver = RecordingDriver::succeeding( 'ref-1' );

		// Fence the worker mid-flight by replacing the lease token as the driver runs.
		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array( 'ssl_mutation_token' => str_repeat( '7', 32 ) ),
					array( 'id' => $m->id )
				);
			}
		);

		$result = CreateService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->provision( $m );

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_ref, 'a fenced worker must not apply its result' );
		$this->assertNull( $after?->ssl_ownership_origin );

		// The provider succeeded. The mutation did not. Those are different facts
		// and the caller has to be able to tell them apart.
		$this->assertSame( MutationDisposition::FENCED, $result->disposition );
		$this->assertNull( $result->status );
		$this->assertFalse( $result->succeeded() );

		remove_all_actions( 'pd_test_after_provider_call' );
		unset( $wpdb );
	}

	public function test_a_fenced_worker_records_no_success_event(): void {
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'ssl_mutation_token' => str_repeat( '7', 32 ),
						'ssl_mutation_phase' => 'recovering',
					),
					array( 'id' => $m->id )
				);
			}
		);

		CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->provision( $m );

		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'created' === $e['to_state']
			),
			'no history for work that was discarded'
		);

		remove_all_actions( 'pd_test_after_provider_call' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CreateServiceTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\CreateService" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/CreateAuthorizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class CreateAuthorizer {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver, lease: array{token: string, revision: int}, mapping: Mapping}|MutationRefusal
	 */
	public function authorize( Mapping $mapping ) {
		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::CREATE
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver  = $window['driver'];
		$context = $window['context'];
		$held    = $window['lease'];
		$leased  = $window['mapping'];

		// The reference may legitimately be null here, so a bound match is not
		// required — but the read must still be complete and unconflicted.
		$identity_refusal = AuthorizerSupport::check_identity( $driver, $context, false );

		if ( null !== $identity_refusal ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::CREATE,
				$identity_refusal->precondition, $identity_refusal->transient
			);
		}

		$method = $leased->ssl_method ?? Credentials::ssl_method();

		if ( array() !== $driver->capabilities()->validation_methods
			&& ! in_array( $method, $driver->capabilities()->validation_methods, true ) ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::CREATE, 'method_unsupported', false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::CREATE, 'fresh_proof_transient', true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::CREATE, 'fresh_proof_failed', false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'  => $driver,
			'context' => $context,
			'lease'   => $held,
			'mapping' => $leased,
			'auth'    => new MutationAuthorization(
				MutationOperation::CREATE,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::CREATE ),
				false,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
```

Create `src/Ssl/CreateService.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class CreateService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly CreateAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$repo  = new DbRepository();

		// Production resolves drivers through DriverFactory, so tests install
		// theirs the same way a site would rather than injecting a registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new self(
			$repo,
			new CreateAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock ),
			$clock
		);
	}

	public function provision( Mapping $mapping ): MutationResult {
		$authorized = $this->authorizer->authorize( $mapping );

		if ( $authorized instanceof MutationRefusal ) {
			return MutationResult::refused( $authorized );
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return MutationResult::refused( $gated );
		}

		/** For the fencing race test: fires after the provider call, before finalize. */
		do_action( 'pd_test_after_provider_call' );

		/** @var SslStatus $status */
		$status = $gated->result;

		if ( ! $status->transient && null !== $status->ref ) {
			return $this->apply(
				$authorized,
				$gated,
				LeaseOutcome::bound(
					$status->state,
					$status->ref,
					$authorized['driver']->id(),
					OwnershipOrigin::CREATED,
					$authorized['context']->installation_id
				),
				$status,
				'created'
			);
		}

		// Ambiguous: read before considering anything else. Never a second POST.
		$identity = $authorized['driver']->identify( $authorized['context'] );
		$decision = CreateRecovery::decide( $identity, $authorized['context'] );

		$outcome = match ( $decision ) {
			CreateRecovery::BIND           => LeaseOutcome::bound(
				SslState::REQUESTED,
				(string) $identity->observed_ref,
				$authorized['driver']->id(),
				OwnershipOrigin::CREATED,
				$authorized['context']->installation_id
			),
			CreateRecovery::ADOPT_REQUIRED => LeaseOutcome::failure(
				SslState::FAILED,
				'provider_create_ambiguous',
				'A resource may exist for this hostname; adopt it explicitly.'
			),
			CreateRecovery::UNOWNED        => LeaseOutcome::failure(
				SslState::FAILED,
				'unowned_resource',
				'A resource exists carrying a marker from another installation.'
			),
			default                        => LeaseOutcome::checked(),
		};

		$applied = $this->apply( $authorized, $gated, $outcome, $status, $decision );

		if ( ! $applied->succeeded() ) {
			return $applied;
		}

		// A recovered create is a completed mutation; every other ambiguous
		// decision leaves the truth with the provider, so say so rather than
		// dressing it up as a success.
		return CreateRecovery::BIND === $decision
			? $applied
			: MutationResult::ambiguous( $decision );
	}

	/**
	 * Applies the outcome and its event as one transition, and reports precisely
	 * what became of the attempt.
	 *
	 * @param array{auth: MutationAuthorization, context: SslResourceContext, driver: SslDriver, lease: array{token: string, revision: int}, mapping: Mapping} $authorized
	 */
	private function apply(
		array $authorized,
		GateResult $gated,
		LeaseOutcome $outcome,
		SslStatus $status,
		string $note
	): MutationResult {
		$mapping_id = $authorized['mapping']->id;

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize(
				$mapping_id,
				$gated->in_flight_revision,
				$gated->lease_token,
				MutationKind::CREATE,
				MutationPhase::IN_FLIGHT,
				$outcome
			),
			fn (): bool => EventLog::record(
				$mapping_id,
				$authorized['mapping']->host,
				'ssl',
				null,
				$note,
				'cron',
				array( 'create' => $note )
			)
		);

		if ( $applied ) {
			return MutationResult::committed( $status, $note );
		}

		// Fenced or simply not persisted — the row itself says which.
		return MutationResult::lost( $this->repo->by_id( $mapping_id ), $gated->lease_token );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CreateServiceTest`
Expected: PASS — 17 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/CreateAuthorizer.php src/Ssl/CreateService.php tests/integration/Ssl/CreateServiceTest.php
git commit -m "Provision under the full precondition set and settle ambiguity by reading

Create now proves the challenge live and reads identity before the POST, like
every other operation. A timeout is followed by a provider read, never a second
POST, and a fenced worker applies nothing."
```

---

### Task 3: Explicit adoption

**Files:**
- Create: `src/Ssl/AdoptionAuthorizer.php`, `src/Ssl/AdoptionService.php`
- Test: `tests/integration/Ssl/AdoptionTest.php`

**Interfaces:**
- Consumes: Plan 07's authorization machinery, `FreshProof` (Plan 06).
- Produces: `AdoptionAuthorizer::authorize( Mapping $m, array $request )` and `AdoptionService::take_ownership( Mapping $m, array $request ): MutationResult`, plus `AdoptionService::for_tests()`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/AdoptionTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class AdoptionTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function proof( DnsOutcome $outcome ): FreshProof {
		return new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);
	}

	private function mapping(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::FAILED,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				null, null, 'recording', null
			)
		);
	}

	public function test_adoption_with_confirmation_and_proof_succeeds(): void {
		$m = $this->mapping();

		AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( OwnershipOrigin::ADOPTED, $after?->ssl_ownership_origin );
		$this->assertSame( Environment::installation_id(), $after?->ssl_owner_installation_id );
		$this->assertSame( 'ref-9', $after?->ssl_ref );
		$this->assertNotNull( $after?->ssl_adopted_at );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_adoption_without_confirmation_is_refused(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array() );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'confirmation_required', $result->refusal?->precondition );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_adoption_without_a_fresh_proof_is_refused(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MISMATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'fresh_proof_failed', $result->refusal?->precondition );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_a_foreign_marker_needs_the_second_key(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_foreign( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'foreign_marker_override_required', $result->refusal?->precondition );
	}

	public function test_a_foreign_marker_can_be_overridden_deliberately(): void {
		$m = $this->mapping();

		AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_foreign( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true, 'override_foreign_marker' => true ) );

		$this->assertSame( OwnershipOrigin::ADOPTED, $this->repo->by_id( $m->id )?->ssl_ownership_origin );
	}

	public function test_an_absent_provider_resource_cannot_be_adopted(): void {
		$m      = $this->mapping();
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_absent(),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'identity_not_confirmed', $result->refusal?->precondition );
	}

	public function test_a_fenced_adoption_claims_nothing(): void {
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'ssl_mutation_token' => str_repeat( '7', 32 ),
						'ssl_mutation_phase' => 'recovering',
					),
					array( 'id' => $m->id )
				);
			}
		);

		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->take_ownership( $m, array( 'confirm' => true ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( MutationDisposition::FENCED, $result->disposition );
		$this->assertNull( $after?->ssl_ownership_origin, 'ownership is exactly what must not survive a lost CAS' );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'adopted' === $e['to_state']
			)
		);

		remove_all_actions( 'pd_test_after_provider_call' );
	}

	public function test_provisioning_never_adopts(): void {
		$m = $this->mapping();

		CreateService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->provision( $m );

		$this->assertNull(
			$this->repo->by_id( $m->id )?->ssl_ownership_origin,
			'finding a duplicate never adopts it'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter AdoptionTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\AdoptionService" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/AdoptionAuthorizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class AdoptionAuthorizer {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @param array{confirm?: bool, override_foreign_marker?: bool} $request
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver, lease: array{token: string, revision: int}, mapping: Mapping, observed_ref: string}|MutationRefusal
	 */
	public function authorize( Mapping $mapping, array $request ) {
		if ( true !== ( $request['confirm'] ?? false ) ) {
			return new MutationRefusal( 'confirmation_required', false );
		}

		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::ADOPT
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver   = $window['driver'];
		$context  = $window['context'];
		$held     = $window['lease'];
		$leased   = $window['mapping'];
		$identity = $driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::ADOPT, 'identity_incomplete', true
			);
		}

		if ( null === $identity->observed_ref || $identity->observed_hostname !== $leased->host ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::ADOPT, 'identity_not_confirmed', false
			);
		}

		$override = true === ( $request['override_foreign_marker'] ?? false );

		if ( $identity->has_conflicting_marker( $context->installation_id, $leased->id ) && ! $override ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::ADOPT, 'foreign_marker_override_required', false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::ADOPT, 'fresh_proof_transient', true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::ADOPT, 'fresh_proof_failed', false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'       => $driver,
			'context'      => $context,
			'lease'        => $held,
			'mapping'      => $leased,
			'observed_ref' => $identity->observed_ref,
			'auth'         => new MutationAuthorization(
				MutationOperation::ADOPT,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::ADOPT ),
				$override,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
```

Create `src/Ssl/AdoptionService.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

/**
 * The public method is `take_ownership()`, not `adopt()`: `adopt` is a driver
 * method name, and the enforcement scan in Plan 07 flags that name anywhere
 * outside MutationGate. A service that borrowed it would make the scan noisy
 * exactly where it needs to be precise.
 */
final class AdoptionService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly AdoptionAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$repo  = new DbRepository();

		// Production resolves drivers through DriverFactory, so tests install
		// theirs the same way a site would rather than injecting a registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new self(
			$repo,
			new AdoptionAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock ),
			$clock
		);
	}

	/**
	 * @param array{confirm?: bool, override_foreign_marker?: bool} $request
	 */
	public function take_ownership( Mapping $mapping, array $request ): MutationResult {
		$authorized = $this->authorizer->authorize( $mapping, $request );

		if ( $authorized instanceof MutationRefusal ) {
			return MutationResult::refused( $authorized );
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return MutationResult::refused( $gated );
		}

		do_action( 'pd_test_after_provider_call' );

		/** @var SslStatus $status */
		$status = $gated->result;

		$mapping_id = $authorized['mapping']->id;
		$actor      = 'admin:' . get_current_user_id();

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize(
				$mapping_id,
				$gated->in_flight_revision,
				$gated->lease_token,
				MutationKind::ADOPT,
				MutationPhase::IN_FLIGHT,
				LeaseOutcome::adopted(
					$status->state,
					$authorized['observed_ref'],
					$authorized['driver']->id(),
					$authorized['context']->installation_id,
					get_current_user_id()
				)
			),
			fn (): bool => EventLog::record(
				$mapping_id,
				$authorized['mapping']->host,
				'ssl',
				null,
				'adopted',
				$actor,
				array( 'observed_ref' => $authorized['observed_ref'] )
			)
		);

		// Claiming ownership is exactly the write that must not survive a lost
		// CAS, and no event may say it happened.
		return $applied
			? MutationResult::committed( $status, 'adopted' )
			: MutationResult::lost( $this->repo->by_id( $mapping_id ), $gated->lease_token );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter AdoptionTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/AdoptionAuthorizer.php src/Ssl/AdoptionService.php tests/integration/Ssl/AdoptionTest.php
git commit -m "Adopt provider resources only on an explicit, proven request

Confirmation plus a fresh DNS proof, and a second key for a foreign marker.
Provisioning never reaches this path, which a test asserts directly."
```

---

### Task 4: Validation-method change

**Files:**
- Create: `src/Ssl/MethodChangeAuthorizer.php`, `src/Ssl/MethodChangeService.php`
- Test: `tests/integration/Ssl/MethodChangeTest.php`

**Interfaces:**
- Consumes: Plan 07 machinery.
- Produces: `MethodChangeAuthorizer::METHODS` (`http`, `txt`, `email`), `::authorize( Mapping $m, string $method )`, and `MethodChangeService::change( Mapping $m, string $method ): MutationResult`, plus `::for_tests()`.

Local persistence happens **only after** the provider's resulting method is
confirmed by a re-read (spec §14.10).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/MethodChangeTest.php`:

```php
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
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MethodChangeService;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class MethodChangeTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
	}

	private function proof( DnsOutcome $outcome ): FreshProof {
		return new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);
	}

	private function mapping( bool $owned = true ): Mapping {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				$owned ? OwnershipOrigin::CREATED : null,
				$owned ? Environment::installation_id() : null,
				'recording',
				$owned ? 'ref-1' : null
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_method' => 'txt' ),
			array( 'id' => $m->id )
		);

		return $this->repo->by_id( $m->id );
	}

	public function test_a_confirmed_change_is_persisted(): void {
		$m = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'http' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $m, 'http' );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'http', $after?->ssl_method );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_an_unconfirmed_change_leaves_the_local_method_alone(): void {
		$m = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'txt' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $m, 'http' );

		$this->assertSame(
			'txt',
			$this->repo->by_id( $m->id )?->ssl_method,
			'local state follows the provider, not the request'
		);
	}

	public function test_an_unsupported_method_is_refused_without_calling_the_provider(): void {
		$driver = RecordingDriver::confirming_method( 'http' );
		$m      = $this->mapping();

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )->change( $m, 'email' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'method_unsupported', $result->refusal?->precondition );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_an_invalid_method_is_refused(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'carrier-pigeon' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
	}

	public function test_a_failed_fresh_proof_refuses_and_releases(): void {
		$driver = RecordingDriver::confirming_method( 'http' );
		$m      = $this->mapping();

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::NO_RECORD ) )->change( $m, 'http' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_no_ownership_authority_refuses(): void {
		$m      = $this->mapping( false );
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $m, 'http' );

		$this->assertSame( MutationDisposition::REFUSED, $result->disposition );
		$this->assertSame( 'no_ownership_authority', $result->refusal?->precondition );
	}

	public function test_a_confirmed_change_reports_a_committed_result(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'http' );

		$this->assertSame( MutationDisposition::COMMITTED, $result->disposition );
	}

	public function test_an_unconfirmed_change_is_reported_as_ambiguous(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'txt' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'http' );

		$this->assertSame(
			MutationDisposition::AMBIGUOUS_RETAINED,
			$result->disposition,
			'the provider did not do what was asked; that is not success'
		);
	}

	public function test_a_fenced_worker_does_not_persist_the_method(): void {
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array( 'ssl_mutation_token' => str_repeat( '7', 32 ) ),
					array( 'id' => $m->id )
				);
			}
		);

		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $m, 'http' );

		$this->assertSame( 'txt', $this->repo->by_id( $m->id )?->ssl_method );
		$this->assertSame( MutationDisposition::FENCED, $result->disposition );
		$this->assertNull( $result->status );

		remove_all_actions( 'pd_test_after_provider_call' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter MethodChangeTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\MethodChangeService" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/MethodChangeAuthorizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class MethodChangeAuthorizer {

	public const METHODS = array( 'http', 'txt', 'email' );

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver, lease: array{token: string, revision: int}, mapping: Mapping}|MutationRefusal
	 */
	public function authorize( Mapping $mapping, string $method ) {
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return new MutationRefusal( 'method_unsupported', false );
		}

		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->lease,
			$mapping,
			MutationOperation::CHANGE_METHOD
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver  = $window['driver'];
		$context = $window['context'];
		$held    = $window['lease'];
		$leased  = $window['mapping'];

		if ( ! in_array( $method, $driver->capabilities()->validation_methods, true ) ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::METHOD, 'method_unsupported', false
			);
		}

		$identity_refusal = AuthorizerSupport::check_identity( $driver, $context, true );

		if ( null !== $identity_refusal ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::METHOD,
				$identity_refusal->precondition, $identity_refusal->transient
			);
		}

		if ( ! $context->has_ownership_authority() ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::METHOD, 'no_ownership_authority', false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::METHOD, 'fresh_proof_transient', true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::METHOD, 'fresh_proof_failed', false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'  => $driver,
			'context' => $context,
			'lease'   => $held,
			'mapping' => $leased,
			'auth'    => new MutationAuthorization(
				MutationOperation::CHANGE_METHOD,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::METHOD ),
				false,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
```

Create `src/Ssl/MethodChangeService.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class MethodChangeService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly MethodChangeAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$repo  = new DbRepository();

		// Production resolves drivers through DriverFactory, so tests install
		// theirs the same way a site would rather than injecting a registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new self(
			$repo,
			new MethodChangeAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock ),
			$clock
		);
	}

	public function change( Mapping $mapping, string $method ): MutationResult {
		$authorized = $this->authorizer->authorize( $mapping, $method );

		if ( $authorized instanceof MutationRefusal ) {
			return MutationResult::refused( $authorized );
		}

		$gated = $this->gate->execute(
			$authorized['driver'],
			$authorized['context'],
			$authorized['auth'],
			$method
		);

		if ( $gated instanceof MutationRefusal ) {
			return MutationResult::refused( $gated );
		}

		do_action( 'pd_test_after_provider_call' );

		/** @var SslStatus $status */
		$status    = $gated->result;
		$confirmed = $status->confirmed_method === $method;

		// Persist only what the provider's own re-read confirms.
		$outcome = $confirmed
			? LeaseOutcome::method_confirmed( $method )
			: LeaseOutcome::checked();

		$mapping_id = $authorized['mapping']->id;

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize(
				$mapping_id,
				$gated->in_flight_revision,
				$gated->lease_token,
				MutationKind::METHOD,
				MutationPhase::IN_FLIGHT,
				$outcome
			),
			fn (): bool => EventLog::record(
				$mapping_id,
				$authorized['mapping']->host,
				'ssl',
				$authorized['mapping']->ssl_method,
				$status->confirmed_method,
				'admin:' . get_current_user_id(),
				array( 'requested' => $method, 'confirmed' => $status->confirmed_method )
			)
		);

		if ( ! $applied ) {
			return MutationResult::lost( $this->repo->by_id( $mapping_id ), $gated->lease_token );
		}

		// The lease released cleanly, but the provider did not confirm the change
		// it was asked for. That is not a completed method change.
		return $confirmed
			? MutationResult::committed( $status, 'method_changed' )
			: MutationResult::ambiguous( 'the provider did not confirm the requested method' );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter MethodChangeTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/MethodChangeAuthorizer.php src/Ssl/MethodChangeService.php tests/integration/Ssl/MethodChangeTest.php
git commit -m "Change the DCV method as a first-class operation, confirmed by re-read

Local state follows the provider rather than the request: an unconfirmed change
leaves ssl_method exactly as it was, and a fenced worker persists nothing."
```

---

### Task 5: Durable deletion and force-local-delete

**Files:**
- Create: `src/Ssl/DeletionService.php`, `src/Ssl/ForceLocalDelete.php`
- Test: `tests/integration/Ssl/DeletionServiceTest.php`

**Interfaces:**
- Consumes: `DeletionAuthorizer`, `MutationGate`, `MutationLease` (Plan 07), `MappingRepository` (Plan 02).
- Produces:
  - `DeletionService::request( Mapping $m ): bool` — a CAS write on the expected revision that stops serving and, when a provider resource exists, moves to `pending_removal`; when none exists it takes a `RESERVED` lease and deletes under it.
  - `DeletionService::process( Mapping $m ): string` — `removed`, `pending`, `transient`, `failed`, `refused`, or `fenced`.
  - `ForceLocalDelete::run( Mapping $m ): bool`.

Every deletion path is fenced by the exact lease. A failed finalize means the
worker was fenced and must **not** delete (spec §14.15).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/DeletionServiceTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DeletionService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\ForceLocalDelete;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class DeletionServiceTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
	}

	private function proof( DnsOutcome $outcome ): FreshProof {
		return new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);
	}

	private function owned(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'recording', 'ref-1'
			)
		);
	}

	private function force_lease( int $id, string $phase, int $offset ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '5', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => $phase,
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
			),
			array( 'id' => $id )
		);
	}

	public function test_requesting_deletion_stops_serving_under_a_cas(): void {
		$m = $this->owned();

		$this->assertTrue(
			DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) )
				->request( $m )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( ActivationState::INACTIVE, $after?->activation_state );
		$this->assertSame( SslState::PENDING_REMOVAL, $after?->ssl_state );
		$this->assertNotNull( $after?->deletion_requested_at );
	}

	public function test_requesting_deletion_on_a_stale_revision_fails(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		$this->assertFalse( $service->request( $m ), 'the second request carries a stale revision' );
	}

	public function test_the_local_row_survives_until_the_provider_confirms(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::PENDING ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$this->assertNotNull( $this->repo->by_id( $m->id ), 'not deleted until cleanup succeeds' );
	}

	public function test_a_confirmed_removal_deletes_the_row_and_keeps_the_event(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$this->assertSame( 'removed', $service->process( $this->repo->by_id( $m->id ) ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );

		$events = EventLog::for_domain( $m->id );

		$this->assertNotEmpty( $events );
		$this->assertSame( 'mapped.test', end( $events )['host'] );
	}

	public function test_a_transient_removal_does_not_increment_attempts(): void {
		global $wpdb;

		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::TRANSIENT ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$this->assertSame(
			0,
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( 'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d', $m->id )
			)
		);
	}

	public function test_a_failed_removal_increments_attempts_and_keeps_the_row(): void {
		global $wpdb;

		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::FAILED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$this->assertSame(
			1,
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( 'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d', $m->id )
			)
		);
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}

	public function test_a_fenced_worker_does_not_hard_delete(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array( 'ssl_mutation_token' => str_repeat( '9', 32 ) ),
					array( 'id' => $m->id )
				);
			}
		);

		$outcome = $service->process( $this->repo->by_id( $m->id ) );

		$this->assertSame( 'fenced', $outcome );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'recovery owns this row now' );
		$this->assertSame(
			array(),
			array_filter(
				EventLog::for_domain( $m->id ),
				static fn( array $e ): bool => 'deleted' === $e['to_state']
			),
			'no deletion event for a deletion that did not happen'
		);

		remove_all_actions( 'pd_test_after_provider_call' );
	}

	public function test_a_confirmed_removal_records_its_event_only_with_the_delete(): void {
		$m       = $this->owned();
		$service = DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) );

		$service->request( $m );
		$service->process( $this->repo->by_id( $m->id ) );

		$deleted = array_filter(
			EventLog::for_domain( $m->id ),
			static fn( array $e ): bool => 'deleted' === $e['to_state']
		);

		$this->assertCount( 1, $deleted );
		$this->assertSame( 'mapped.test', reset( $deleted )['host'], 'the host snapshot outlives the row' );
	}

	public function test_a_local_delete_that_loses_its_cas_records_nothing(): void {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0, 'plain.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'e', 32 ), '_post-domain-challenge'
			)
		);

		$stale = $this->repo->by_id( $m->id );

		// Someone bumps the revision between our read and our acquire.
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'revision' => $stale->revision + 3 ),
			array( 'id' => $m->id )
		);

		$service = DeletionService::for_tests( new NullDriver(), $this->proof( DnsOutcome::MATCH ) );

		$this->assertFalse( $service->request( $stale ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
		$this->assertSame( array(), EventLog::for_domain( $m->id ) );
	}

	public function test_a_mapping_with_no_provider_resource_deletes_under_its_own_lease(): void {
		$m = $this->repo->save(
			new Mapping(
				0, 'plain.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge'
			)
		);

		$service = DeletionService::for_tests( new NullDriver(), $this->proof( DnsOutcome::MATCH ) );

		$this->assertTrue( $service->request( $m ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}

	/**
	 * @dataProvider lease_states
	 */
	public function test_a_local_delete_cannot_race_a_prepared_mutation( string $phase, int $offset ): void {
		$m = $this->repo->save(
			new Mapping(
				0, 'plain.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'd', 32 ), '_post-domain-challenge'
			)
		);

		$this->force_lease( $m->id, $phase, $offset );

		$service = DeletionService::for_tests( new NullDriver(), $this->proof( DnsOutcome::MATCH ) );

		$this->assertFalse( $service->request( $this->repo->by_id( $m->id ) ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}

	public function test_force_local_delete_removes_the_row_and_records_the_orphan(): void {
		$m = $this->owned();

		$this->assertTrue( ForceLocalDelete::run( $m ) );
		$this->assertNull( $this->repo->by_id( $m->id ) );

		$events = EventLog::for_domain( $m->id );

		$this->assertStringContainsString( 'provider_resource_may_remain', (string) end( $events )['detail'] );
	}

	public function test_force_local_delete_issues_no_provider_deletion(): void {
		$driver = RecordingDriver::removing( RemovalOutcome::REMOVED );

		ForceLocalDelete::run( $this->owned() );

		$this->assertSame( 0, $driver->remove_calls );
	}

	/**
	 * @dataProvider lease_states
	 */
	public function test_force_local_delete_cannot_overwrite_any_lease( string $phase, int $offset ): void {
		$m = $this->owned();
		$this->force_lease( $m->id, $phase, $offset );

		$this->assertFalse( ForceLocalDelete::run( $this->repo->by_id( $m->id ) ) );
		$this->assertNotNull( $this->repo->by_id( $m->id ) );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function lease_states(): array {
		return array(
			'reserved unexpired'   => array( 'reserved', 600 ),
			'reserved expired'     => array( 'reserved', -600 ),
			'in flight unexpired'  => array( 'in_flight', 600 ),
			'in flight expired'    => array( 'in_flight', -600 ),
			'recovering unexpired' => array( 'recovering', 600 ),
			'recovering expired'   => array( 'recovering', -600 ),
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter DeletionServiceTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\DeletionService" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/ForceLocalDelete.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;

/**
 * Removes the local row only. Issues no provider deletion, and cannot start from
 * a row carrying any lease — including an expired one, which belongs to recovery.
 */
final class ForceLocalDelete {

	public static function run( Mapping $mapping ): bool {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$held  = $lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE );

		if ( null === $held ) {
			return false;
		}

		// The host snapshot is captured before the row can vanish, and the event
		// is written inside the same transaction as the delete.
		$host  = $mapping->host;
		$id    = $mapping->id;
		$actor = 'admin:' . get_current_user_id();

		$deleted = AtomicTransition::commit(
			static fn (): bool => $lease->delete_row(
				$id,
				$held['revision'],
				$held['token'],
				MutationKind::REMOVE,
				MutationPhase::RESERVED
			),
			static fn (): bool => EventLog::record(
				$id,
				$host,
				'ssl',
				null,
				'force_deleted',
				$actor,
				array( 'note' => 'provider_resource_may_remain' )
			)
		);

		if ( ! $deleted ) {
			$lease->release_reserved( $mapping->id, $held['revision'], $held['token'], MutationKind::REMOVE );

			return false;
		}

		return true;
	}
}
```

Create `src/Ssl/DeletionService.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class DeletionService {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly DeletionAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$repo  = new DbRepository();

		// Production resolves drivers through DriverFactory, so tests install
		// theirs the same way a site would rather than injecting a registry.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new self(
			$repo,
			new DeletionAuthorizer( $repo, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease, $clock ),
			$clock
		);
	}

	/** Stops serving under a CAS, then either schedules removal or deletes locally. */
	public function request( Mapping $mapping ): bool {
		global $wpdb;

		$holds_resource = null !== $mapping->ssl_ref && 'null' !== ( $mapping->ssl_provider ?? 'null' );

		if ( ! $holds_resource ) {
			// Nothing external exists, but the delete must still not race a
			// mutation someone else is preparing.
			return $this->delete_locally( $mapping );
		}

		$table = Schema::domains_table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET deletion_requested_at = %s, activation_state = %s, ssl_state = %s,
				        deletion_next_attempt_at = %s, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
				$this->clock->mysql(),
				ActivationState::INACTIVE->value,
				SslState::PENDING_REMOVAL->value,
				$this->clock->mysql(),
				$this->clock->mysql(),
				$mapping->id,
				$mapping->revision
			)
		);

		return 1 === $affected;
	}

	public function process( Mapping $mapping ): string {
		$authorized = $this->authorizer->authorize( $mapping );

		if ( $authorized instanceof MutationRefusal ) {
			if ( ! $authorized->transient ) {
				$this->bump_attempts( $mapping );
			}

			return 'refused';
		}

		$gated = $this->gate->execute( $authorized['driver'], $authorized['context'], $authorized['auth'] );

		if ( $gated instanceof MutationRefusal ) {
			return 'refused';
		}

		do_action( 'pd_test_after_provider_call' );

		/** @var RemovalResult $result */
		$result = $gated->result;

		if ( RemovalOutcome::REMOVED === $result->outcome ) {
			// The host is snapshotted now because the row is about to vanish, but
			// the event is written inside the delete's own transaction — never
			// before it. A fenced worker must leave no record of a deletion it
			// did not perform, and must never delete a row recovery now owns.
			$host  = $mapping->host;
			$id    = $mapping->id;
			$from  = $mapping->ssl_state->value;
			$lease = $this->lease;

			$deleted = AtomicTransition::commit(
				static fn (): bool => $lease->delete_row(
					$id,
					$gated->in_flight_revision,
					$gated->lease_token,
					MutationKind::REMOVE,
					MutationPhase::IN_FLIGHT
				),
				static fn (): bool => EventLog::record(
					$id,
					$host,
					'ssl',
					$from,
					'deleted',
					'cron',
					array( 'cleanup' => 'confirmed' )
				)
			);

			return $deleted ? 'removed' : 'fenced';
		}

		$outcome = RemovalOutcome::FAILED === $result->outcome
			? LeaseOutcome::attempted(
				$this->attempts( $mapping ) + 1,
				TimingPolicy::attempt_backoff( $this->attempts( $mapping ) )
			)
			: LeaseOutcome::checked();

		$applied = AtomicTransition::commit(
			fn (): bool => $this->lease->finalize(
				$mapping->id,
				$gated->in_flight_revision,
				$gated->lease_token,
				MutationKind::REMOVE,
				MutationPhase::IN_FLIGHT,
				$outcome
			),
			fn (): bool => EventLog::record(
				$mapping->id,
				$mapping->host,
				'ssl',
				$mapping->ssl_state->value,
				'removal_' . $result->outcome->value,
				'cron',
				array( 'cleanup' => $result->outcome->value )
			)
		);

		if ( ! $applied ) {
			return 'fenced';
		}

		return $result->outcome->value;
	}

	/** Deletes a mapping with no provider resource, under its own RESERVED lease. */
	private function delete_locally( Mapping $mapping ): bool {
		$held = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE );

		if ( null === $held ) {
			return false;
		}

		$id    = $mapping->id;
		$host  = $mapping->host;
		$from  = $mapping->ssl_state->value;
		$actor = 'admin:' . get_current_user_id();
		$lease = $this->lease;

		$deleted = AtomicTransition::commit(
			static fn (): bool => $lease->delete_row(
				$id,
				$held['revision'],
				$held['token'],
				MutationKind::REMOVE,
				MutationPhase::RESERVED
			),
			static fn (): bool => EventLog::record(
				$id,
				$host,
				'ssl',
				$from,
				'deleted',
				$actor,
				array( 'cleanup' => 'no_provider_resource' )
			)
		);

		if ( ! $deleted ) {
			$this->lease->release_reserved( $mapping->id, $held['revision'], $held['token'], MutationKind::REMOVE );
		}

		return $deleted;
	}

	private function attempts( Mapping $mapping ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d',
				$mapping->id
			)
		);
	}

	/** @return bool True only when exactly one row was counted. */
	private function bump_attempts( Mapping $mapping ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		// Unleased and at the revision we read: a refusal that races a real
		// mutation must not inflate that mutation's attempt counter.
		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET deletion_attempts = deletion_attempts + 1, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
				$this->clock->mysql(),
				$mapping->id,
				$mapping->revision
			)
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DeletionServiceTest`
Expected: PASS — 26 tests (including the two six-case lease-state providers)

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/DeletionService.php src/Ssl/ForceLocalDelete.php tests/integration/Ssl/DeletionServiceTest.php
git commit -m "Fence every deletion path with the exact lease

The request is a CAS on the expected revision, a mapping with no provider
resource still takes a lease before deleting, and a fenced worker returns
'fenced' rather than deleting a row recovery now owns."
```

---

### Task 6: Driver-backed recovery and the SSL sweep

**Files:**
- Create: `src/Ssl/DriverRecoveryResolver.php`, `src/Ssl/Reconciler.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Ssl/DriverRecoveryResolverTest.php`, `tests/integration/Ssl/ReconcilerTest.php`

**Interfaces:**
- Consumes: `RecoveryResolver`, `LeaseRecovery`, `CreateRecovery`, `DriverFactory` (Plan 07); `AtomicTransition` (Plan 02).
- Produces:
  - `PostDomain\Ssl\DriverRecoveryResolver` implementing `RecoveryResolver`, dispatching by `MutationKind` and resolving its driver through `DriverFactory`.
  - `PostDomain\Ssl\Reconciler::run( Mapping[] $mappings ): array{updated: int, divergences: int, skipped: int}` — groups mappings by their resolved driver, so a site running more than one provider reconciles correctly.
  - `Plugin::sweep_ssl(): void` wiring recovery and reconciliation through the same factory REST uses.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/DriverRecoveryResolverTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverRecoveryResolver;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\MutationKind;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use WP_UnitTestCase;

final class DriverRecoveryResolverTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
	}

	private function resolver( RecordingDriver $driver ): DriverRecoveryResolver {
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		return new DriverRecoveryResolver();
	}

	private function mapping( ?string $ref = 'ref-1' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::REQUESTED,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				null === $ref ? null : OwnershipOrigin::CREATED,
				null === $ref ? null : Environment::installation_id(),
				'recording',
				$ref
			)
		);
	}

	public function test_create_recovery_binds_a_marker_matched_resource(): void {
		$outcome = $this->resolver( RecordingDriver::ambiguous_then_marked( 'ref-9' ) )
			->resolve( $this->mapping( null ), MutationKind::CREATE, str_repeat( '1', 32 ) );

		$this->assertTrue( $outcome->conclusive );
		$this->assertFalse( $outcome->delete_row );
		$this->assertNotNull( $outcome->apply );
	}

	public function test_create_recovery_with_conclusive_absence_is_conclusive_and_clears(): void {
		$outcome = $this->resolver( RecordingDriver::ambiguous_then_absent() )
			->resolve( $this->mapping( null ), MutationKind::CREATE, str_repeat( '1', 32 ) );

		$this->assertTrue( $outcome->conclusive );
		$this->assertFalse( $outcome->delete_row );
	}

	public function test_create_recovery_without_markers_requires_adoption(): void {
		$outcome = $this->resolver( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) )
			->resolve( $this->mapping( null ), MutationKind::CREATE, str_repeat( '1', 32 ) );

		$this->assertTrue( $outcome->conclusive );
		$this->assertStringContainsString( 'adopt', $outcome->note );
	}

	public function test_an_incomplete_read_is_inconclusive_for_every_kind(): void {
		foreach ( MutationKind::cases() as $kind ) {
			$outcome = $this->resolver( RecordingDriver::with_incomplete_identity() )
				->resolve( $this->mapping(), $kind, str_repeat( '1', 32 ) );

			$this->assertFalse( $outcome->conclusive, "kind {$kind->value} must wait on an incomplete read" );
		}
	}

	public function test_adopt_recovery_confirms_from_identity(): void {
		$outcome = $this->resolver( RecordingDriver::succeeding( 'ref-1' ) )
			->resolve( $this->mapping(), MutationKind::ADOPT, str_repeat( '1', 32 ) );

		$this->assertTrue( $outcome->conclusive );
		$this->assertNotNull( $outcome->apply );
	}

	public function test_method_recovery_reads_the_confirmed_method(): void {
		$outcome = $this->resolver( RecordingDriver::confirming_method( 'http' ) )
			->resolve( $this->mapping(), MutationKind::METHOD, str_repeat( '1', 32 ) );

		$this->assertTrue( $outcome->conclusive );
		$this->assertArrayHasKey( 'ssl_method', $outcome->apply->columns() );
	}

	public function test_remove_recovery_deletes_when_the_resource_is_gone(): void {
		$outcome = $this->resolver( RecordingDriver::ambiguous_then_absent() )
			->resolve( $this->mapping(), MutationKind::REMOVE, str_repeat( '1', 32 ) );

		$this->assertTrue( $outcome->conclusive );
		$this->assertTrue( $outcome->delete_row );
	}

	public function test_remove_recovery_keeps_the_row_when_the_resource_still_exists(): void {
		$outcome = $this->resolver( RecordingDriver::succeeding( 'ref-1' ) )
			->resolve( $this->mapping(), MutationKind::REMOVE, str_repeat( '1', 32 ) );

		$this->assertFalse( $outcome->delete_row );
	}

	public function test_the_resolver_issues_no_provider_mutation(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );

		foreach ( MutationKind::cases() as $kind ) {
			$this->resolver( $driver )->resolve( $this->mapping(), $kind, str_repeat( '1', 32 ) );
		}

		$this->assertSame( 0, $driver->create_calls );
		$this->assertSame( 0, $driver->adopt_calls );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertSame( 0, $driver->remove_calls );
	}
}
```

Create `tests/integration/Ssl/ReconcilerTest.php`:

```php
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
use WP_UnitTestCase;

final class ReconcilerTest extends WP_UnitTestCase {

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
	private function driver( array $statuses, bool $complete ): SslDriver {
		return new class( $statuses, $complete ) implements SslDriver {
			/** @param array<string, SslStatus> $statuses */
			public function __construct( private readonly array $statuses, private readonly bool $complete ) {}

			public function id(): string {
				return 'recon';
			}

			public function capabilities(): DriverCapabilities {
				return new DriverCapabilities( false, array( 'txt' ), false );
			}

			public function status( SslResourceContext $ctx ): SslStatus {
				return $this->statuses[ $ctx->host ] ?? new SslStatus( SslState::NONE );
			}

			public function identify( SslResourceContext $ctx ): IdentityResult {
				return new IdentityResult(
					IdentityVerdict::MATCH, $ctx->provider_ref, $ctx->provider_ref,
					$ctx->host, null, MarkerSupport::UNAVAILABLE, true, false
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
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, $state,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'recon', 'ref-1'
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
		global $wpdb;

		$m = $this->mapping( SslState::ACTIVE );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_ownership_origin' => null, 'ssl_owner_installation_id' => null, 'ssl_ref' => null ),
			array( 'id' => $m->id )
		);

		$this->install( $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true ) );
		Reconciler::run( array( $this->repo->by_id( $m->id ) ) );

		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_ownership_origin );
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test:integration -- --filter DriverRecoveryResolverTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\DriverRecoveryResolver" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/DriverRecoveryResolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Verification\Challenge;

/**
 * Reads provider state for a fenced mutation and decides what it means, per kind.
 * It calls only status() and identify(); it never mutates.
 */
final class DriverRecoveryResolver implements RecoveryResolver {

	// No constructor: there is one production source of drivers and this is not
	// a place that may disagree with it.

	public function resolve( Mapping $mapping, MutationKind $kind, string $recovery_token ): RecoveryOutcome {
		$driver = DriverFactory::for_mapping( $mapping );

		if ( $driver instanceof DriverUnavailable ) {
			// Inconclusive, never conclusive: without the owning driver there is
			// no way to learn what happened, and guessing would be worse.
			return RecoveryOutcome::inconclusive( 'driver unavailable: ' . $driver->reason );
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return RecoveryOutcome::inconclusive( 'challenge name invalid' );
		}

		$context  = SslResourceContext::from_mapping(
			$mapping,
			Environment::installation_id(),
			$name,
			$recovery_token
		);
		$identity = $driver->identify( $context );

		if ( ! $identity->read_complete || $identity->transient ) {
			return RecoveryOutcome::inconclusive( 'provider read incomplete' );
		}

		return match ( $kind ) {
			MutationKind::CREATE => $this->resolve_create( $identity, $context ),
			MutationKind::ADOPT  => $this->resolve_adopt( $identity, $context ),
			MutationKind::METHOD => $this->resolve_method( $driver, $context ),
			MutationKind::REMOVE => $this->resolve_remove( $identity ),
		};
	}

	private function resolve_create( IdentityResult $identity, SslResourceContext $ctx ): RecoveryOutcome {
		$decision = CreateRecovery::decide( $identity, $ctx );

		return match ( $decision ) {
			CreateRecovery::BIND           => RecoveryOutcome::apply(
				LeaseOutcome::bound(
					SslState::REQUESTED,
					(string) $identity->observed_ref,
					$ctx->provider_id,
					OwnershipOrigin::CREATED,
					$ctx->installation_id
				),
				'recovered an ambiguous create by matching marker'
			),
			CreateRecovery::RETRY          => RecoveryOutcome::apply(
				LeaseOutcome::checked(),
				'no resource exists; the create may be retried'
			),
			CreateRecovery::ADOPT_REQUIRED => RecoveryOutcome::apply(
				LeaseOutcome::failure(
					SslState::FAILED,
					'provider_create_ambiguous',
					'A resource may exist for this hostname; adopt it explicitly.'
				),
				'ambiguous create needs an explicit adopt'
			),
			CreateRecovery::UNOWNED        => RecoveryOutcome::apply(
				LeaseOutcome::failure(
					SslState::FAILED,
					'unowned_resource',
					'A resource exists carrying a marker from another installation.'
				),
				'foreign marker'
			),
			default                        => RecoveryOutcome::inconclusive( 'create state still unclear' ),
		};
	}

	private function resolve_adopt( IdentityResult $identity, SslResourceContext $ctx ): RecoveryOutcome {
		if ( null === $identity->observed_ref || $identity->observed_hostname !== $ctx->host ) {
			return RecoveryOutcome::apply( LeaseOutcome::checked(), 'adoption did not take effect' );
		}

		if ( $identity->has_conflicting_marker( $ctx->installation_id, $ctx->mapping_id ) ) {
			return RecoveryOutcome::apply(
				LeaseOutcome::failure( SslState::FAILED, 'unowned_resource', 'The marker names another installation.' ),
				'adoption blocked by a foreign marker'
			);
		}

		return RecoveryOutcome::apply(
			LeaseOutcome::adopted(
				SslState::REQUESTED,
				$identity->observed_ref,
				$ctx->provider_id,
				$ctx->installation_id,
				0
			),
			'adoption confirmed by identity'
		);
	}

	private function resolve_method( \PostDomain\Contracts\SslDriver $driver, SslResourceContext $ctx ): RecoveryOutcome {
		$status = $driver->status( $ctx );

		if ( $status->transient || null === $status->confirmed_method ) {
			return RecoveryOutcome::inconclusive( 'provider did not report a method' );
		}

		return RecoveryOutcome::apply(
			LeaseOutcome::method_confirmed( $status->confirmed_method ),
			'method confirmed by re-read'
		);
	}

	private function resolve_remove( IdentityResult $identity ): RecoveryOutcome {
		if ( IdentityVerdict::ABSENT === $identity->verdict ) {
			return RecoveryOutcome::delete( 'provider confirms the resource is gone' );
		}

		return RecoveryOutcome::apply(
			LeaseOutcome::state( SslState::PENDING_REMOVAL ),
			'the resource still exists; removal remains pending'
		);
	}
}
```

Create `src/Ssl/Reconciler.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\Schema;
use PostDomain\Verification\Challenge;

/**
 * Adopts provider truth for state only: it never deletes at the provider, never
 * adopts ownership, never auto-patches a divergent method, and skips leased rows.
 */
final class Reconciler {

	/**
	 * @param Mapping[] $mappings
	 * @return array{updated: int, divergences: int, skipped: int}
	 */
	public static function run( array $mappings ): array {
		$totals = array( 'updated' => 0, 'divergences' => 0, 'skipped' => 0 );

		/** @var array<string, array{driver: SslDriver, mappings: Mapping[]}> $groups */
		$groups = array();

		foreach ( $mappings as $mapping ) {
			// A leased row belongs to whoever holds the lease. Reconciliation
			// never writes through one, so it does not even read one here.
			if ( null !== $mapping->ssl_mutation_token ) {
				++$totals['skipped'];

				continue;
			}

			$driver = DriverFactory::for_mapping( $mapping );

			if ( $driver instanceof DriverUnavailable ) {
				++$totals['skipped'];

				continue;
			}

			$groups[ $driver->id() ]['driver']     = $driver;
			$groups[ $driver->id() ]['mappings'][] = $mapping;
		}

		foreach ( $groups as $group ) {
			$result = self::reconcile_group( $group['driver'], $group['mappings'] );

			$totals['updated']     += $result['updated'];
			$totals['divergences'] += $result['divergences'];
			$totals['skipped']     += $result['skipped'];
		}

		return $totals;
	}

	/**
	 * @param Mapping[] $mappings
	 * @return array{updated: int, divergences: int, skipped: int}
	 */
	private static function reconcile_group( SslDriver $driver, array $mappings ): array {
		global $wpdb;

		$contexts = array();
		$by_host  = array();

		foreach ( $mappings as $mapping ) {
			$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

			if ( null === $name ) {
				continue;
			}

			$contexts[]                = SslResourceContext::from_mapping(
				$mapping,
				Environment::installation_id(),
				$name
			);
			$by_host[ $mapping->host ] = $mapping;
		}

		if ( array() === $contexts ) {
			return array( 'updated' => 0, 'divergences' => 0, 'skipped' => 0 );
		}

		$report      = $driver->reconcile( $contexts );
		$updated     = 0;
		$divergences = 0;
		$skipped     = 0;

		foreach ( $report->statuses as $host => $status ) {
			$mapping = $by_host[ $host ] ?? null;

			if ( null === $mapping || $status->transient ) {
				continue;
			}

			if ( null !== $status->confirmed_method && $status->confirmed_method !== $mapping->ssl_method ) {
				++$divergences;

				// Reported, never patched: the local method is an operator
				// decision, and this is a read.
				EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$mapping->ssl_method,
					$status->confirmed_method,
					'cron',
					array( 'divergence' => 'validation_method', 'auto_patched' => false )
				);
			}

			if ( $status->state === $mapping->ssl_state ) {
				continue;
			}

			// The CAS result decides whether this counts. A row whose revision
			// moved, or which acquired a lease since the snapshot, was changed by
			// someone with better information than a batch read.
			$applied = AtomicTransition::commit(
				static fn (): bool => 1 === $wpdb->query( // phpcs:ignore WordPress.DB
					$wpdb->prepare(
						'UPDATE ' . Schema::domains_table()
						. ' SET ssl_state = %s, ssl_checked_at = %s, revision = revision + 1, updated_at = %s'
						. ' WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL',
						$status->state->value,
						gmdate( 'Y-m-d H:i:s' ),
						gmdate( 'Y-m-d H:i:s' ),
						$mapping->id,
						$mapping->revision
					)
				),
				static fn (): bool => EventLog::record(
					$mapping->id,
					$mapping->host,
					'ssl',
					$mapping->ssl_state->value,
					$status->state->value,
					'cron',
					array( 'source' => 'reconciliation' )
				)
			);

			if ( $applied ) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		if ( ! $report->snapshot_complete ) {
			EventLog::record( 0, '', 'ssl', null, null, 'cron', array( 'snapshot_incomplete' => $report->incomplete_reason ) );
		}

		return array( 'updated' => $updated, 'divergences' => $divergences, 'skipped' => $skipped );
	}
}
```

Add to `src/Plugin.php`, inside `boot()`:

```php
		add_action( 'pd_ssl_sweep', array( $plugin, 'sweep_ssl' ) );
```

and the method:

```php
	public function sweep_ssl(): void {
		$clock = new \PostDomain\Support\SystemClock();
		$lease = new \PostDomain\Ssl\MutationLease( $clock );

		// No registry is built here. Cron resolves drivers through exactly the
		// factory REST uses, so the two can never disagree about a row's owner.
		$recovery = new \PostDomain\Ssl\LeaseRecovery( $lease, $this->repository, $clock );
		$resolver = new \PostDomain\Ssl\DriverRecoveryResolver();

		foreach ( $recovery->due( 50 ) as $mapping ) {
			$recovery->recover( $mapping, $resolver );
		}

		\PostDomain\Ssl\Reconciler::run( $this->repository->all() );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer test:integration -- --filter DriverRecoveryResolverTest && composer test:integration -- --filter ReconcilerTest`
Expected: PASS — 9 and 11 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Ssl/DriverRecoveryResolver.php src/Ssl/Reconciler.php src/Plugin.php tests/integration/Ssl/DriverRecoveryResolverTest.php tests/integration/Ssl/ReconcilerTest.php
git commit -m "Resolve fenced mutations by kind, reading only

Recovery calls status() and identify() and nothing else; the reconciler adopts
provider truth for state alone, never ownership and never a method. Both resolve
their drivers through the same factory REST uses, and the reconciler counts an
update only when its CAS actually affected a row."
```

---

## Gate for Plan 08

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: every authorizer's individual precondition failures prove zero mutating
provider calls and a released reservation; the fencing tests prove a failed
finalize writes nothing, deletes nothing, records no success event, and returns
`FENCED` rather than the provider's status; `DeletionServiceTest` proves
force-local-delete and the no-provider local delete both refuse across all six
lease phase-and-expiry states; `ReconcilerTest` proves a zero-row update is
neither counted nor logged; and `Plugin::sweep_ssl()` builds no registry of its
own.
