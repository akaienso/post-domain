# post-domain 07 — SSL lease, gate, and authorization

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No provider mutation can begin without an authorization consumed by a
database phase transition, and no expired lease can be taken over by ordinary work.

**Architecture:** Identity is what the provider says a resource is; authorization
is whether this installation may change it. The driver answers the first, a
plugin-owned gate outside every driver answers the second. Ownership provenance is
column state, never reconstructed from events.

**Tech Stack:** As Plans 01–06.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–06, and add:

- **Ownership authority is `ssl_ownership_origin IS NOT NULL` AND
  `ssl_owner_installation_id === pd_installation_id`.** No event query
  participates in any authorization decision (spec §12.2).
- **Ordinary lease acquisition requires `ssl_mutation_token IS NULL`.** A non-null
  token blocks it regardless of phase and regardless of expiry (spec §12.6).
- **Ordinary work skips every leased row, expired or not.** Only `LeaseRecovery`
  finds work by lease expiry (spec §12.6).
- **The `RESERVED → IN_FLIGHT` CAS is the consumption point,** performed before
  any provider call. Zero rows affected means the provider is never called
  (spec §12.6).
- **Mutating driver methods are callable only through `MutationGate`** and receive
  an `ExecutionPermit`, never an unconsumed authorization (spec §14.3).
- **One local mutation execution per authorization.** Exactly-once external
  behaviour is not promised (spec §12.6).
- **`SslState` never gates serving** (spec §12.7).
- **Credentials never appear in a row, a response, an event, or a log**
  (spec §14.11).

---

## File map

| File | Responsibility |
|---|---|
| `src/Ssl/SslResourceContext.php` | Everything a provider call needs, built from the leased row |
| `src/Ssl/IdentityVerdict.php` | `MATCH`, `RECOVERABLE_CREATE`, `MISMATCH`, `ABSENT`, `AMBIGUOUS`, `UNKNOWN` |
| `src/Ssl/MarkerSupport.php` | `SUPPORTED`, `UNAVAILABLE`, `UNKNOWN` |
| `src/Ssl/ProviderMarker.php` | Parsed provider-side marker |
| `src/Ssl/IdentityResult.php` | Expected vs observed identity, completeness, transience |
| `src/Ssl/SslStatus.php` | State plus sanitized provider detail |
| `src/Ssl/RemovalOutcome.php`, `src/Ssl/RemovalResult.php` | Removal outcome, distinct from "we asked" |
| `src/Ssl/ReconcileReport.php` | Statuses plus `snapshot_complete` |
| `src/Ssl/DriverCapabilities.php` | What a driver can do |
| `src/Contracts/SslDriver.php` | The provider contract |
| `src/Ssl/NullDriver.php` | The default: certificates handled outside the plugin |
| `src/Ssl/SslDriverRegistry.php` | Stored-provider resolution |
| `src/Ssl/MutationLease.php` | Acquire, phase transitions, release |
| `src/Ssl/ExecutionPermit.php` | Proof that consumption already happened |
| `src/Ssl/MutationAuthorization.php`, `src/Ssl/MutationRefusal.php` | Bound authorization and its refusal |
| `src/Ssl/MutationGate.php` | The only caller of mutating driver methods |
| `src/Ssl/LeaseRecovery.php` | The only claimant of expired leases |
| `src/Ssl/DeletionAuthorizer.php` | The six preconditions |
| `src/Ssl/Environment.php` | Installation identity, clone detection |
| `src/Ssl/Cooldown.php` | Provider-wide `Retry-After` cooldowns |

---

### Task 1: Resource context and identity

**Files:**
- Create: `src/Ssl/SslResourceContext.php`, `src/Ssl/IdentityVerdict.php`, `src/Ssl/MarkerSupport.php`, `src/Ssl/ProviderMarker.php`, `src/Ssl/IdentityResult.php`
- Test: `tests/unit/Ssl/IdentityResultTest.php`

**Interfaces:**
- Consumes: `Mapping`, `OwnershipOrigin` (Plan 02).
- Produces:
  - `PostDomain\Ssl\SslResourceContext` — readonly `int $mapping_id`, `string $host`, `string $installation_id`, `string $provider_id`, `?string $provider_ref`, `?OwnershipOrigin $ownership_origin`, `?string $owner_installation_id`, `string $challenge_name`, `string $challenge_value`, `int $revision`, `?string $lease_token`, `?string $requested_method`; plus `::from_mapping( Mapping $m, string $installation_id, string $challenge_name, ?string $lease_token ): self`.
  - `IdentityVerdict`, `MarkerSupport`, `ProviderMarker`, and `IdentityResult` with `::is_owned_by( string $installation_id ): bool`.

`MATCH` requires `read_complete`, a non-null `expected_ref`, an exact reference
match, an exact hostname match, and no conflicting marker.
`RECOVERABLE_CREATE` is reachable **only** when `expected_ref` is null (spec §14.2).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/IdentityResultTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\ProviderMarker;

final class IdentityResultTest extends TestCase {

	private function result( array $overrides = array() ): IdentityResult {
		return new IdentityResult(
			$overrides['verdict'] ?? IdentityVerdict::MATCH,
			$overrides['expected_ref'] ?? 'ref-1',
			$overrides['observed_ref'] ?? 'ref-1',
			$overrides['observed_hostname'] ?? 'mapped.test',
			$overrides['marker'] ?? null,
			$overrides['marker_support'] ?? MarkerSupport::UNAVAILABLE,
			$overrides['read_complete'] ?? true,
			$overrides['transient'] ?? false
		);
	}

	public function test_a_complete_exact_match_is_owned(): void {
		$this->assertTrue( $this->result()->is_usable_for_mutation( 'mapped.test' ) );
	}

	public function test_an_incomplete_read_is_not_usable(): void {
		$this->assertFalse(
			$this->result( array( 'read_complete' => false ) )->is_usable_for_mutation( 'mapped.test' )
		);
	}

	public function test_a_reference_mismatch_is_not_usable(): void {
		$this->assertFalse(
			$this->result( array( 'observed_ref' => 'ref-2' ) )->is_usable_for_mutation( 'mapped.test' )
		);
	}

	public function test_a_hostname_mismatch_is_not_usable(): void {
		$this->assertFalse(
			$this->result( array( 'observed_hostname' => 'other.test' ) )->is_usable_for_mutation( 'mapped.test' )
		);
	}

	public function test_a_transient_result_is_not_usable(): void {
		$this->assertFalse(
			$this->result( array( 'transient' => true ) )->is_usable_for_mutation( 'mapped.test' )
		);
	}

	public function test_a_non_match_verdict_is_not_usable(): void {
		foreach (
			array(
				IdentityVerdict::MISMATCH,
				IdentityVerdict::ABSENT,
				IdentityVerdict::AMBIGUOUS,
				IdentityVerdict::UNKNOWN,
				IdentityVerdict::RECOVERABLE_CREATE,
			) as $verdict
		) {
			$this->assertFalse(
				$this->result( array( 'verdict' => $verdict ) )->is_usable_for_mutation( 'mapped.test' )
			);
		}
	}

	public function test_a_marker_naming_this_installation_and_mapping_matches(): void {
		$marker = new ProviderMarker( 'install-a', 12, array() );

		$this->assertTrue( $marker->names( 'install-a', 12 ) );
		$this->assertFalse( $marker->names( 'install-b', 12 ) );
		$this->assertFalse( $marker->names( 'install-a', 13 ) );
	}

	public function test_a_foreign_marker_conflicts(): void {
		$result = $this->result(
			array( 'marker' => new ProviderMarker( 'other-install', 12, array() ) )
		);

		$this->assertTrue( $result->has_conflicting_marker( 'install-a', 12 ) );
	}

	public function test_a_matching_marker_does_not_conflict(): void {
		$result = $this->result( array( 'marker' => new ProviderMarker( 'install-a', 12, array() ) ) );

		$this->assertFalse( $result->has_conflicting_marker( 'install-a', 12 ) );
	}

	public function test_an_absent_marker_never_conflicts(): void {
		$this->assertFalse(
			$this->result( array( 'marker' => null ) )->has_conflicting_marker( 'install-a', 12 ),
			'an absent marker establishes nothing either way'
		);
	}

	public function test_recoverable_create_requires_a_null_expected_ref(): void {
		$valid = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE, null, 'ref-9', 'mapped.test',
			new ProviderMarker( 'install-a', 12, array() ), MarkerSupport::SUPPORTED, true, false
		);

		$this->assertTrue( $valid->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );

		$bound = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE, 'ref-1', 'ref-9', 'mapped.test',
			new ProviderMarker( 'install-a', 12, array() ), MarkerSupport::SUPPORTED, true, false
		);

		$this->assertFalse(
			$bound->is_recoverable_create( 'install-a', 12, 'mapped.test' ),
			'recovery applies only to an unbound reference'
		);
	}

	public function test_recoverable_create_requires_a_marker_naming_this_mapping(): void {
		$unmarked = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE, null, 'ref-9', 'mapped.test',
			null, MarkerSupport::UNAVAILABLE, true, false
		);

		$this->assertFalse( $unmarked->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter IdentityResultTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\IdentityResult" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/IdentityVerdict.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum IdentityVerdict: string {
	case MATCH              = 'match';
	case RECOVERABLE_CREATE = 'recoverable_create';
	case MISMATCH           = 'mismatch';
	case ABSENT             = 'absent';
	case AMBIGUOUS          = 'ambiguous';
	case UNKNOWN            = 'unknown';
}
```

Create `src/Ssl/MarkerSupport.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MarkerSupport: string {
	case SUPPORTED   = 'supported';
	case UNAVAILABLE = 'unavailable';
	case UNKNOWN     = 'unknown';
}
```

Create `src/Ssl/ProviderMarker.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ProviderMarker {

	/** @param array<string, mixed> $raw */
	public function __construct(
		public readonly ?string $installation_id,
		public readonly ?int $mapping_id,
		public readonly array $raw
	) {}

	public function names( string $installation_id, int $mapping_id ): bool {
		return $this->installation_id === $installation_id && $this->mapping_id === $mapping_id;
	}
}
```

Create `src/Ssl/IdentityResult.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class IdentityResult {

	public function __construct(
		public readonly IdentityVerdict $verdict,
		public readonly ?string $expected_ref,
		public readonly ?string $observed_ref,
		public readonly ?string $observed_hostname,
		public readonly ?ProviderMarker $marker,
		public readonly MarkerSupport $marker_support,
		public readonly bool $read_complete,
		public readonly bool $transient,
		public readonly ?string $code = null,
		public readonly ?string $message = null
	) {}

	/** The strict rule for an already-bound resource. Never relaxed. */
	public function is_usable_for_mutation( string $expected_host ): bool {
		return IdentityVerdict::MATCH === $this->verdict
			&& $this->read_complete
			&& ! $this->transient
			&& null !== $this->expected_ref
			&& $this->observed_ref === $this->expected_ref
			&& $this->observed_hostname === $expected_host;
	}

	/** Reachable only while the reference is unbound. */
	public function is_recoverable_create( string $installation_id, int $mapping_id, string $expected_host ): bool {
		return IdentityVerdict::RECOVERABLE_CREATE === $this->verdict
			&& $this->read_complete
			&& ! $this->transient
			&& null === $this->expected_ref
			&& $this->observed_hostname === $expected_host
			&& null !== $this->marker
			&& $this->marker->names( $installation_id, $mapping_id );
	}

	/** An absent marker establishes nothing either way, so it never conflicts. */
	public function has_conflicting_marker( string $installation_id, int $mapping_id ): bool {
		if ( null === $this->marker ) {
			return false;
		}

		if ( null === $this->marker->installation_id && null === $this->marker->mapping_id ) {
			return false;
		}

		return ! $this->marker->names( $installation_id, $mapping_id );
	}
}
```

Create `src/Ssl/SslResourceContext.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;

final class SslResourceContext {

	public function __construct(
		public readonly int $mapping_id,
		public readonly string $host,
		public readonly string $installation_id,
		public readonly string $provider_id,
		public readonly ?string $provider_ref,
		public readonly ?OwnershipOrigin $ownership_origin,
		public readonly ?string $owner_installation_id,
		public readonly string $challenge_name,
		public readonly string $challenge_value,
		public readonly int $revision,
		public readonly ?string $lease_token = null,
		public readonly ?string $requested_method = null
	) {}

	public static function from_mapping(
		Mapping $mapping,
		string $installation_id,
		string $challenge_name,
		?string $lease_token = null
	): self {
		return new self(
			$mapping->id,
			$mapping->host,
			$installation_id,
			$mapping->ssl_provider ?? 'null',
			$mapping->ssl_ref,
			$mapping->ssl_ownership_origin,
			$mapping->ssl_owner_installation_id,
			$challenge_name,
			'post-domain-verify=' . $mapping->challenge,
			$mapping->revision,
			$lease_token,
			$mapping->ssl_method
		);
	}

	/** Column state only: no event query participates in this answer. */
	public function has_ownership_authority(): bool {
		return null !== $this->ownership_origin
			&& null !== $this->owner_installation_id
			&& $this->owner_installation_id === $this->installation_id;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter IdentityResultTest`
Expected: PASS — 12 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/SslResourceContext.php src/Ssl/IdentityVerdict.php src/Ssl/MarkerSupport.php src/Ssl/ProviderMarker.php src/Ssl/IdentityResult.php tests/unit/Ssl/IdentityResultTest.php
git commit -m "Separate provider identity from mutation authorization

A marker naming this installation is additional evidence; a foreign one blocks;
an absent one establishes nothing. Ownership authority reads columns only."
```

---

### Task 2: The driver contract and the null driver

**Files:**
- Create: `src/Ssl/SslStatus.php`, `src/Ssl/RemovalOutcome.php`, `src/Ssl/RemovalResult.php`, `src/Ssl/ReconcileReport.php`, `src/Ssl/DriverCapabilities.php`, `src/Contracts/SslDriver.php`, `src/Ssl/NullDriver.php`
- Test: `tests/unit/Ssl/NullDriverTest.php`

**Interfaces:**
- Consumes: Task 1's types.
- Produces: `PostDomain\Contracts\SslDriver` with `id()`, `capabilities()`, `status()`, `identify()`, `create()`, `adopt()`, `change_validation_method()`, `remove()`, `reconcile()`, `validation_plan()`; and `NullDriver` implementing it.

Mutating methods take an `ExecutionPermit` (Task 5), never an unconsumed
authorization (spec §14.3).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/NullDriverTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\SslResourceContext;

final class NullDriverTest extends TestCase {

	private function context(): SslResourceContext {
		return new SslResourceContext(
			12, 'mapped.test', 'install-a', 'null', null, null, null,
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 3
		);
	}

	private function permit( MutationKind $kind ): ExecutionPermit {
		return new ExecutionPermit(
			$kind,
			12,
			4,
			str_repeat( '7', 32 ),
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_the_id_is_stable(): void {
		$this->assertSame( 'null', ( new NullDriver() )->id() );
	}

	public function test_it_declares_no_marker_support_and_no_validation_methods(): void {
		$capabilities = ( new NullDriver() )->capabilities();

		$this->assertFalse( $capabilities->supports_markers );
		$this->assertSame( array(), $capabilities->validation_methods );
		$this->assertFalse( $capabilities->supports_apex_proxy_targets );
	}

	public function test_status_reports_none_and_says_it_is_handled_elsewhere(): void {
		$status = ( new NullDriver() )->status( $this->context() );

		$this->assertSame( SslState::NONE, $status->state );
		$this->assertStringContainsString( 'outside', (string) $status->message );
	}

	public function test_identity_is_absent_and_complete(): void {
		$identity = ( new NullDriver() )->identify( $this->context() );

		$this->assertSame( IdentityVerdict::ABSENT, $identity->verdict );
		$this->assertTrue( $identity->read_complete );
		$this->assertFalse( $identity->transient );
	}

	public function test_create_changes_nothing(): void {
		$status = ( new NullDriver() )->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertSame( SslState::NONE, $status->state );
		$this->assertNull( $status->ref );
	}

	public function test_removal_reports_removed_because_nothing_exists(): void {
		$result = ( new NullDriver() )->remove( $this->context(), $this->permit( MutationKind::REMOVE ) );

		$this->assertSame( RemovalOutcome::REMOVED, $result->outcome );
	}

	public function test_a_permit_of_the_wrong_kind_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new NullDriver() )->remove( $this->context(), $this->permit( MutationKind::CREATE ) );
	}

	public function test_reconcile_reports_a_complete_empty_snapshot(): void {
		$report = ( new NullDriver() )->reconcile( array( $this->context() ) );

		$this->assertTrue( $report->snapshot_complete );
		$this->assertSame( array(), iterator_to_array( $report->statuses ) );
	}

	public function test_the_validation_plan_contributes_no_provider_records(): void {
		$plan = ( new NullDriver() )->validation_plan( $this->context(), null );

		$this->assertSame( array(), $plan->http );
		$this->assertSame( array(), $plan->blockers );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter NullDriverTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\NullDriver" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/SslStatus.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\SslState;

final class SslStatus {

	/** @param array<string, mixed>|null $provider_state */
	public function __construct(
		public readonly SslState $state,
		public readonly ?string $ref = null,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?string $confirmed_method = null,
		public readonly bool $transient = false,
		public readonly ?array $provider_state = null
	) {}
}
```

Create `src/Ssl/RemovalOutcome.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum RemovalOutcome: string {
	case REMOVED   = 'removed';
	case PENDING   = 'pending';
	case TRANSIENT = 'transient';
	case FAILED    = 'failed';
}
```

Create `src/Ssl/RemovalResult.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class RemovalResult {

	public function __construct(
		public readonly RemovalOutcome $outcome,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?int $retry_after = null
	) {}
}
```

Create `src/Ssl/ReconcileReport.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ReconcileReport {

	/** @param iterable<string, SslStatus> $statuses */
	public function __construct(
		public readonly iterable $statuses,
		public readonly bool $snapshot_complete,
		public readonly ?string $incomplete_reason = null
	) {}
}
```

Create `src/Ssl/DriverCapabilities.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DriverCapabilities {

	/** @param string[] $validation_methods */
	public function __construct(
		public readonly bool $supports_markers,
		public readonly array $validation_methods,
		public readonly bool $supports_apex_proxy_targets
	) {}
}
```

Create `src/Contracts/SslDriver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;

/**
 * Mutating methods take an ExecutionPermit — proof that the authorization was
 * already consumed by the RESERVED -> IN_FLIGHT transition. They are invoked
 * only by MutationGate.
 */
interface SslDriver {

	public function id(): string;

	public function capabilities(): DriverCapabilities;

	public function status( SslResourceContext $ctx ): SslStatus;

	public function identify( SslResourceContext $ctx ): IdentityResult;

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus;

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus;

	public function change_validation_method(
		SslResourceContext $ctx,
		string $method,
		ExecutionPermit $permit
	): SslStatus;

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult;

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport;

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan;
}
```

Create `src/Ssl/NullDriver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\SslState;

/**
 * The default. Where certificates are handled outside the plugin, the plugin has
 * no idea what the domain should point at, so it contributes no records.
 */
final class NullDriver implements SslDriver {

	public function id(): string {
		return 'null';
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities( false, array(), false );
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		unset( $ctx );

		return new SslStatus( SslState::NONE, null, 'handled_externally', 'Certificates are handled outside this plugin.' );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		return new IdentityResult(
			IdentityVerdict::ABSENT,
			$ctx->provider_ref,
			null,
			null,
			null,
			MarkerSupport::UNAVAILABLE,
			true,
			false
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( MutationKind::CREATE );

		return $this->status( $ctx );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( MutationKind::ADOPT );

		return $this->status( $ctx );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( MutationKind::METHOD );
		unset( $method );

		return $this->status( $ctx );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_kind( MutationKind::REMOVE );
		unset( $ctx );

		return new RemovalResult( RemovalOutcome::REMOVED, 'nothing_to_remove' );
	}

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport {
		unset( $contexts );

		return new ReconcileReport( array(), true );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		unset( $ctx, $apex );

		return new ValidationPlan( array(), array(), array(), array(), array() );
	}
}
```

Create `src/Ssl/ValidationPlan.php` as the minimum this task needs; Plan 09
extends it:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPlan {

	/**
	 * @param array<string, array<int, mixed>> $dns
	 * @param array<int, mixed>                $http
	 * @param array<int, mixed>                $manual
	 * @param array<int, mixed>                $pending
	 * @param array<int, mixed>                $blockers
	 */
	public function __construct(
		public readonly array $dns,
		public readonly array $http,
		public readonly array $manual,
		public readonly array $pending,
		public readonly array $blockers
	) {}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter NullDriverTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/SslStatus.php src/Ssl/RemovalOutcome.php src/Ssl/RemovalResult.php src/Ssl/ReconcileReport.php src/Ssl/DriverCapabilities.php src/Ssl/ValidationPlan.php src/Contracts/SslDriver.php src/Ssl/NullDriver.php tests/unit/Ssl/NullDriverTest.php
git commit -m "Define the SSL driver contract with a working null default

remove() returns an enum rather than void so 'gone' and 'we asked' stay
distinct, and every mutating method asserts its permit's kind before acting."
```

---

### Task 3: The mutation lease

**Files:**
- Create: `src/Ssl/MutationLease.php`
- Test: `tests/integration/Ssl/MutationLeaseTest.php`

**Interfaces:**
- Consumes: `Schema` (Plan 02), `Clock` (Plan 02), `MutationKind`, `MutationPhase` (Plan 02).
- Produces: `PostDomain\Ssl\MutationLease::__construct( Clock $clock )` with `::acquire( int $mapping_id, int $revision, MutationKind $kind, int $ttl ): ?array{token: string, revision: int}`, `::consume( array $bound ): ?int`, `::finalize( int $mapping_id, int $revision, string $token, MutationKind $kind, array $columns ): bool`, `::clear_expired_reserved( int $mapping_id, string $token ): bool`, `::claim_recovery( int $mapping_id, string $old_token, int $ttl ): ?string`.

Acquisition requires `ssl_mutation_token IS NULL`, so expiry never frees a row
for ordinary work (spec §12.6).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/MutationLeaseTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use WP_UnitTestCase;

final class MutationLeaseTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationLease $lease;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo  = new DbRepository();
		$this->lease = new MutationLease( new SystemClock() );
	}

	private function seed(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
	}

	private function force_lease( int $id, MutationPhase $phase, int $expires_offset ): string {
		global $wpdb;

		$token = bin2hex( random_bytes( 16 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => $token,
				'ssl_mutation_kind'       => MutationKind::REMOVE->value,
				'ssl_mutation_phase'      => $phase->value,
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expires_offset ),
			),
			array( 'id' => $id )
		);

		return $token;
	}

	public function test_acquiring_a_lease_on_a_free_row_succeeds(): void {
		$mapping = $this->seed();

		$lease = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, 120 );

		$this->assertNotNull( $lease );
		$this->assertSame( $mapping->revision + 1, $lease['revision'] );
		$this->assertSame( MutationPhase::RESERVED, $this->repo->by_id( $mapping->id )?->ssl_mutation_phase );
	}

	public function test_acquiring_against_a_stale_revision_fails(): void {
		$mapping = $this->seed();

		$this->assertNull( $this->lease->acquire( $mapping->id, $mapping->revision + 5, MutationKind::CREATE, 120 ) );
	}

	public function test_a_second_acquisition_while_reserved_fails(): void {
		$mapping = $this->seed();
		$first   = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, 120 );

		$this->assertNull( $this->lease->acquire( $mapping->id, $first['revision'], MutationKind::CREATE, 120 ) );
	}

	/**
	 * @dataProvider phases
	 */
	public function test_acquisition_against_an_expired_lease_fails_in_every_phase( string $phase ): void {
		$mapping = $this->seed();
		$this->force_lease( $mapping->id, MutationPhase::from( $phase ), -600 );

		$current = $this->repo->by_id( $mapping->id );

		$this->assertNull(
			$this->lease->acquire( $mapping->id, (int) $current?->revision, MutationKind::CREATE, 120 ),
			'expiry transfers the row to recovery, it does not free it'
		);
	}

	/** @return array<string, array{0: string}> */
	public static function phases(): array {
		return array(
			'reserved'   => array( 'reserved' ),
			'in flight'  => array( 'in_flight' ),
			'recovering' => array( 'recovering' ),
		);
	}

	public function test_consuming_moves_reserved_to_in_flight(): void {
		$mapping = $this->seed();
		$lease   = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, 120 );
		$current = $this->repo->by_id( $mapping->id );

		$revision = $this->lease->consume(
			array(
				'mapping_id' => $mapping->id,
				'revision'   => $lease['revision'],
				'token'      => $lease['token'],
				'kind'       => MutationKind::CREATE,
				'host'       => $current->host,
				'provider'   => $current->ssl_provider,
				'ref'        => $current->ssl_ref,
				'challenge'  => $current->challenge,
				'method'     => $current->ssl_method,
			)
		);

		$this->assertNotNull( $revision );
		$this->assertSame( MutationPhase::IN_FLIGHT, $this->repo->by_id( $mapping->id )?->ssl_mutation_phase );
	}

	public function test_consuming_twice_fails_the_second_time(): void {
		$mapping = $this->seed();
		$lease   = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, 120 );
		$current = $this->repo->by_id( $mapping->id );

		$bound = array(
			'mapping_id' => $mapping->id,
			'revision'   => $lease['revision'],
			'token'      => $lease['token'],
			'kind'       => MutationKind::CREATE,
			'host'       => $current->host,
			'provider'   => $current->ssl_provider,
			'ref'        => $current->ssl_ref,
			'challenge'  => $current->challenge,
			'method'     => $current->ssl_method,
		);

		$this->assertNotNull( $this->lease->consume( $bound ) );
		$this->assertNull( $this->lease->consume( $bound ), 'one execution per authorization' );
	}

	public function test_consuming_fails_when_the_challenge_changed(): void {
		global $wpdb;

		$mapping = $this->seed();
		$lease   = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, 120 );
		$current = $this->repo->by_id( $mapping->id );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'challenge' => str_repeat( 'z', 32 ) ),
			array( 'id' => $mapping->id )
		);

		$this->assertNull(
			$this->lease->consume(
				array(
					'mapping_id' => $mapping->id,
					'revision'   => $lease['revision'],
					'token'      => $lease['token'],
					'kind'       => MutationKind::CREATE,
					'host'       => $current->host,
					'provider'   => $current->ssl_provider,
					'ref'        => $current->ssl_ref,
					'challenge'  => $current->challenge,
					'method'     => $current->ssl_method,
				)
			)
		);
	}

	public function test_finalize_applies_the_result_and_clears_the_lease_in_one_transition(): void {
		$mapping = $this->seed();
		$lease   = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::CREATE, 120 );
		$current = $this->repo->by_id( $mapping->id );

		$in_flight = $this->lease->consume(
			array(
				'mapping_id' => $mapping->id,
				'revision'   => $lease['revision'],
				'token'      => $lease['token'],
				'kind'       => MutationKind::CREATE,
				'host'       => $current->host,
				'provider'   => $current->ssl_provider,
				'ref'        => $current->ssl_ref,
				'challenge'  => $current->challenge,
				'method'     => $current->ssl_method,
			)
		);

		$this->assertTrue(
			$this->lease->finalize(
				$mapping->id,
				(int) $in_flight,
				$lease['token'],
				MutationKind::CREATE,
				array( 'ssl_state' => SslState::REQUESTED->value, 'ssl_ref' => 'ref-1' )
			)
		);

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( SslState::REQUESTED, $after?->ssl_state );
		$this->assertNull( $after?->ssl_mutation_token );
		$this->assertNull( $after?->ssl_mutation_phase );
	}

	public function test_finalize_fails_under_a_replaced_token(): void {
		$mapping = $this->seed();
		$lease   = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE, 120 );
		$current = $this->repo->by_id( $mapping->id );

		$in_flight = $this->lease->consume(
			array(
				'mapping_id' => $mapping->id,
				'revision'   => $lease['revision'],
				'token'      => $lease['token'],
				'kind'       => MutationKind::REMOVE,
				'host'       => $current->host,
				'provider'   => $current->ssl_provider,
				'ref'        => $current->ssl_ref,
				'challenge'  => $current->challenge,
				'method'     => $current->ssl_method,
			)
		);

		$this->force_lease( $mapping->id, MutationPhase::RECOVERING, 120 );

		$this->assertFalse(
			$this->lease->finalize(
				$mapping->id,
				(int) $in_flight,
				$lease['token'],
				MutationKind::REMOVE,
				array( 'ssl_state' => SslState::REVOKED->value )
			),
			'a fenced worker cannot apply its result'
		);
	}

	public function test_an_expired_reserved_lease_clears_without_a_provider_read(): void {
		$mapping = $this->seed();
		$token   = $this->force_lease( $mapping->id, MutationPhase::RESERVED, -600 );

		$this->assertTrue( $this->lease->clear_expired_reserved( $mapping->id, $token ) );
		$this->assertNull( $this->repo->by_id( $mapping->id )?->ssl_mutation_token );
	}

	public function test_clearing_refuses_an_unexpired_reserved_lease(): void {
		$mapping = $this->seed();
		$token   = $this->force_lease( $mapping->id, MutationPhase::RESERVED, 600 );

		$this->assertFalse( $this->lease->clear_expired_reserved( $mapping->id, $token ) );
	}

	public function test_clearing_refuses_an_in_flight_lease(): void {
		$mapping = $this->seed();
		$token   = $this->force_lease( $mapping->id, MutationPhase::IN_FLIGHT, -600 );

		$this->assertFalse(
			$this->lease->clear_expired_reserved( $mapping->id, $token ),
			'an in-flight lease may have reached the provider'
		);
	}

	public function test_claiming_recovery_replaces_the_token_and_preserves_the_kind(): void {
		$mapping = $this->seed();
		$old     = $this->force_lease( $mapping->id, MutationPhase::IN_FLIGHT, -600 );

		$new = $this->lease->claim_recovery( $mapping->id, $old, 300 );

		$this->assertNotNull( $new );
		$this->assertNotSame( $old, $new );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( MutationPhase::RECOVERING, $after?->ssl_mutation_phase );
		$this->assertSame( MutationKind::REMOVE, $after?->ssl_mutation_kind );
	}

	public function test_claiming_recovery_refuses_an_unexpired_in_flight_lease(): void {
		$mapping = $this->seed();
		$token   = $this->force_lease( $mapping->id, MutationPhase::IN_FLIGHT, 600 );

		$this->assertNull( $this->lease->claim_recovery( $mapping->id, $token, 300 ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter MutationLeaseTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\MutationLease" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/MutationLease.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Support\Schema;

final class MutationLease {

	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Permitted only when the row carries NO lease. Expiry never frees a row for
	 * ordinary work; it transfers the row to LeaseRecovery.
	 *
	 * @return array{token: string, revision: int}|null
	 */
	public function acquire( int $mapping_id, int $revision, MutationKind $kind, int $ttl ): ?array {
		global $wpdb;

		$table   = Schema::domains_table();
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + $ttl );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = %s, ssl_mutation_kind = %s,
				        ssl_mutation_phase = %s, ssl_mutation_expires_at = %s,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
				$token,
				$kind->value,
				MutationPhase::RESERVED->value,
				$expires,
				$this->clock->mysql(),
				$mapping_id,
				$revision
			)
		);

		return 1 === $affected ? array( 'token' => $token, 'revision' => $revision + 1 ) : null;
	}

	/**
	 * The consumption point: RESERVED -> IN_FLIGHT, before any provider call.
	 *
	 * @param array{mapping_id: int, revision: int, token: string, kind: MutationKind, host: string, provider: string|null, ref: string|null, challenge: string, method: string|null} $bound
	 * @return int|null The in-flight revision, or null when the provider must not be called.
	 */
	public function consume( array $bound ): ?int {
		global $wpdb;

		$table = Schema::domains_table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_phase = %s, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d
				    AND ssl_mutation_token = %s AND ssl_mutation_kind = %s
				    AND ssl_mutation_phase = %s AND ssl_mutation_expires_at > %s
				    AND host = %s
				    AND ( ssl_provider <=> %s ) AND ( ssl_ref <=> %s )
				    AND challenge = %s AND ( ssl_method <=> %s )",
				MutationPhase::IN_FLIGHT->value,
				$this->clock->mysql(),
				$bound['mapping_id'],
				$bound['revision'],
				$bound['token'],
				$bound['kind']->value,
				MutationPhase::RESERVED->value,
				$this->clock->mysql(),
				$bound['host'],
				$bound['provider'],
				$bound['ref'],
				$bound['challenge'],
				$bound['method']
			)
		);

		return 1 === $affected ? $bound['revision'] + 1 : null;
	}

	/**
	 * Applies the result and clears the lease in one transition.
	 *
	 * @param array<string, string|int|null> $columns
	 */
	public function finalize(
		int $mapping_id,
		int $in_flight_revision,
		string $token,
		MutationKind $kind,
		array $columns
	): bool {
		global $wpdb;

		$table  = Schema::domains_table();
		$sets   = array();
		$values = array();

		foreach ( $columns as $column => $value ) {
			$sets[]   = "{$column} = %s";
			$values[] = $value;
		}

		$sets[] = 'ssl_mutation_token = NULL';
		$sets[] = 'ssl_mutation_kind = NULL';
		$sets[] = 'ssl_mutation_phase = NULL';
		$sets[] = 'ssl_mutation_expires_at = NULL';
		$sets[] = 'revision = revision + 1';
		$sets[] = 'updated_at = %s';

		$values[] = $this->clock->mysql();
		$values[] = $mapping_id;
		$values[] = $in_flight_revision;
		$values[] = $token;
		$values[] = $kind->value;
		$values[] = MutationPhase::IN_FLIGHT->value;

		$sql = "UPDATE {$table} SET " . implode( ', ', $sets )
			. ' WHERE id = %d AND revision = %d AND ssl_mutation_token = %s'
			. ' AND ssl_mutation_kind = %s AND ssl_mutation_phase = %s';

		return 1 === $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB
	}

	/** An expired RESERVED lease proves nothing was sent. */
	public function clear_expired_reserved( int $mapping_id, string $token ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = NULL, ssl_mutation_kind = NULL,
				        ssl_mutation_phase = NULL, ssl_mutation_expires_at = NULL,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND ssl_mutation_token = %s
				    AND ssl_mutation_phase = %s AND ssl_mutation_expires_at <= %s",
				$this->clock->mysql(),
				$mapping_id,
				$token,
				MutationPhase::RESERVED->value,
				$this->clock->mysql()
			)
		);
	}

	/** Fences the original worker before any provider read. */
	public function claim_recovery( int $mapping_id, string $old_token, int $ttl ): ?string {
		global $wpdb;

		$table = Schema::domains_table();
		$new   = bin2hex( random_bytes( 16 ) );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = %s, ssl_mutation_phase = %s,
				        ssl_mutation_expires_at = %s, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND ssl_mutation_token = %s
				    AND ssl_mutation_phase IN (%s, %s) AND ssl_mutation_expires_at <= %s",
				$new,
				MutationPhase::RECOVERING->value,
				gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + $ttl ),
				$this->clock->mysql(),
				$mapping_id,
				$old_token,
				MutationPhase::IN_FLIGHT->value,
				MutationPhase::RECOVERING->value,
				$this->clock->mysql()
			)
		);

		return 1 === $affected ? $new : null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter MutationLeaseTest`
Expected: PASS — 15 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/MutationLease.php tests/integration/Ssl/MutationLeaseTest.php
git commit -m "Phase the provider-mutation lease and refuse to acquire over any lease

Acquisition requires a null token, so an expired IN_FLIGHT row cannot be taken
over by an ordinary worker and its external operation duplicated."
```

---

### Task 4: The execution permit and the mutation gate

**Files:**
- Create: `src/Ssl/ExecutionPermit.php`, `src/Ssl/MutationAuthorization.php`, `src/Ssl/MutationRefusal.php`, `src/Ssl/MutationGate.php`
- Test: `tests/integration/Ssl/MutationGateTest.php`

**Interfaces:**
- Consumes: `MutationLease` (Task 3), `SslDriver` (Task 2).
- Produces:
  - `PostDomain\Ssl\ExecutionPermit` — readonly `MutationKind $kind`, `int $mapping_id`, `int $in_flight_revision`, `string $lease_token`, `\DateTimeImmutable $expires_at`; plus `::assert_kind( MutationKind $expected ): void`.
  - `PostDomain\Ssl\MutationAuthorization` — the bound, unconsumed authorization.
  - `PostDomain\Ssl\MutationRefusal` — readonly `string $precondition`, `bool $transient`, `?string $detail`.
  - `PostDomain\Ssl\MutationGate::execute( MutationAuthorization $auth, Mapping $m, callable $call ): SslStatus|RemovalResult|MutationRefusal`.

**The gate is the only caller of mutating driver methods.** The provider is never
reached unless the `RESERVED → IN_FLIGHT` CAS affected exactly one row.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/MutationGateTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\MutationAuthorization;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use WP_UnitTestCase;

final class MutationGateTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationGate $gate;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
		$this->gate = new MutationGate( new MutationLease( new SystemClock() ) );
	}

	private function seed(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
	}

	private function authorization( Mapping $m, string $token, int $revision ): MutationAuthorization {
		return new MutationAuthorization(
			MutationKind::CREATE,
			$token,
			$m->id,
			$revision,
			$m->host,
			$m->ssl_provider,
			$m->ssl_ref,
			$m->challenge,
			$m->ssl_method,
			$m->ssl_ownership_origin,
			$m->ssl_owner_installation_id,
			false,
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_the_driver_is_reached_only_after_consumption(): void {
		$mapping = $this->seed();
		$lease   = ( new MutationLease( new SystemClock() ) )->acquire(
			$mapping->id,
			$mapping->revision,
			MutationKind::CREATE,
			120
		);

		$leased  = $this->repo->by_id( $mapping->id );
		$phases  = array();

		$result = $this->gate->execute(
			$this->authorization( $leased, $lease['token'], $lease['revision'] ),
			$leased,
			function ( ExecutionPermit $permit ) use ( &$phases, $mapping ): \PostDomain\Ssl\SslStatus {
				$phases[] = ( new DbRepository() )->by_id( $mapping->id )?->ssl_mutation_phase?->value;

				return new \PostDomain\Ssl\SslStatus( SslState::REQUESTED, 'ref-1' );
			}
		);

		$this->assertInstanceOf( \PostDomain\Ssl\SslStatus::class, $result );
		$this->assertSame(
			array( 'in_flight' ),
			$phases,
			'the phase is already in_flight when the driver runs'
		);
	}

	public function test_a_stale_authorization_never_reaches_the_driver(): void {
		$mapping = $this->seed();
		$lease   = ( new MutationLease( new SystemClock() ) )->acquire(
			$mapping->id,
			$mapping->revision,
			MutationKind::CREATE,
			120
		);

		$leased = $this->repo->by_id( $mapping->id );
		$calls  = 0;

		$auth = new MutationAuthorization(
			MutationKind::CREATE,
			$lease['token'],
			$mapping->id,
			$lease['revision'] + 7,
			$leased->host,
			$leased->ssl_provider,
			$leased->ssl_ref,
			$leased->challenge,
			$leased->ssl_method,
			null,
			null,
			false,
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->gate->execute(
			$auth,
			$leased,
			function () use ( &$calls ): \PostDomain\Ssl\SslStatus {
				++$calls;

				return new \PostDomain\Ssl\SslStatus( SslState::REQUESTED );
			}
		);

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 0, $calls, 'zero provider calls on a failed consumption' );
	}

	public function test_the_same_authorization_cannot_begin_a_second_execution(): void {
		$mapping = $this->seed();
		$lease   = ( new MutationLease( new SystemClock() ) )->acquire(
			$mapping->id,
			$mapping->revision,
			MutationKind::CREATE,
			120
		);

		$leased = $this->repo->by_id( $mapping->id );
		$auth   = $this->authorization( $leased, $lease['token'], $lease['revision'] );
		$calls  = 0;

		$call = function () use ( &$calls ): \PostDomain\Ssl\SslStatus {
			++$calls;

			return new \PostDomain\Ssl\SslStatus( SslState::REQUESTED, 'ref-1' );
		};

		$this->gate->execute( $auth, $leased, $call );
		$second = $this->gate->execute( $auth, $leased, $call );

		$this->assertInstanceOf( MutationRefusal::class, $second );
		$this->assertSame( 1, $calls );
	}

	public function test_an_expired_authorization_is_refused(): void {
		$mapping = $this->seed();
		$lease   = ( new MutationLease( new SystemClock() ) )->acquire(
			$mapping->id,
			$mapping->revision,
			MutationKind::CREATE,
			120
		);

		$leased = $this->repo->by_id( $mapping->id );

		$auth = new MutationAuthorization(
			MutationKind::CREATE,
			$lease['token'],
			$mapping->id,
			$lease['revision'],
			$leased->host,
			$leased->ssl_provider,
			$leased->ssl_ref,
			$leased->challenge,
			$leased->ssl_method,
			null,
			null,
			false,
			new \DateTimeImmutable( '-1 second', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->gate->execute(
			$auth,
			$leased,
			static fn(): \PostDomain\Ssl\SslStatus => new \PostDomain\Ssl\SslStatus( SslState::REQUESTED )
		);

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'authorization_expired', $result->precondition );
	}

	public function test_a_permit_of_the_wrong_kind_is_rejected_by_the_driver(): void {
		$permit = new ExecutionPermit(
			MutationKind::CREATE,
			1,
			2,
			str_repeat( '3', 32 ),
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);

		$this->expectException( \InvalidArgumentException::class );
		$permit->assert_kind( MutationKind::REMOVE );
	}

	public function test_only_the_gate_calls_mutating_driver_methods(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$name = $file->getFilename();

			if ( in_array( $name, array( 'MutationGate.php', 'SslDriver.php', 'NullDriver.php' ), true ) ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			foreach ( array( '->create(', '->adopt(', '->change_validation_method(', '->remove(' ) as $call ) {
				if ( str_contains( $source, '$driver' . $call ) ) {
					$offenders[] = $name . ' ' . $call;
				}
			}
		}

		$this->assertSame( array(), $offenders );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter MutationGateTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\ExecutionPermit" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/ExecutionPermit.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Proof that the authorization was already consumed by the RESERVED -> IN_FLIGHT
 * transition. A driver that is handed anything else refuses.
 */
final class ExecutionPermit {

	public function __construct(
		public readonly MutationKind $kind,
		public readonly int $mapping_id,
		public readonly int $in_flight_revision,
		public readonly string $lease_token,
		public readonly \DateTimeImmutable $expires_at
	) {}

	public function assert_kind( MutationKind $expected ): void {
		if ( $this->kind !== $expected ) {
			throw new \InvalidArgumentException(
				sprintf( 'Permit is for %s, not %s.', $this->kind->value, $expected->value )
			);
		}
	}
}
```

Create `src/Ssl/MutationAuthorization.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\OwnershipOrigin;

/** In-process only: never persisted, serialized, or logged. */
final class MutationAuthorization {

	public function __construct(
		public readonly MutationKind $kind,
		public readonly string $lease_token,
		public readonly int $mapping_id,
		public readonly int $revision,
		public readonly string $host,
		public readonly ?string $provider_id,
		public readonly ?string $provider_ref,
		public readonly string $challenge,
		public readonly ?string $requested_method,
		public readonly ?OwnershipOrigin $ownership_origin,
		public readonly ?string $owner_installation_id,
		public readonly bool $override_foreign_marker,
		public readonly \DateTimeImmutable $expires_at
	) {}
}
```

Create `src/Ssl/MutationRefusal.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class MutationRefusal {

	public function __construct(
		public readonly string $precondition,
		public readonly bool $transient,
		public readonly ?string $detail = null
	) {}
}
```

Create `src/Ssl/MutationGate.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\Mapping;

/**
 * The only component that invokes a mutating driver method, and only after the
 * RESERVED -> IN_FLIGHT CAS has consumed the authorization.
 */
final class MutationGate {

	public function __construct( private readonly MutationLease $lease ) {}

	/**
	 * @param callable(ExecutionPermit): (SslStatus|RemovalResult) $call
	 * @return SslStatus|RemovalResult|MutationRefusal
	 */
	public function execute( MutationAuthorization $auth, Mapping $mapping, callable $call ) {
		if ( $auth->expires_at <= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) {
			return new MutationRefusal( 'authorization_expired', true );
		}

		$in_flight = $this->lease->consume(
			array(
				'mapping_id' => $auth->mapping_id,
				'revision'   => $auth->revision,
				'token'      => $auth->lease_token,
				'kind'       => $auth->kind,
				'host'       => $auth->host,
				'provider'   => $auth->provider_id,
				'ref'        => $auth->provider_ref,
				'challenge'  => $auth->challenge,
				'method'     => $auth->requested_method,
			)
		);

		if ( null === $in_flight ) {
			// The provider is never called.
			return new MutationRefusal( 'authorization_not_consumable', true, 'the mapping changed underneath' );
		}

		unset( $mapping );

		return $call(
			new ExecutionPermit(
				$auth->kind,
				$auth->mapping_id,
				$in_flight,
				$auth->lease_token,
				$auth->expires_at
			)
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter MutationGateTest`
Expected: PASS — 6 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/ExecutionPermit.php src/Ssl/MutationAuthorization.php src/Ssl/MutationRefusal.php src/Ssl/MutationGate.php tests/integration/Ssl/MutationGateTest.php
git commit -m "Consume the authorization in the database before calling any provider

A zero-row consumption CAS means the driver is never reached. The phase is
already in_flight when the driver runs, which the test asserts by ordering
rather than after the fact."
```

---

### Task 5: Lease recovery

**Files:**
- Create: `src/Ssl/LeaseRecovery.php`
- Test: `tests/integration/Ssl/LeaseRecoveryTest.php`

**Interfaces:**
- Consumes: `MutationLease` (Task 3).
- Produces: `PostDomain\Ssl\LeaseRecovery::__construct( MutationLease $lease, Clock $clock )` with `::due( int $batch ): Mapping[]` and `::recover( Mapping $m, callable $read ): string` returning one of `cleared`, `fenced`, `still_recovering`, `skipped`.

`due()` is the **only** work selector in the plugin that finds rows by lease
expiry (spec §12.6).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/LeaseRecoveryTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\LeaseRecovery;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use WP_UnitTestCase;

final class LeaseRecoveryTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private LeaseRecovery $recovery;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo     = new DbRepository();
		$this->recovery = new LeaseRecovery( new MutationLease( new SystemClock() ), new SystemClock() );
	}

	private function seed( string $host, ?MutationPhase $phase, int $offset ): Mapping {
		global $wpdb;

		$mapping = $this->repo->save(
			new Mapping(
				0, $host, null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, substr( md5( $host ), 0, 32 ), '_post-domain-challenge'
			)
		);

		if ( null !== $phase ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				Schema::domains_table(),
				array(
					'ssl_mutation_token'      => bin2hex( random_bytes( 16 ) ),
					'ssl_mutation_kind'       => MutationKind::CREATE->value,
					'ssl_mutation_phase'      => $phase->value,
					'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
				),
				array( 'id' => $mapping->id )
			);
		}

		return (object) $this->repo->by_id( $mapping->id ) === null
			? $mapping
			: $this->repo->by_id( $mapping->id );
	}

	public function test_only_expired_leases_are_selected(): void {
		$expired   = $this->seed( 'expired.test', MutationPhase::IN_FLIGHT, -600 );
		$unexpired = $this->seed( 'live.test', MutationPhase::IN_FLIGHT, 600 );
		$unleased  = $this->seed( 'free.test', null, 0 );

		$ids = array_map( static fn( Mapping $m ): int => $m->id, $this->recovery->due( 50 ) );

		$this->assertContains( $expired->id, $ids );
		$this->assertNotContains( $unexpired->id, $ids );
		$this->assertNotContains( $unleased->id, $ids );
	}

	public function test_an_expired_reserved_lease_clears_without_a_provider_read(): void {
		$mapping = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );
		$reads   = 0;

		$outcome = $this->recovery->recover(
			$mapping,
			function () use ( &$reads ): array {
				++$reads;

				return array();
			}
		);

		$this->assertSame( 'cleared', $outcome );
		$this->assertSame( 0, $reads, 'nothing was sent, so nothing needs reading' );
		$this->assertNull( $this->repo->by_id( $mapping->id )?->ssl_mutation_token );
	}

	public function test_a_cleared_row_becomes_ordinarily_acquirable(): void {
		$mapping = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );
		$this->recovery->recover( $mapping, static fn(): array => array() );

		$after = $this->repo->by_id( $mapping->id );
		$lease = ( new MutationLease( new SystemClock() ) )->acquire(
			$after->id,
			$after->revision,
			MutationKind::CREATE,
			120
		);

		$this->assertNotNull( $lease, 'only successful cleanup frees the row' );
	}

	public function test_an_expired_in_flight_lease_is_fenced_before_the_read(): void {
		$mapping = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$phase_at_read = null;

		$this->recovery->recover(
			$mapping,
			function () use ( &$phase_at_read, $mapping ): array {
				$phase_at_read = ( new DbRepository() )->by_id( $mapping->id )?->ssl_mutation_phase;

				return array( 'resolved' => true );
			}
		);

		$this->assertSame(
			MutationPhase::RECOVERING,
			$phase_at_read,
			'the fence happens before provider state is read'
		);
	}

	public function test_the_fenced_original_worker_cannot_finalize(): void {
		$mapping   = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$old_token = (string) $mapping->ssl_mutation_token;

		$this->recovery->recover( $mapping, static fn(): array => array( 'resolved' => true ) );

		$finalized = ( new MutationLease( new SystemClock() ) )->finalize(
			$mapping->id,
			$mapping->revision,
			$old_token,
			MutationKind::CREATE,
			array( 'ssl_state' => SslState::ACTIVE->value )
		);

		$this->assertFalse( $finalized );
	}

	public function test_the_mutation_kind_is_preserved_through_the_fence(): void {
		$mapping = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$this->recovery->recover( $mapping, static fn(): array => array( 'resolved' => true ) );

		$this->assertSame( MutationKind::CREATE, $this->repo->by_id( $mapping->id )?->ssl_mutation_kind );
	}

	public function test_an_unresolved_read_stays_recovering_and_issues_no_mutation(): void {
		$mapping = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover( $mapping, static fn(): array => array( 'resolved' => false ) );

		$this->assertSame( 'still_recovering', $outcome );
		$this->assertSame( MutationPhase::RECOVERING, $this->repo->by_id( $mapping->id )?->ssl_mutation_phase );
	}

	public function test_an_expired_recovering_lease_can_be_taken_over(): void {
		$mapping = $this->seed( 'recovering.test', MutationPhase::RECOVERING, -600 );
		$old     = (string) $mapping->ssl_mutation_token;

		$this->recovery->recover( $mapping, static fn(): array => array( 'resolved' => false ) );

		$this->assertNotSame( $old, $this->repo->by_id( $mapping->id )?->ssl_mutation_token );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter LeaseRecoveryTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\LeaseRecovery" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/LeaseRecovery.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\Schema;

/**
 * The only claimant of expired leases, and the only work selector in the plugin
 * that finds rows by lease expiry.
 */
final class LeaseRecovery {

	public function __construct(
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/** @return Mapping[] */
	public function due( int $batch ): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE ssl_mutation_token IS NOT NULL
				    AND ssl_mutation_expires_at <= %s
				  ORDER BY ssl_mutation_expires_at ASC
				  LIMIT %d",
				$this->clock->mysql(),
				$batch
			),
			ARRAY_A
		);

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	/**
	 * @param callable(Mapping): array{resolved?: bool} $read Provider read, run only after fencing.
	 */
	public function recover( Mapping $mapping, callable $read ): string {
		$token = $mapping->ssl_mutation_token;
		$phase = $mapping->ssl_mutation_phase;

		if ( null === $token || null === $phase ) {
			return 'skipped';
		}

		if ( MutationPhase::RESERVED === $phase ) {
			// Nothing was sent: clear without contacting the provider.
			return $this->lease->clear_expired_reserved( $mapping->id, $token ) ? 'cleared' : 'skipped';
		}

		$ttl       = (int) apply_filters( 'pd_mutation_lease_ttl', 120 );
		$ttl       = max( 30, min( 600, $ttl ) );
		$new_token = $this->lease->claim_recovery( $mapping->id, $token, $ttl );

		if ( null === $new_token ) {
			return 'skipped';
		}

		$outcome = $read( $mapping );

		if ( true !== ( $outcome['resolved'] ?? false ) ) {
			// Another bounded read later. Never another provider mutation.
			return 'still_recovering';
		}

		return 'fenced';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter LeaseRecoveryTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/LeaseRecovery.php tests/integration/Ssl/LeaseRecoveryTest.php
git commit -m "Recover expired leases by phase, fencing before any provider read

An expired RESERVED lease proves nothing was sent and clears with no read. An
expired IN_FLIGHT lease is fenced by token replacement first, so the original
worker cannot finalize and must not retry."
```

---

### Task 6: Installation identity and clone detection

**Files:**
- Create: `src/Ssl/Environment.php`
- Test: `tests/integration/Ssl/EnvironmentTest.php`

**Interfaces:**
- Consumes: `MappingRepository` (Plan 02), `Challenge` (Plan 06).
- Produces: `PostDomain\Ssl\Environment::installation_id(): string`, `::primary_host(): string`, `::check(): ?array{stored: string, current: string}`, `::resolve_as_restore(): void`, `::resolve_as_clone(): void`, `::is_blocked(): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/EnvironmentTest.php`:

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
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class EnvironmentTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
		delete_option( 'pd_environment_mismatch' );
	}

	private function owned_mapping(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'cloudflare-saas', 'ref-1'
			)
		);
	}

	public function test_an_installation_id_is_generated_once_and_persists(): void {
		$first = Environment::installation_id();

		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $first );
		$this->assertSame( $first, Environment::installation_id() );
	}

	public function test_no_mismatch_on_a_stable_host(): void {
		Environment::installation_id();
		Environment::remember_primary_host();

		$this->assertNull( Environment::check() );
		$this->assertFalse( Environment::is_blocked() );
	}

	public function test_a_changed_primary_host_raises_a_mismatch(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );

		$mismatch = Environment::check();

		$this->assertNotNull( $mismatch );
		$this->assertSame( 'old-host.test', $mismatch['stored'] );
		$this->assertTrue( Environment::is_blocked() );
	}

	public function test_restore_keeps_the_identity_and_ownership(): void {
		$mapping = $this->owned_mapping();
		$id      = Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_restore();

		$this->assertSame( $id, Environment::installation_id() );
		$this->assertFalse( Environment::is_blocked() );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertSame( $mapping->challenge, $after?->challenge );
	}

	public function test_clone_replaces_the_identity_and_clears_ownership(): void {
		$mapping = $this->owned_mapping();
		$id      = Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_clone();

		$this->assertNotSame( $id, Environment::installation_id() );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertNull( $after?->ssl_ownership_origin );
		$this->assertNull( $after?->ssl_owner_installation_id );
		$this->assertNull( $after?->ssl_ref );
		$this->assertSame( SslState::NONE, $after?->ssl_state );
	}

	public function test_clone_rotates_every_challenge_and_resets_verification(): void {
		$mapping = $this->owned_mapping();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_clone();

		$after = $this->repo->by_id( $mapping->id );

		$this->assertNotSame( $mapping->challenge, $after?->challenge );
		$this->assertSame( VerificationState::UNVERIFIED, $after?->verification_state );
	}

	public function test_a_clone_cannot_claim_ownership_authority_over_the_originals_resources(): void {
		$mapping = $this->owned_mapping();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();
		Environment::resolve_as_clone();

		$after   = $this->repo->by_id( $mapping->id );
		$context = \PostDomain\Ssl\SslResourceContext::from_mapping(
			$after,
			Environment::installation_id(),
			'_post-domain-challenge.' . $after->host
		);

		$this->assertFalse( $context->has_ownership_authority() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Ssl\\EnvironmentTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\Environment" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/Environment.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Verification\Challenge;

final class Environment {

	public static function installation_id(): string {
		$id = get_option( 'pd_installation_id', '' );

		if ( ! is_string( $id ) || '' === $id ) {
			$id = wp_generate_uuid4();
			update_option( 'pd_installation_id', $id, false );
		}

		return $id;
	}

	public static function primary_host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function remember_primary_host(): void {
		update_option( 'pd_installation_primary_host', self::primary_host(), false );
	}

	/**
	 * @return array{stored: string, current: string}|null
	 */
	public static function check(): ?array {
		$stored = get_option( 'pd_installation_primary_host', '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			self::remember_primary_host();

			return null;
		}

		$current = self::primary_host();

		if ( $stored === $current ) {
			return null;
		}

		$mismatch = array( 'stored' => $stored, 'current' => $current );
		update_option( 'pd_environment_mismatch', $mismatch, false );

		return $mismatch;
	}

	public static function is_blocked(): bool {
		return is_array( get_option( 'pd_environment_mismatch', null ) );
	}

	public static function resolve_as_restore(): void {
		self::remember_primary_host();
		delete_option( 'pd_environment_mismatch' );
	}

	public static function resolve_as_clone(): void {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var int[] $ids */
		$ids = $wpdb->get_col( "SELECT id FROM {$table}" ); // phpcs:ignore WordPress.DB

		foreach ( $ids as $id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array(
					'ssl_ownership_origin'      => null,
					'ssl_owner_installation_id' => null,
					'ssl_adopted_at'            => null,
					'ssl_adopted_by'            => null,
					'ssl_ref'                   => null,
					'ssl_provider_state'        => null,
					'ssl_state'                 => \PostDomain\Mapping\SslState::NONE->value,
					'ssl_mutation_token'        => null,
					'ssl_mutation_kind'         => null,
					'ssl_mutation_phase'        => null,
					'ssl_mutation_expires_at'   => null,
					'challenge'                 => Challenge::token(),
					'challenge_rotated_at'      => gmdate( 'Y-m-d H:i:s' ),
					'verification_state'        => VerificationState::UNVERIFIED->value,
					'verified_at'               => null,
					'hard_failure_count'        => 0,
					'transient_failure_count'   => 0,
					'revision'                  => 1,
					'updated_at'                => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $id )
			);
		}

		delete_option( 'pd_installation_id' );
		self::installation_id();
		self::remember_primary_host();
		delete_option( 'pd_environment_mismatch' );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Ssl\\EnvironmentTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/Environment.php tests/integration/Ssl/EnvironmentTest.php
git commit -m "Detect a clone and strip its inherited authority

A clone gets a new installation id, cleared ownership columns, and rotated
challenges, so it fails both the ownership and fresh-proof preconditions
against the original's resources without needing a rule of its own."
```

---

### Task 7: The deletion authorizer

**Files:**
- Create: `src/Ssl/DeletionAuthorizer.php`, `src/Ssl/SslDriverRegistry.php`, `src/Ssl/Cooldown.php`
- Test: `tests/integration/Ssl/DeletionAuthorizerTest.php`

**Interfaces:**
- Consumes: everything above, `FreshProof` (Plan 06).
- Produces:
  - `PostDomain\Ssl\SslDriverRegistry::register( SslDriver $d ): void`, `::get( string $id ): ?SslDriver`, `::default(): SslDriver`.
  - `PostDomain\Ssl\Cooldown::active_for( string $driver_id ): bool`, `::set( string $driver_id, int $seconds, string $reason ): void`.
  - `PostDomain\Ssl\DeletionAuthorizer::authorize( Mapping $m ): MutationAuthorization|MutationRefusal`.

**The six preconditions**, evaluated in order; the first failure refuses and
nothing at the provider is touched (spec §14.4–14.5).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/DeletionAuthorizerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\MutationAuthorization;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslDriverRegistry;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class DeletionAuthorizerTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_provider_cooldowns' );
		$this->repo = new DbRepository();
		Environment::remember_primary_host();
	}

	private function driver( IdentityVerdict $verdict, bool $complete = true, bool $transient = false ): SslDriver {
		return new class( $verdict, $complete, $transient ) implements SslDriver {
			public function __construct(
				private readonly IdentityVerdict $verdict,
				private readonly bool $complete,
				private readonly bool $transient
			) {}

			public function id(): string {
				return 'test-driver';
			}

			public function capabilities(): DriverCapabilities {
				return new DriverCapabilities( true, array( 'txt' ), false );
			}

			public function status( SslResourceContext $ctx ): SslStatus {
				return new SslStatus( SslState::ACTIVE, $ctx->provider_ref );
			}

			public function identify( SslResourceContext $ctx ): IdentityResult {
				return new IdentityResult(
					$this->verdict,
					$ctx->provider_ref,
					$ctx->provider_ref,
					$ctx->host,
					null,
					MarkerSupport::UNAVAILABLE,
					$this->complete,
					$this->transient
				);
			}

			public function create( SslResourceContext $ctx, ExecutionPermit $p ): SslStatus {
				return $this->status( $ctx );
			}

			public function adopt( SslResourceContext $ctx, ExecutionPermit $p ): SslStatus {
				return $this->status( $ctx );
			}

			public function change_validation_method( SslResourceContext $ctx, string $m, ExecutionPermit $p ): SslStatus {
				return $this->status( $ctx );
			}

			public function remove( SslResourceContext $ctx, ExecutionPermit $p ): RemovalResult {
				return new RemovalResult( RemovalOutcome::REMOVED );
			}

			public function reconcile( array $contexts ): ReconcileReport {
				return new ReconcileReport( array(), true );
			}

			public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
				return new ValidationPlan( array(), array(), array(), array(), array() );
			}
		};
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

	private function authorizer( SslDriver $driver, FreshProof $proof ): DeletionAuthorizer {
		$registry = new SslDriverRegistry( new \PostDomain\Ssl\NullDriver() );
		$registry->register( $driver );

		return new DeletionAuthorizer(
			$registry,
			$proof,
			new MutationLease( new SystemClock() ),
			new SystemClock()
		);
	}

	private function deletable(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'test-driver', 'ref-1'
			)
		);
	}

	public function test_all_six_preconditions_met_yields_an_authorization(): void {
		$auth = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationAuthorization::class, $auth );
	}

	public function test_precondition_1_an_unresolved_environment_refuses(): void {
		update_option( 'pd_environment_mismatch', array( 'stored' => 'a', 'current' => 'b' ), false );

		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'environment_unresolved', $result->precondition );
	}

	public function test_precondition_2_a_provider_mismatch_refuses(): void {
		$mapping = $this->deletable();

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_provider' => 'some-other-driver' ),
			array( 'id' => $mapping->id )
		);

		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->repo->by_id( $mapping->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'driver_not_registered', $result->precondition );
	}

	public function test_precondition_3_an_identity_mismatch_refuses(): void {
		$result = $this->authorizer( $this->driver( IdentityVerdict::MISMATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'identity_not_confirmed', $result->precondition );
	}

	public function test_precondition_3_an_incomplete_read_refuses_transiently(): void {
		$result = $this->authorizer(
			$this->driver( IdentityVerdict::MATCH, false ),
			$this->proof( DnsOutcome::MATCH )
		)->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertTrue( $result->transient );
	}

	public function test_precondition_4_no_ownership_authority_refuses(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'unowned.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge',
				null, null, 'test-driver', null
			)
		);

		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $mapping );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
	}

	public function test_precondition_4_a_foreign_owner_refuses(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'foreign.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, 'some-other-installation', 'test-driver', 'ref-9'
			)
		);

		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $mapping );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
	}

	public function test_precondition_5_a_failed_fresh_proof_refuses(): void {
		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MISMATCH ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'fresh_proof_failed', $result->precondition );
	}

	public function test_precondition_5_a_transient_proof_refuses_transiently(): void {
		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::TRANSIENT ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertTrue( $result->transient );
	}

	public function test_precondition_5_cached_verification_is_not_sufficient(): void {
		$mapping = $this->deletable();

		$this->assertSame( VerificationState::VERIFIED, $mapping->verification_state );

		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::NO_RECORD ) )
			->authorize( $mapping );

		$this->assertInstanceOf(
			MutationRefusal::class,
			$result,
			'a verified row with a missing live record must not authorize a deletion'
		);
	}

	public function test_precondition_6_a_lease_cannot_be_taken_while_one_is_held(): void {
		$mapping = $this->deletable();

		( new MutationLease( new SystemClock() ) )->acquire(
			$mapping->id,
			$mapping->revision,
			\PostDomain\Ssl\MutationKind::CREATE,
			120
		);

		$result = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->repo->by_id( $mapping->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'lease_unavailable', $result->precondition );
	}

	public function test_a_refusal_is_recorded_as_an_event(): void {
		$mapping = $this->deletable();

		$this->authorizer( $this->driver( IdentityVerdict::MISMATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $mapping );

		$events = EventLog::for_domain( $mapping->id );

		$this->assertNotEmpty( $events );
	}

	public function test_deletion_still_authorizes_after_every_event_is_pruned(): void {
		$mapping = $this->deletable();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::events_table() ); // phpcs:ignore WordPress.DB

		$auth = $this->authorizer( $this->driver( IdentityVerdict::MATCH ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $mapping );

		$this->assertInstanceOf(
			MutationAuthorization::class,
			$auth,
			'ownership provenance is column state, so pruning history changes nothing'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter DeletionAuthorizerTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\SslDriverRegistry" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/SslDriverRegistry.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;

final class SslDriverRegistry {

	/** @var array<string, SslDriver> */
	private array $drivers = array();

	public function __construct( private readonly SslDriver $fallback ) {
		$this->drivers[ $fallback->id() ] = $fallback;
	}

	public function register( SslDriver $driver ): void {
		$this->drivers[ $driver->id() ] = $driver;
	}

	public function get( string $id ): ?SslDriver {
		return $this->drivers[ $id ] ?? null;
	}

	public function default(): SslDriver {
		return $this->fallback;
	}
}
```

Create `src/Ssl/Cooldown.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class Cooldown {

	public static function active_for( string $driver_id ): bool {
		$cooldowns = get_option( 'pd_provider_cooldowns', array() );

		if ( ! is_array( $cooldowns ) || ! isset( $cooldowns[ $driver_id ]['until'] ) ) {
			return false;
		}

		return strtotime( (string) $cooldowns[ $driver_id ]['until'] ) > time();
	}

	public static function set( string $driver_id, int $seconds, string $reason ): void {
		$cooldowns = get_option( 'pd_provider_cooldowns', array() );
		$cooldowns = is_array( $cooldowns ) ? $cooldowns : array();

		$cooldowns[ $driver_id ] = array(
			'until'  => gmdate( 'c', time() + max( 1, $seconds ) ),
			'reason' => $reason,
			'source' => 'retry_after',
		);

		update_option( 'pd_provider_cooldowns', $cooldowns, false );
	}
}
```

Create `src/Ssl/DeletionAuthorizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\Challenge;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class DeletionAuthorizer {

	public function __construct(
		private readonly SslDriverRegistry $registry,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/** @return MutationAuthorization|MutationRefusal */
	public function authorize( Mapping $mapping ) {
		// 1. Environment resolved.
		if ( Environment::is_blocked() ) {
			return $this->refuse( $mapping, 'environment_unresolved', false );
		}

		// 2. Stored provider matches a registered driver.
		$driver = null === $mapping->ssl_provider ? null : $this->registry->get( $mapping->ssl_provider );

		if ( null === $driver ) {
			return $this->refuse( $mapping, 'driver_not_registered', false );
		}

		if ( Cooldown::active_for( $driver->id() ) ) {
			return $this->refuse( $mapping, 'provider_cooldown', true );
		}

		// 6a. Take the RESERVED lease first: everything after this is bound to it.
		$ttl = (int) apply_filters( 'pd_mutation_lease_ttl', 120 );
		$ttl = max( 30, min( 600, $ttl ) );

		$lease = $this->lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE, $ttl );

		if ( null === $lease ) {
			return $this->refuse( $mapping, 'lease_unavailable', true );
		}

		$challenge_name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $challenge_name ) {
			return $this->refuse( $mapping, 'challenge_name_invalid', false );
		}

		$context = SslResourceContext::from_mapping(
			$mapping,
			Environment::installation_id(),
			$challenge_name,
			$lease['token']
		);

		// 3. Fresh identity, complete, exact reference and hostname.
		$identity = $driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return $this->refuse( $mapping, 'identity_incomplete', true );
		}

		if ( ! $identity->is_usable_for_mutation( $mapping->host )
			|| $identity->has_conflicting_marker( $context->installation_id, $mapping->id ) ) {
			return $this->refuse( $mapping, 'identity_not_confirmed', false );
		}

		// 4. Ownership authority, from columns only.
		if ( ! $context->has_ownership_authority() ) {
			return $this->refuse( $mapping, 'no_ownership_authority', false );
		}

		// 5. Fresh DNS proof of the current persisted challenge.
		$outcome = $this->proof->prove( $mapping );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return $this->refuse( $mapping, 'fresh_proof_transient', true );
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return $this->refuse( $mapping, 'fresh_proof_failed', false );
		}

		// 6b. Bind everything the consumption CAS will re-check.
		return new MutationAuthorization(
			MutationKind::REMOVE,
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
			$this->expiry()
		);
	}

	private function expiry(): \DateTimeImmutable {
		$ttl = (int) apply_filters( 'pd_authorization_ttl', 120 );
		$ttl = max( 30, min( 300, $ttl ) );

		return $this->clock->now()->modify( "+{$ttl} seconds" );
	}

	private function refuse( Mapping $mapping, string $precondition, bool $transient ): MutationRefusal {
		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			null,
			null,
			'cron',
			array( 'refused' => $precondition, 'transient' => $transient )
		);

		return new MutationRefusal( $precondition, $transient );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DeletionAuthorizerTest`
Expected: PASS — 13 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Ssl/DeletionAuthorizer.php src/Ssl/SslDriverRegistry.php src/Ssl/Cooldown.php tests/integration/Ssl/DeletionAuthorizerTest.php
git commit -m "Require six preconditions before any provider deletion

Cached verification is explicitly insufficient: a verified row whose live record
has gone must not authorize a deletion. Ownership reads columns, so a test
proves deletion still authorizes after every event is pruned."
```

---

## Gate for Plan 07

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `MutationGateTest` proves zero provider calls on a failed consumption and
that only the gate calls mutating driver methods; `MutationLeaseTest` proves
acquisition fails against an expired lease in all three phases;
`LeaseRecoveryTest` proves the fence precedes the read; `DeletionAuthorizerTest`
proves each of the six preconditions individually and that pruning events changes
nothing.
