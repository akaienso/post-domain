# post-domain 08 — SSL lifecycle: create, adopt, method change, delete, reconcile

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every provider operation runs through the §12.6 sequence, and every
ambiguous outcome is resolved by reading provider state rather than by repeating
a mutation.

**Architecture:** Each operation has an authorizer that produces a bound
authorization and a service that runs it through `MutationGate`. Ambiguity is
never guessed: create recovery has six named cases, and only one of them binds a
reference without an explicit adoption.

**Tech Stack:** As Plans 01–07.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–07, and add:

- **Adoption is never automatic.** Finding a duplicate does not adopt it,
  recovery does not adopt, and reconciliation adopts nothing (spec §14.7).
- **`create()` is idempotent only once the reference is durably bound.** A
  marker-free create-then-timeout may require explicit adoption (spec §14.6).
- **The normal deletion path never deletes the local row before external cleanup
  succeeds.** The sole exception is force-local-delete after the ceiling, which
  issues no provider deletion (spec §14.15).
- **Force-local-delete cannot overwrite any existing lease** (spec §14.5).
- **Reconciliation never adopts ownership and never auto-patches a divergent
  method** (spec §14.17).
- **`deletion_attempts` is not incremented for transient refusals** (spec §14.15).

---

## File map

| File | Responsibility |
|---|---|
| `src/Ssl/CreateRecovery.php` | The six ambiguous-create cases |
| `src/Ssl/CreateService.php` | Provision under the gate, then resolve the outcome |
| `src/Ssl/AdoptionAuthorizer.php` | Explicit adoption preconditions |
| `src/Ssl/AdoptionService.php` | Adoption under the gate |
| `src/Ssl/MethodChangeAuthorizer.php` | Method-change preconditions |
| `src/Ssl/MethodChangeService.php` | PATCH, re-read, persist only on confirmation |
| `src/Ssl/DeletionService.php` | The durable removal workflow |
| `src/Ssl/ForceLocalDelete.php` | Local-only removal that proves no lease exists |
| `src/Ssl/Reconciler.php` | Daily provider-truth adoption for state only |

---

### Task 1: Ambiguous-create recovery

**Files:**
- Create: `src/Ssl/CreateRecovery.php`
- Test: `tests/unit/Ssl/CreateRecoveryTest.php`

**Interfaces:**
- Consumes: `IdentityResult` (Plan 07).
- Produces: `PostDomain\Ssl\CreateRecovery::decide( IdentityResult $identity, SslResourceContext $ctx ): string` returning one of `bind`, `retry`, `adopt_required`, `unowned`, `wait`.

The six cases from spec §14.6, named:

| Case | Situation | Decision |
|---|---|---|
| A | POST succeeded, complete resource | `bind` (handled by the service, not here) |
| B | POST ambiguous | read first — this class decides from that read |
| C | Read finds nothing conclusively | `retry` |
| D | Read finds a resource whose marker names this install and mapping | `bind` |
| E | Read finds a resource, markers unavailable or absent, nothing bound | `adopt_required` |
| F | Read finds a foreign or conflicting marker | `unowned` |

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
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 3
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
		$identity = $this->identity( IdentityVerdict::ABSENT, null, null, MarkerSupport::SUPPORTED );

		$this->assertSame( 'retry', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_case_d_a_marker_naming_this_install_and_mapping_binds(): void {
		$identity = $this->identity(
			IdentityVerdict::RECOVERABLE_CREATE,
			'ref-9',
			new ProviderMarker( 'install-a', 12, array() ),
			MarkerSupport::SUPPORTED
		);

		$this->assertSame( 'bind', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_case_d_does_not_apply_to_another_mapping(): void {
		$identity = $this->identity(
			IdentityVerdict::RECOVERABLE_CREATE,
			'ref-9',
			new ProviderMarker( 'install-a', 99, array() ),
			MarkerSupport::SUPPORTED
		);

		$this->assertSame( 'unowned', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_case_e_markers_unavailable_requires_explicit_adoption(): void {
		$identity = $this->identity(
			IdentityVerdict::MISMATCH,
			'ref-9',
			null,
			MarkerSupport::UNAVAILABLE
		);

		$this->assertSame(
			'adopt_required',
			CreateRecovery::decide( $identity, $this->context() ),
			'the plugin refuses to guess which unbound resource is its own'
		);
	}

	public function test_case_e_an_absent_marker_also_requires_adoption(): void {
		$identity = $this->identity(
			IdentityVerdict::MISMATCH,
			'ref-9',
			null,
			MarkerSupport::SUPPORTED
		);

		$this->assertSame( 'adopt_required', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_case_f_a_foreign_marker_is_unowned(): void {
		$identity = $this->identity(
			IdentityVerdict::MISMATCH,
			'ref-9',
			new ProviderMarker( 'other-install', 12, array() ),
			MarkerSupport::SUPPORTED
		);

		$this->assertSame( 'unowned', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_an_incomplete_read_waits(): void {
		$identity = $this->identity(
			IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, false
		);

		$this->assertSame( 'wait', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_a_transient_read_waits(): void {
		$identity = $this->identity(
			IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, true, true
		);

		$this->assertSame( 'wait', CreateRecovery::decide( $identity, $this->context() ) );
	}

	public function test_recovery_never_applies_once_a_reference_is_bound(): void {
		$identity = $this->identity(
			IdentityVerdict::RECOVERABLE_CREATE,
			'ref-9',
			new ProviderMarker( 'install-a', 12, array() ),
			MarkerSupport::SUPPORTED
		);

		$this->assertSame(
			'wait',
			CreateRecovery::decide( $identity, $this->context( 'ref-1' ) ),
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

### Task 2: Provisioning under the gate

**Files:**
- Create: `src/Ssl/CreateService.php`
- Test: `tests/integration/Ssl/CreateServiceTest.php`

**Interfaces:**
- Consumes: `MutationGate`, `MutationLease`, `SslDriverRegistry`, `FreshProof`, `CreateRecovery`.
- Produces: `PostDomain\Ssl\CreateService::provision( Mapping $m ): SslStatus|MutationRefusal`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/CreateServiceTest.php`:

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
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
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

	private function mapping(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_a_successful_create_binds_the_reference_and_records_provenance(): void {
		$driver  = RecordingDriver::succeeding( 'ref-1' );
		$mapping = $this->mapping();

		$status = CreateService::for_tests( $driver )->provision( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( SslState::REQUESTED, $after?->ssl_state );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertSame( OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( Environment::installation_id(), $after?->ssl_owner_installation_id );
		$this->assertSame( 'recording', $after?->ssl_provider );
		$this->assertNotNull( $status );
	}

	public function test_the_lease_is_cleared_after_a_successful_create(): void {
		$mapping = $this->mapping();

		CreateService::for_tests( RecordingDriver::succeeding( 'ref-1' ) )->provision( $mapping );

		$this->assertNull( $this->repo->by_id( $mapping->id )?->ssl_mutation_token );
	}

	public function test_a_second_concurrent_provision_sends_no_second_post(): void {
		$driver  = RecordingDriver::succeeding( 'ref-1' );
		$mapping = $this->mapping();

		$service = CreateService::for_tests( $driver );
		$service->provision( $mapping );

		// The stale mapping object still carries the pre-lease revision.
		$second = $service->provision( $mapping );

		$this->assertInstanceOf( MutationRefusal::class, $second );
		$this->assertSame( 1, $driver->create_calls, 'exactly one POST' );
	}

	public function test_an_ambiguous_create_with_a_matching_marker_binds_without_adoption(): void {
		$driver  = RecordingDriver::ambiguous_then_marked( 'ref-9' );
		$mapping = $this->mapping();

		CreateService::for_tests( $driver )->provision( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( 'ref-9', $after?->ssl_ref );
		$this->assertSame(
			OwnershipOrigin::CREATED,
			$after?->ssl_ownership_origin,
			'a recovered create is created, not adopted'
		);
		$this->assertNull( $after?->ssl_adopted_at );
	}

	public function test_an_ambiguous_create_without_markers_requires_adoption(): void {
		$driver  = RecordingDriver::ambiguous_then_unmarked( 'ref-9' );
		$mapping = $this->mapping();

		CreateService::for_tests( $driver )->provision( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( SslState::FAILED, $after?->ssl_state );
		$this->assertNull( $after?->ssl_ref, 'nothing is bound without an explicit adoption' );
		$this->assertStringContainsString( 'provider_create_ambiguous', (string) $after?->ssl_error );
	}

	public function test_an_ambiguous_create_with_a_foreign_marker_is_unowned(): void {
		$driver  = RecordingDriver::ambiguous_then_foreign( 'ref-9' );
		$mapping = $this->mapping();

		CreateService::for_tests( $driver )->provision( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertStringContainsString( 'unowned_resource', (string) $after?->ssl_error );
		$this->assertNull( $after?->ssl_ref );
	}

	public function test_a_conclusive_absence_leaves_the_mapping_retryable(): void {
		$driver  = RecordingDriver::ambiguous_then_absent();
		$mapping = $this->mapping();

		CreateService::for_tests( $driver )->provision( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertNull( $after?->ssl_mutation_token, 'the lease is released so a retry can acquire' );
		$this->assertNull( $after?->ssl_ref );
	}

	public function test_the_post_is_never_repeated_after_an_ambiguous_outcome(): void {
		$driver = RecordingDriver::ambiguous_then_absent();

		CreateService::for_tests( $driver )->provision( $this->mapping() );

		$this->assertSame( 1, $driver->create_calls );
		$this->assertGreaterThanOrEqual( 1, $driver->identify_calls, 'the read happens before any retry' );
	}

	public function test_provisioning_is_refused_while_the_environment_is_unresolved(): void {
		update_option( 'pd_environment_mismatch', array( 'stored' => 'a', 'current' => 'b' ), false );

		$driver = RecordingDriver::succeeding( 'ref-1' );
		$result = CreateService::for_tests( $driver )->provision( $this->mapping() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 0, $driver->create_calls );
	}
}
```

Create the shared driver fixture `tests/integration/Ssl/Fixtures/RecordingDriver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl\Fixtures;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\ProviderMarker;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;

final class RecordingDriver implements SslDriver {

	public int $create_calls = 0;

	public int $identify_calls = 0;

	public int $remove_calls = 0;

	public int $method_calls = 0;

	private function __construct(
		private readonly ?string $created_ref,
		private readonly bool $create_is_ambiguous,
		private readonly IdentityVerdict $identity_verdict,
		private readonly ?string $observed_ref,
		private readonly ?ProviderMarker $marker,
		private readonly MarkerSupport $marker_support,
		private readonly RemovalOutcome $removal = RemovalOutcome::REMOVED,
		private readonly ?string $confirmed_method = null
	) {}

	public static function succeeding( string $ref ): self {
		return new self( $ref, false, IdentityVerdict::MATCH, $ref, null, MarkerSupport::UNAVAILABLE );
	}

	public static function ambiguous_then_marked( string $ref ): self {
		return new self(
			null,
			true,
			IdentityVerdict::RECOVERABLE_CREATE,
			$ref,
			new ProviderMarker( \PostDomain\Ssl\Environment::installation_id(), 0, array() ),
			MarkerSupport::SUPPORTED
		);
	}

	public static function ambiguous_then_unmarked( string $ref ): self {
		return new self( null, true, IdentityVerdict::MISMATCH, $ref, null, MarkerSupport::UNAVAILABLE );
	}

	public static function ambiguous_then_foreign( string $ref ): self {
		return new self(
			null,
			true,
			IdentityVerdict::MISMATCH,
			$ref,
			new ProviderMarker( 'someone-else', 1, array() ),
			MarkerSupport::SUPPORTED
		);
	}

	public static function ambiguous_then_absent(): self {
		return new self( null, true, IdentityVerdict::ABSENT, null, null, MarkerSupport::SUPPORTED );
	}

	public static function removing( RemovalOutcome $outcome ): self {
		return new self( 'ref-1', false, IdentityVerdict::MATCH, 'ref-1', null, MarkerSupport::UNAVAILABLE, $outcome );
	}

	public static function confirming_method( string $method ): self {
		return new self(
			'ref-1',
			false,
			IdentityVerdict::MATCH,
			'ref-1',
			null,
			MarkerSupport::UNAVAILABLE,
			RemovalOutcome::REMOVED,
			$method
		);
	}

	public function id(): string {
		return 'recording';
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities( MarkerSupport::SUPPORTED === $this->marker_support, array( 'txt', 'http' ), false );
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		return new SslStatus( SslState::REQUESTED, $ctx->provider_ref, null, null, $this->confirmed_method );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		++$this->identify_calls;

		$marker = $this->marker;

		if ( null !== $marker && 0 === $marker->mapping_id ) {
			$marker = new ProviderMarker( $marker->installation_id, $ctx->mapping_id, array() );
		}

		return new IdentityResult(
			$this->identity_verdict,
			$ctx->provider_ref,
			$this->observed_ref,
			$ctx->host,
			$marker,
			$this->marker_support,
			true,
			false
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( \PostDomain\Ssl\MutationKind::CREATE );
		++$this->create_calls;

		if ( $this->create_is_ambiguous ) {
			return new SslStatus( SslState::NONE, null, 'timeout', 'ambiguous', null, true );
		}

		return new SslStatus( SslState::REQUESTED, $this->created_ref );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( \PostDomain\Ssl\MutationKind::ADOPT );

		return new SslStatus( SslState::REQUESTED, $this->observed_ref );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( \PostDomain\Ssl\MutationKind::METHOD );
		++$this->method_calls;

		return new SslStatus( SslState::REQUESTED, $ctx->provider_ref, null, null, $this->confirmed_method );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_kind( \PostDomain\Ssl\MutationKind::REMOVE );
		++$this->remove_calls;

		return new RemovalResult( $this->removal );
	}

	public function reconcile( array $contexts ): ReconcileReport {
		return new ReconcileReport( array(), true );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		return new ValidationPlan( array(), array(), array(), array(), array() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CreateServiceTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\CreateService" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/CreateService.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\Challenge;

final class CreateService {

	public function __construct(
		private readonly SslDriver $driver,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );

		return new self( $driver, $lease, new MutationGate( $lease ), $clock );
	}

	/** @return SslStatus|MutationRefusal */
	public function provision( Mapping $mapping ) {
		if ( Environment::is_blocked() ) {
			return new MutationRefusal( 'environment_unresolved', false );
		}

		if ( Cooldown::active_for( $this->driver->id() ) ) {
			return new MutationRefusal( 'provider_cooldown', true );
		}

		$ttl   = max( 30, min( 600, (int) apply_filters( 'pd_mutation_lease_ttl', 120 ) ) );
		$lease = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, $ttl );

		if ( null === $lease ) {
			return new MutationRefusal( 'lease_unavailable', true );
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return new MutationRefusal( 'challenge_name_invalid', false );
		}

		$context = SslResourceContext::from_mapping(
			$mapping,
			Environment::installation_id(),
			$name,
			$lease['token']
		);

		$auth = new MutationAuthorization(
			MutationKind::CREATE,
			$lease['token'],
			$mapping->id,
			$lease['revision'],
			$mapping->host,
			$mapping->ssl_provider,
			$mapping->ssl_ref,
			$mapping->challenge,
			$mapping->ssl_method,
			$mapping->ssl_ownership_origin,
			$mapping->ssl_owner_installation_id,
			false,
			$this->clock->now()->modify( '+120 seconds' )
		);

		$outcome = $this->gate->execute(
			$auth,
			$mapping,
			fn( ExecutionPermit $permit ): SslStatus => $this->driver->create( $context, $permit )
		);

		if ( $outcome instanceof MutationRefusal ) {
			return $outcome;
		}

		return $this->settle( $mapping, $context, $lease, $outcome );
	}

	/**
	 * @param array{token: string, revision: int} $lease
	 * @return SslStatus|MutationRefusal
	 */
	private function settle( Mapping $mapping, SslResourceContext $context, array $lease, SslStatus $status ) {
		$in_flight = $lease['revision'] + 1;

		if ( ! $status->transient && null !== $status->ref ) {
			$this->lease->finalize(
				$mapping->id,
				$in_flight,
				$lease['token'],
				MutationKind::CREATE,
				array(
					'ssl_state'                 => $status->state->value,
					'ssl_ref'                   => $status->ref,
					'ssl_provider'              => $this->driver->id(),
					'ssl_ownership_origin'      => OwnershipOrigin::CREATED->value,
					'ssl_owner_installation_id' => $context->installation_id,
					'ssl_checked_at'            => $this->clock->mysql(),
				)
			);

			return $status;
		}

		// Ambiguous: read before considering anything else.
		$identity = $this->driver->identify( $context );
		$decision = CreateRecovery::decide( $identity, $context );

		$columns = match ( $decision ) {
			CreateRecovery::BIND => array(
				'ssl_state'                 => SslState::REQUESTED->value,
				'ssl_ref'                   => $identity->observed_ref,
				'ssl_provider'              => $this->driver->id(),
				'ssl_ownership_origin'      => OwnershipOrigin::CREATED->value,
				'ssl_owner_installation_id' => $context->installation_id,
				'ssl_checked_at'            => $this->clock->mysql(),
			),
			CreateRecovery::ADOPT_REQUIRED => array(
				'ssl_state' => SslState::FAILED->value,
				'ssl_error' => (string) wp_json_encode(
					array( 'code' => 'provider_create_ambiguous', 'message' => 'A resource may exist; adopt it explicitly.', 'at' => $this->clock->mysql() )
				),
			),
			CreateRecovery::UNOWNED => array(
				'ssl_state' => SslState::FAILED->value,
				'ssl_error' => (string) wp_json_encode(
					array( 'code' => 'unowned_resource', 'message' => 'A resource exists with a foreign marker.', 'at' => $this->clock->mysql() )
				),
			),
			default => array( 'ssl_checked_at' => $this->clock->mysql() ),
		};

		$this->lease->finalize( $mapping->id, $in_flight, $lease['token'], MutationKind::CREATE, $columns );

		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			null,
			$decision,
			'cron',
			array( 'create_recovery' => $decision )
		);

		return $status;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CreateServiceTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/CreateService.php tests/integration/Ssl/CreateServiceTest.php tests/integration/Ssl/Fixtures/RecordingDriver.php
git commit -m "Provision certificates under the gate and settle ambiguity by reading

The POST is sent once. A timeout is followed by a provider read, never by a
second POST, and only a marker naming this install and mapping binds a
reference."
```

---

### Task 3: Explicit adoption

**Files:**
- Create: `src/Ssl/AdoptionAuthorizer.php`, `src/Ssl/AdoptionService.php`
- Test: `tests/integration/Ssl/AdoptionTest.php`

**Interfaces:**
- Consumes: Plan 07's authorization machinery, `FreshProof`.
- Produces: `AdoptionAuthorizer::authorize( Mapping $m, array $request ): MutationAuthorization|MutationRefusal` and `AdoptionService::adopt( Mapping $m, array $request ): SslStatus|MutationRefusal`.

Adoption requires an explicit `confirm`, a fresh complete identity whose hostname
matches, a fresh DNS proof, and — against a foreign marker — a second key
(spec §14.7).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/AdoptionTest.php`:

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
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationRefusal;
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
		$mapping = $this->mapping();

		AdoptionService::for_tests( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ), $this->proof( DnsOutcome::MATCH ) )
			->adopt( $mapping, array( 'confirm' => true ) );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( OwnershipOrigin::ADOPTED, $after?->ssl_ownership_origin );
		$this->assertSame( Environment::installation_id(), $after?->ssl_owner_installation_id );
		$this->assertSame( 'ref-9', $after?->ssl_ref );
		$this->assertNotNull( $after?->ssl_adopted_at );
	}

	public function test_adoption_without_confirmation_is_refused(): void {
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->adopt( $this->mapping(), array() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'confirmation_required', $result->precondition );
	}

	public function test_adoption_without_a_fresh_proof_is_refused(): void {
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' ),
			$this->proof( DnsOutcome::MISMATCH )
		)->adopt( $this->mapping(), array( 'confirm' => true ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'fresh_proof_failed', $result->precondition );
	}

	public function test_a_foreign_marker_needs_the_second_key(): void {
		$result = AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_foreign( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->adopt( $this->mapping(), array( 'confirm' => true ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'foreign_marker_override_required', $result->precondition );
	}

	public function test_a_foreign_marker_can_be_overridden_deliberately(): void {
		$mapping = $this->mapping();

		AdoptionService::for_tests(
			RecordingDriver::ambiguous_then_foreign( 'ref-9' ),
			$this->proof( DnsOutcome::MATCH )
		)->adopt( $mapping, array( 'confirm' => true, 'override_foreign_marker' => true ) );

		$this->assertSame(
			OwnershipOrigin::ADOPTED,
			$this->repo->by_id( $mapping->id )?->ssl_ownership_origin
		);
	}

	public function test_adoption_is_never_reached_by_provisioning(): void {
		$driver  = RecordingDriver::ambiguous_then_unmarked( 'ref-9' );
		$mapping = $this->mapping();

		\PostDomain\Ssl\CreateService::for_tests( $driver )->provision( $mapping );

		$this->assertNull(
			$this->repo->by_id( $mapping->id )?->ssl_ownership_origin,
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
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\Challenge;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class AdoptionAuthorizer {

	public function __construct(
		private readonly SslDriver $driver,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @param array{confirm?: bool, override_foreign_marker?: bool} $request
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, identity: IdentityResult}|MutationRefusal
	 */
	public function authorize( Mapping $mapping, array $request ) {
		if ( Environment::is_blocked() ) {
			return new MutationRefusal( 'environment_unresolved', false );
		}

		if ( true !== ( $request['confirm'] ?? false ) ) {
			return new MutationRefusal( 'confirmation_required', false );
		}

		$ttl   = max( 30, min( 600, (int) apply_filters( 'pd_mutation_lease_ttl', 120 ) ) );
		$lease = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::ADOPT, $ttl );

		if ( null === $lease ) {
			return new MutationRefusal( 'lease_unavailable', true );
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return new MutationRefusal( 'challenge_name_invalid', false );
		}

		$context  = SslResourceContext::from_mapping( $mapping, Environment::installation_id(), $name, $lease['token'] );
		$identity = $this->driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return new MutationRefusal( 'identity_incomplete', true );
		}

		if ( $identity->observed_hostname !== $mapping->host || null === $identity->observed_ref ) {
			return new MutationRefusal( 'identity_not_confirmed', false );
		}

		$override = true === ( $request['override_foreign_marker'] ?? false );

		if ( $identity->has_conflicting_marker( $context->installation_id, $mapping->id ) && ! $override ) {
			return new MutationRefusal( 'foreign_marker_override_required', false );
		}

		$outcome = $this->proof->prove( $mapping );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return new MutationRefusal( 'fresh_proof_transient', true );
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return new MutationRefusal( 'fresh_proof_failed', false );
		}

		return array(
			'auth'     => new MutationAuthorization(
				MutationKind::ADOPT,
				$lease['token'],
				$mapping->id,
				$lease['revision'],
				$mapping->host,
				$mapping->ssl_provider,
				$mapping->ssl_ref,
				$mapping->challenge,
				$mapping->ssl_method,
				$mapping->ssl_ownership_origin,
				$mapping->ssl_owner_installation_id,
				$override,
				$this->clock->now()->modify( '+120 seconds' )
			),
			'context'  => $context,
			'identity' => $identity,
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
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class AdoptionService {

	public function __construct(
		private readonly SslDriver $driver,
		private readonly AdoptionAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );

		return new self(
			$driver,
			new AdoptionAuthorizer( $driver, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease ),
			$clock
		);
	}

	/**
	 * @param array{confirm?: bool, override_foreign_marker?: bool} $request
	 * @return SslStatus|MutationRefusal
	 */
	public function adopt( Mapping $mapping, array $request ) {
		$authorized = $this->authorizer->authorize( $mapping, $request );

		if ( $authorized instanceof MutationRefusal ) {
			return $authorized;
		}

		$outcome = $this->gate->execute(
			$authorized['auth'],
			$mapping,
			fn( ExecutionPermit $permit ): SslStatus => $this->driver->adopt( $authorized['context'], $permit )
		);

		if ( $outcome instanceof MutationRefusal ) {
			return $outcome;
		}

		$this->lease->finalize(
			$mapping->id,
			$authorized['auth']->revision + 1,
			$authorized['auth']->lease_token,
			MutationKind::ADOPT,
			array(
				'ssl_state'                 => $outcome->state->value,
				'ssl_ref'                   => $authorized['identity']->observed_ref,
				'ssl_provider'              => $this->driver->id(),
				'ssl_ownership_origin'      => OwnershipOrigin::ADOPTED->value,
				'ssl_owner_installation_id' => $authorized['context']->installation_id,
				'ssl_adopted_at'            => $this->clock->mysql(),
				'ssl_adopted_by'            => get_current_user_id(),
			)
		);

		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			null,
			'adopted',
			'admin:' . get_current_user_id(),
			array(
				'observed_ref' => $authorized['identity']->observed_ref,
				'prior_marker' => $authorized['identity']->marker?->installation_id,
			)
		);

		return $outcome;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter AdoptionTest`
Expected: PASS — 6 tests

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
- Produces: `MethodChangeService::change( Mapping $m, string $method ): SslStatus|MutationRefusal`.

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
use PostDomain\Ssl\MutationRefusal;
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

	private function mapping(): Mapping {
		global $wpdb;

		$mapping = $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'recording', 'ref-1'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_method' => 'txt' ),
			array( 'id' => $mapping->id )
		);

		return $this->repo->by_id( $mapping->id );
	}

	public function test_a_confirmed_change_is_persisted(): void {
		$mapping = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'http' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $mapping, 'http' );

		$this->assertSame( 'http', $this->repo->by_id( $mapping->id )?->ssl_method );
	}

	public function test_an_unconfirmed_change_leaves_the_local_method_alone(): void {
		$mapping = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'txt' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $mapping, 'http' );

		$this->assertSame(
			'txt',
			$this->repo->by_id( $mapping->id )?->ssl_method,
			'local state follows the provider, not the request'
		);
	}

	public function test_an_unsupported_method_is_refused_without_calling_the_provider(): void {
		$driver = RecordingDriver::confirming_method( 'http' );

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::MATCH ) )
			->change( $this->mapping(), 'email' );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'method_unsupported', $result->precondition );
		$this->assertSame( 0, $driver->method_calls );
	}

	public function test_an_invalid_method_is_refused(): void {
		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $this->mapping(), 'carrier-pigeon' );

		$this->assertInstanceOf( MutationRefusal::class, $result );
	}

	public function test_a_failed_fresh_proof_refuses(): void {
		$driver = RecordingDriver::confirming_method( 'http' );

		$result = MethodChangeService::for_tests( $driver, $this->proof( DnsOutcome::NO_RECORD ) )
			->change( $this->mapping(), 'http' );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 0, $driver->method_calls );
	}

	public function test_no_ownership_authority_refuses(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'unowned.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge',
				null, null, 'recording', null
			)
		);

		$result = MethodChangeService::for_tests(
			RecordingDriver::confirming_method( 'http' ),
			$this->proof( DnsOutcome::MATCH )
		)->change( $mapping, 'http' );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
	}

	public function test_the_lease_is_released_afterwards(): void {
		$mapping = $this->mapping();

		MethodChangeService::for_tests( RecordingDriver::confirming_method( 'http' ), $this->proof( DnsOutcome::MATCH ) )
			->change( $mapping, 'http' );

		$this->assertNull( $this->repo->by_id( $mapping->id )?->ssl_mutation_token );
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
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\Challenge;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class MethodChangeAuthorizer {

	public const METHODS = array( 'http', 'txt', 'email' );

	public function __construct(
		private readonly SslDriver $driver,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext}|MutationRefusal
	 */
	public function authorize( Mapping $mapping, string $method ) {
		if ( Environment::is_blocked() ) {
			return new MutationRefusal( 'environment_unresolved', false );
		}

		if ( ! in_array( $method, self::METHODS, true )
			|| ! in_array( $method, $this->driver->capabilities()->validation_methods, true ) ) {
			return new MutationRefusal( 'method_unsupported', false );
		}

		$ttl   = max( 30, min( 600, (int) apply_filters( 'pd_mutation_lease_ttl', 120 ) ) );
		$lease = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::METHOD, $ttl );

		if ( null === $lease ) {
			return new MutationRefusal( 'lease_unavailable', true );
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return new MutationRefusal( 'challenge_name_invalid', false );
		}

		$context = SslResourceContext::from_mapping( $mapping, Environment::installation_id(), $name, $lease['token'] );

		if ( ! $context->has_ownership_authority() ) {
			return new MutationRefusal( 'no_ownership_authority', false );
		}

		$identity = $this->driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return new MutationRefusal( 'identity_incomplete', true );
		}

		if ( ! $identity->is_usable_for_mutation( $mapping->host )
			|| $identity->has_conflicting_marker( $context->installation_id, $mapping->id ) ) {
			return new MutationRefusal( 'identity_not_confirmed', false );
		}

		$outcome = $this->proof->prove( $mapping );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return new MutationRefusal( 'fresh_proof_transient', true );
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return new MutationRefusal( 'fresh_proof_failed', false );
		}

		return array(
			'auth'    => new MutationAuthorization(
				MutationKind::METHOD,
				$lease['token'],
				$mapping->id,
				$lease['revision'],
				$mapping->host,
				$mapping->ssl_provider,
				$mapping->ssl_ref,
				$mapping->challenge,
				$mapping->ssl_method,
				$mapping->ssl_ownership_origin,
				$mapping->ssl_owner_installation_id,
				false,
				$this->clock->now()->modify( '+120 seconds' )
			),
			'context' => $context,
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
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;

final class MethodChangeService {

	public function __construct(
		private readonly SslDriver $driver,
		private readonly MethodChangeAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );

		return new self(
			$driver,
			new MethodChangeAuthorizer( $driver, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease ),
			$clock
		);
	}

	/** @return SslStatus|MutationRefusal */
	public function change( Mapping $mapping, string $method ) {
		$authorized = $this->authorizer->authorize( $mapping, $method );

		if ( $authorized instanceof MutationRefusal ) {
			return $authorized;
		}

		$outcome = $this->gate->execute(
			$authorized['auth'],
			$mapping,
			fn( ExecutionPermit $permit ): SslStatus =>
				$this->driver->change_validation_method( $authorized['context'], $method, $permit )
		);

		if ( $outcome instanceof MutationRefusal ) {
			return $outcome;
		}

		$columns = array( 'ssl_checked_at' => $this->clock->mysql() );

		// Persist only after the provider's resulting method is confirmed.
		if ( $outcome->confirmed_method === $method ) {
			$columns['ssl_method']              = $method;
			$columns['ssl_method_requested_at'] = $this->clock->mysql();
		}

		$this->lease->finalize(
			$mapping->id,
			$authorized['auth']->revision + 1,
			$authorized['auth']->lease_token,
			MutationKind::METHOD,
			$columns
		);

		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			$mapping->ssl_method,
			$outcome->confirmed_method,
			'admin:' . get_current_user_id(),
			array( 'requested' => $method, 'confirmed' => $outcome->confirmed_method )
		);

		return $outcome;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter MethodChangeTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/MethodChangeAuthorizer.php src/Ssl/MethodChangeService.php tests/integration/Ssl/MethodChangeTest.php
git commit -m "Change the DCV method as a first-class operation, confirmed by re-read

Local state follows the provider rather than the request: an unconfirmed change
leaves ssl_method exactly as it was."
```

---

### Task 5: Durable deletion and force-local-delete

**Files:**
- Create: `src/Ssl/DeletionService.php`, `src/Ssl/ForceLocalDelete.php`
- Test: `tests/integration/Ssl/DeletionServiceTest.php`

**Interfaces:**
- Consumes: `DeletionAuthorizer` (Plan 07), `MutationGate`, `MutationLease`, `MappingRepository`.
- Produces: `DeletionService::request( Mapping $m ): void`, `::process( Mapping $m ): string`, and `ForceLocalDelete::run( Mapping $m ): bool`.

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
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
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

	public function test_requesting_deletion_stops_serving_immediately(): void {
		$mapping = $this->owned();

		DeletionService::for_tests( RecordingDriver::removing( RemovalOutcome::REMOVED ), $this->proof( DnsOutcome::MATCH ) )
			->request( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( ActivationState::INACTIVE, $after?->activation_state );
		$this->assertSame( SslState::PENDING_REMOVAL, $after?->ssl_state );
		$this->assertNotNull( $after?->deletion_requested_at );
	}

	public function test_the_local_row_survives_until_the_provider_confirms(): void {
		$mapping = $this->owned();
		$service = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::PENDING ),
			$this->proof( DnsOutcome::MATCH )
		);

		$service->request( $mapping );
		$service->process( $this->repo->by_id( $mapping->id ) );

		$this->assertNotNull( $this->repo->by_id( $mapping->id ), 'not deleted until cleanup succeeds' );
	}

	public function test_a_confirmed_removal_hard_deletes_the_row(): void {
		$mapping = $this->owned();
		$service = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::REMOVED ),
			$this->proof( DnsOutcome::MATCH )
		);

		$service->request( $mapping );
		$service->process( $this->repo->by_id( $mapping->id ) );

		$this->assertNull( $this->repo->by_id( $mapping->id ) );
	}

	public function test_the_final_event_survives_the_row(): void {
		$mapping = $this->owned();
		$service = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::REMOVED ),
			$this->proof( DnsOutcome::MATCH )
		);

		$service->request( $mapping );
		$service->process( $this->repo->by_id( $mapping->id ) );

		$events = EventLog::for_domain( $mapping->id );

		$this->assertNotEmpty( $events );
		$this->assertSame( 'mapped.test', end( $events )['host'] );
	}

	public function test_a_transient_removal_does_not_increment_attempts(): void {
		$mapping = $this->owned();
		$service = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::TRANSIENT ),
			$this->proof( DnsOutcome::MATCH )
		);

		$service->request( $mapping );
		$service->process( $this->repo->by_id( $mapping->id ) );

		global $wpdb;
		$attempts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d', $mapping->id )
		);

		$this->assertSame( 0, $attempts );
	}

	public function test_a_failed_removal_increments_attempts(): void {
		$mapping = $this->owned();
		$service = DeletionService::for_tests(
			RecordingDriver::removing( RemovalOutcome::FAILED ),
			$this->proof( DnsOutcome::MATCH )
		);

		$service->request( $mapping );
		$service->process( $this->repo->by_id( $mapping->id ) );

		global $wpdb;
		$attempts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT deletion_attempts FROM ' . Schema::domains_table() . ' WHERE id = %d', $mapping->id )
		);

		$this->assertSame( 1, $attempts );
	}

	public function test_force_local_delete_removes_the_row_and_records_the_orphan(): void {
		$mapping = $this->owned();

		$this->assertTrue( ForceLocalDelete::run( $mapping ) );
		$this->assertNull( $this->repo->by_id( $mapping->id ) );

		$events = EventLog::for_domain( $mapping->id );

		$this->assertStringContainsString( 'provider_resource_may_remain', (string) end( $events )['detail'] );
	}

	public function test_force_local_delete_issues_no_provider_deletion(): void {
		$driver  = RecordingDriver::removing( RemovalOutcome::REMOVED );
		$mapping = $this->owned();

		ForceLocalDelete::run( $mapping );

		$this->assertSame( 0, $driver->remove_calls );
	}

	/**
	 * @dataProvider phases
	 */
	public function test_force_local_delete_cannot_overwrite_any_lease( string $phase, int $offset ): void {
		global $wpdb;

		$mapping = $this->owned();

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '5', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => $phase,
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
			),
			array( 'id' => $mapping->id )
		);

		$this->assertFalse( ForceLocalDelete::run( $this->repo->by_id( $mapping->id ) ) );
		$this->assertNotNull( $this->repo->by_id( $mapping->id ) );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function phases(): array {
		return array(
			'reserved unexpired'   => array( 'reserved', 600 ),
			'reserved expired'     => array( 'reserved', -600 ),
			'in flight unexpired'  => array( 'in_flight', 600 ),
			'in flight expired'    => array( 'in_flight', -600 ),
			'recovering unexpired' => array( 'recovering', 600 ),
			'recovering expired'   => array( 'recovering', -600 ),
		);
	}

	public function test_a_null_driver_mapping_deletes_immediately(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'plain.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge'
			)
		);

		$service = DeletionService::for_tests(
			new \PostDomain\Ssl\NullDriver(),
			$this->proof( DnsOutcome::MATCH )
		);

		$service->request( $mapping );

		$this->assertNull( $this->repo->by_id( $mapping->id ), 'nothing external exists' );
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

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;

/**
 * Removes the local row only. Issues no provider deletion, and cannot start from
 * a row carrying any lease — including an expired one, which belongs to recovery.
 */
final class ForceLocalDelete {

	public static function run( Mapping $mapping ): bool {
		global $wpdb;

		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$taken = $lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE, 60 );

		if ( null === $taken ) {
			return false;
		}

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'DELETE FROM ' . Schema::domains_table() . ' WHERE id = %d AND ssl_mutation_token = %s',
				$mapping->id,
				$taken['token']
			)
		);

		if ( 1 !== $deleted ) {
			return false;
		}

		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			null,
			'force_deleted',
			'admin:' . get_current_user_id(),
			array( 'note' => 'provider_resource_may_remain' )
		);

		unset( $wpdb );

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
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\Challenge;
use PostDomain\Verification\FreshProof;

final class DeletionService {

	public function __construct(
		private readonly SslDriver $driver,
		private readonly DeletionAuthorizer $authorizer,
		private readonly MutationLease $lease,
		private readonly MutationGate $gate,
		private readonly DbRepository $repo,
		private readonly Clock $clock
	) {}

	public static function for_tests( SslDriver $driver, FreshProof $proof ): self {
		$clock    = new SystemClock();
		$lease    = new MutationLease( $clock );
		$registry = new SslDriverRegistry( new NullDriver() );
		$registry->register( $driver );

		return new self(
			$driver,
			new DeletionAuthorizer( $registry, $proof, $lease, $clock ),
			$lease,
			new MutationGate( $lease ),
			new DbRepository(),
			$clock
		);
	}

	public function request( Mapping $mapping ): void {
		global $wpdb;

		$holds_resource = null !== $mapping->ssl_ref && 'null' !== $mapping->ssl_provider;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'deletion_requested_at'    => $this->clock->mysql(),
				'activation_state'         => ActivationState::INACTIVE->value,
				'ssl_state'                => $holds_resource
					? SslState::PENDING_REMOVAL->value
					: $mapping->ssl_state->value,
				'deletion_next_attempt_at' => $this->clock->mysql(),
				'updated_at'               => $this->clock->mysql(),
			),
			array( 'id' => $mapping->id )
		);

		if ( ! $holds_resource ) {
			$this->hard_delete( $mapping );
		}
	}

	public function process( Mapping $mapping ): string {
		$authorized = $this->authorizer->authorize( $mapping );

		if ( $authorized instanceof MutationRefusal ) {
			if ( ! $authorized->transient ) {
				$this->bump_attempts( $mapping );
			}

			return 'refused';
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return 'refused';
		}

		$context = SslResourceContext::from_mapping(
			$mapping,
			Environment::installation_id(),
			$name,
			$authorized->lease_token
		);

		$outcome = $this->gate->execute(
			$authorized,
			$mapping,
			fn( ExecutionPermit $permit ): RemovalResult => $this->driver->remove( $context, $permit )
		);

		if ( $outcome instanceof MutationRefusal ) {
			return 'refused';
		}

		$in_flight = $authorized->revision + 1;

		if ( RemovalOutcome::REMOVED === $outcome->outcome ) {
			$this->lease->finalize(
				$mapping->id,
				$in_flight,
				$authorized->lease_token,
				MutationKind::REMOVE,
				array( 'ssl_state' => SslState::REVOKED->value )
			);

			$this->hard_delete( $mapping );

			return 'removed';
		}

		if ( RemovalOutcome::FAILED === $outcome->outcome ) {
			$this->bump_attempts( $mapping );
		}

		$this->lease->finalize(
			$mapping->id,
			$in_flight,
			$authorized->lease_token,
			MutationKind::REMOVE,
			array( 'ssl_checked_at' => $this->clock->mysql() )
		);

		return strtolower( $outcome->outcome->value );
	}

	private function hard_delete( Mapping $mapping ): void {
		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			$mapping->ssl_state->value,
			'deleted',
			'cron',
			array( 'cleanup' => 'confirmed' )
		);

		$this->repo->delete( $mapping->id );
	}

	private function bump_attempts( Mapping $mapping ): void {
		global $wpdb;

		$table = Schema::domains_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table} SET deletion_attempts = deletion_attempts + 1, updated_at = %s WHERE id = %d",
				$this->clock->mysql(),
				$mapping->id
			)
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DeletionServiceTest`
Expected: PASS — 16 tests (six of them the lease-phase data provider)

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/DeletionService.php src/Ssl/ForceLocalDelete.php tests/integration/Ssl/DeletionServiceTest.php
git commit -m "Delete durably, and never force over a lease

Serving stops at the request; the row survives until the provider confirms.
Force-local-delete takes its own lease, so it cannot start from a row in any
phase — including an expired one, which belongs to recovery."
```

---

### Task 6: Reconciliation and the SSL sweep

**Files:**
- Create: `src/Ssl/Reconciler.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Ssl/ReconcilerTest.php`

**Interfaces:**
- Consumes: `SslDriver`, `LeaseRecovery`, `MappingRepository`.
- Produces: `Reconciler::run( SslDriver $driver, Mapping[] $mappings ): array{updated: int, divergences: int}` and `Plugin::sweep_ssl(): void`.

Reconciliation adopts provider truth for **state** only. It never deletes at the
provider, never adopts ownership, never auto-patches a method, and skips leased
rows (spec §14.17).

- [ ] **Step 1: Write the failing test**

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
		$this->repo = new DbRepository();
	}

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
				return new SslStatus( SslState::NONE );
			}

			public function adopt( SslResourceContext $c, ExecutionPermit $p ): SslStatus {
				return new SslStatus( SslState::NONE );
			}

			public function change_validation_method( SslResourceContext $c, string $m, ExecutionPermit $p ): SslStatus {
				return new SslStatus( SslState::NONE );
			}

			public function remove( SslResourceContext $c, ExecutionPermit $p ): RemovalResult {
				throw new \LogicException( 'reconciliation must never remove' );
			}

			public function reconcile( array $contexts ): ReconcileReport {
				return new ReconcileReport( $this->statuses, $this->complete, $this->complete ? null : 'pagination_failed' );
			}

			public function validation_plan( SslResourceContext $c, ?object $a ): ValidationPlan {
				return new ValidationPlan( array(), array(), array(), array(), array() );
			}
		};
	}

	private function mapping( SslState $state, ?string $method = 'txt' ): Mapping {
		global $wpdb;

		$mapping = $this->repo->save(
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
			array( 'id' => $mapping->id )
		);

		return $this->repo->by_id( $mapping->id );
	}

	public function test_provider_truth_updates_the_local_state(): void {
		$mapping = $this->mapping( SslState::PENDING_VALIDATION );
		$driver  = $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true );

		Reconciler::run( $driver, array( $mapping ) );

		$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $mapping->id )?->ssl_state );
	}

	public function test_an_incomplete_snapshot_never_infers_a_missing_resource(): void {
		$mapping = $this->mapping( SslState::ACTIVE );
		$driver  = $this->driver( array(), false );

		Reconciler::run( $driver, array( $mapping ) );

		$this->assertSame(
			SslState::ACTIVE,
			$this->repo->by_id( $mapping->id )?->ssl_state,
			'absence from an incomplete snapshot means nothing'
		);
	}

	public function test_a_transient_status_changes_nothing(): void {
		$mapping = $this->mapping( SslState::ACTIVE );
		$driver  = $this->driver(
			array( 'mapped.test' => new SslStatus( SslState::FAILED, 'ref-1', 'timeout', null, null, true ) ),
			true
		);

		Reconciler::run( $driver, array( $mapping ) );

		$this->assertSame( SslState::ACTIVE, $this->repo->by_id( $mapping->id )?->ssl_state );
	}

	public function test_a_divergent_method_is_reported_not_patched(): void {
		$mapping = $this->mapping( SslState::ACTIVE, 'txt' );
		$driver  = $this->driver(
			array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1', null, null, 'http' ) ),
			true
		);

		$result = Reconciler::run( $driver, array( $mapping ) );

		$this->assertSame( 'txt', $this->repo->by_id( $mapping->id )?->ssl_method );
		$this->assertGreaterThan( 0, $result['divergences'] );
		$this->assertNotEmpty( EventLog::for_domain( $mapping->id ) );
	}

	public function test_reconciliation_never_adopts_ownership(): void {
		global $wpdb;

		$mapping = $this->mapping( SslState::ACTIVE );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_ownership_origin' => null, 'ssl_owner_installation_id' => null, 'ssl_ref' => null ),
			array( 'id' => $mapping->id )
		);

		$driver = $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true );

		Reconciler::run( $driver, array( $this->repo->by_id( $mapping->id ) ) );

		$this->assertNull( $this->repo->by_id( $mapping->id )?->ssl_ownership_origin );
	}

	public function test_leased_rows_are_skipped(): void {
		global $wpdb;

		$mapping = $this->mapping( SslState::PENDING_VALIDATION );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '4', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			),
			array( 'id' => $mapping->id )
		);

		$driver = $this->driver( array( 'mapped.test' => new SslStatus( SslState::ACTIVE, 'ref-1' ) ), true );

		Reconciler::run( $driver, array( $this->repo->by_id( $mapping->id ) ) );

		$this->assertSame( SslState::PENDING_VALIDATION, $this->repo->by_id( $mapping->id )?->ssl_state );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter ReconcilerTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\Reconciler" not found`

- [ ] **Step 3: Write minimal implementation**

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
	 * @return array{updated: int, divergences: int}
	 */
	public static function run( SslDriver $driver, array $mappings ): array {
		global $wpdb;

		$contexts = array();
		$by_host  = array();

		foreach ( $mappings as $mapping ) {
			if ( null !== $mapping->ssl_mutation_token ) {
				continue;
			}

			$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

			if ( null === $name ) {
				continue;
			}

			$contexts[]                 = SslResourceContext::from_mapping( $mapping, Environment::installation_id(), $name );
			$by_host[ $mapping->host ] = $mapping;
		}

		if ( array() === $contexts ) {
			return array( 'updated' => 0, 'divergences' => 0 );
		}

		$report      = $driver->reconcile( $contexts );
		$updated     = 0;
		$divergences = 0;

		foreach ( $report->statuses as $host => $status ) {
			$mapping = $by_host[ $host ] ?? null;

			if ( null === $mapping || $status->transient ) {
				continue;
			}

			if ( null !== $status->confirmed_method && $status->confirmed_method !== $mapping->ssl_method ) {
				++$divergences;

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

			$wpdb->update( // phpcs:ignore WordPress.DB
				Schema::domains_table(),
				array(
					'ssl_state'      => $status->state->value,
					'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ),
					'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $mapping->id )
			);

			++$updated;
		}

		if ( ! $report->snapshot_complete ) {
			EventLog::record(
				0,
				'',
				'ssl',
				null,
				null,
				'cron',
				array( 'snapshot_incomplete' => $report->incomplete_reason )
			);
		}

		return array( 'updated' => $updated, 'divergences' => $divergences );
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
		$recovery = new \PostDomain\Ssl\LeaseRecovery(
			new \PostDomain\Ssl\MutationLease( new \PostDomain\Support\SystemClock() ),
			new \PostDomain\Support\SystemClock()
		);

		foreach ( $recovery->due( 50 ) as $mapping ) {
			$recovery->recover( $mapping, static fn(): array => array( 'resolved' => false ) );
		}
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter ReconcilerTest`
Expected: PASS — 6 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Ssl/Reconciler.php src/Plugin.php tests/integration/Ssl/ReconcilerTest.php
git commit -m "Reconcile provider state without adopting, deleting, or patching

An incomplete snapshot proves nothing, so absence from it never becomes a
missing resource. A divergent validation method is reported, never fixed
silently."
```

---

## Gate for Plan 08

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: every ambiguous outcome test resolves by a provider read;
`DeletionServiceTest` proves force-local-delete cannot overwrite a lease in any
of the six phase-and-expiry combinations; `AdoptionTest` proves provisioning
never adopts.
