# post-domain 07 — SSL lease, gate, and authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No provider mutation can begin without an authorization consumed by a
database phase transition, no code outside `MutationGate` can invoke a mutating
driver method, and no expired lease can be taken over by ordinary work.

**Architecture:** Identity is what the provider says a resource is; authorization
is whether this installation may change it. The driver answers the first; a
plugin-owned gate outside every driver answers the second **and performs the call
itself**. Services hand the gate a driver, a context, an authorization, and an
operation; they never hold a permit and never name a mutating method.

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
- **The `RESERVED → IN_FLIGHT` CAS re-checks every mapping value bound into the
  authorization** and is performed before any provider call (spec §12.6).
- **Mutating driver methods are invoked only by `MutationGate`,** which issues the
  `ExecutionPermit` itself (spec §14.3).
- **A refusal after acquisition but before consumption releases the `RESERVED`
  lease** (spec §12.6).
- **A failed finalization means the worker was fenced:** discard the local result,
  write nothing, retry nothing (spec §12.6).
- **Lease TTL and recovery grace both exceed the provider HTTP timeout plus a
  documented margin** (spec §12.6).
- **`SslState` never gates serving** (spec §12.7).

---

## File map

| File | Responsibility |
|---|---|
| `src/Ssl/SslResourceContext.php` | Everything a provider call needs, built from the leased row |
| `src/Ssl/IdentityVerdict.php`, `MarkerSupport.php`, `ProviderMarker.php`, `IdentityResult.php` | Provider identity |
| `src/Ssl/TimingPolicy.php` | The single source of lease TTL, recovery grace, and the floor |
| `src/Ssl/LeaseBinding.php` | The typed set of values the consumption CAS re-checks |
| `src/Ssl/LeaseOutcome.php` | Typed, column-allowlisted finalization payload |
| `src/Ssl/MutationLease.php` | Acquire, consume, release, finalize, delete, recover — all CAS |
| `src/Ssl/MutationOperation.php` | Which driver method the gate will dispatch to |
| `src/Ssl/ExecutionPermit.php` | Gate-issued proof of consumption; not freely constructible |
| `src/Ssl/MutationAuthorization.php`, `MutationRefusal.php` | Bound authorization and its refusal |
| `src/Ssl/SslStatus.php`, `RemovalOutcome.php`, `RemovalResult.php`, `ReconcileReport.php`, `DriverCapabilities.php`, `ValidationPlan.php` | Driver result types |
| `src/Contracts/SslDriver.php`, `src/Ssl/NullDriver.php` | The provider contract and its default |
| `src/Ssl/GateResult.php`, `MutationGate.php` | The only caller of a mutating driver method |
| `src/Ssl/RecoveryOutcome.php`, `RecoveryResolver.php`, `LeaseRecovery.php` | Phase-specific recovery, token-owned |
| `src/Ssl/Environment.php` | Installation identity and clone detection |
| `src/Ssl/SslDriverRegistry.php`, `Cooldown.php`, `AuthorizerSupport.php`, `DeletionAuthorizer.php` | Registry, cooldowns, shared preconditions, deletion |

**Task order.** Every type exists before the first task that uses it:
`TimingPolicy` (2) → `MutationLease` (3) → `ExecutionPermit` (4) → the driver
contract (5) → the gate (6). No task depends on a type from a later task.

---

### Task 1: Resource context and identity

**Files:**
- Create: `src/Ssl/SslResourceContext.php`, `src/Ssl/IdentityVerdict.php`, `src/Ssl/MarkerSupport.php`, `src/Ssl/ProviderMarker.php`, `src/Ssl/IdentityResult.php`
- Test: `tests/unit/Ssl/IdentityResultTest.php`

**Interfaces:**
- Consumes: `Mapping`, `OwnershipOrigin` (Plan 02).
- Produces:
  - `PostDomain\Ssl\SslResourceContext` — readonly `int $mapping_id`, `string $host`, `string $installation_id`, `string $provider_id`, `?string $provider_ref`, `?OwnershipOrigin $ownership_origin`, `?string $owner_installation_id`, `string $challenge_name`, `string $challenge_value`, `string $challenge`, `int $revision`, `?string $lease_token`, `?string $requested_method`; plus `::from_mapping( Mapping $m, string $installation_id, string $challenge_name, ?string $lease_token = null ): self` and `::has_ownership_authority(): bool`.
  - `IdentityVerdict`, `MarkerSupport`, `ProviderMarker`, `IdentityResult` with `::is_usable_for_mutation( string $expected_host ): bool`, `::is_recoverable_create( string $installation_id, int $mapping_id, string $expected_host ): bool`, `::has_conflicting_marker( string $installation_id, int $mapping_id ): bool`.

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

	/** @param array<string, mixed> $overrides */
	private function result( array $overrides = array() ): IdentityResult {
		return new IdentityResult(
			$overrides['verdict'] ?? IdentityVerdict::MATCH,
			array_key_exists( 'expected_ref', $overrides ) ? $overrides['expected_ref'] : 'ref-1',
			$overrides['observed_ref'] ?? 'ref-1',
			$overrides['observed_hostname'] ?? 'mapped.test',
			$overrides['marker'] ?? null,
			$overrides['marker_support'] ?? MarkerSupport::UNAVAILABLE,
			$overrides['read_complete'] ?? true,
			$overrides['transient'] ?? false
		);
	}

	public function test_a_complete_exact_match_is_usable(): void {
		$this->assertTrue( $this->result()->is_usable_for_mutation( 'mapped.test' ) );
	}

	/**
	 * @dataProvider unusable_shapes
	 * @param array<string, mixed> $overrides
	 */
	public function test_anything_less_than_an_exact_match_is_unusable( array $overrides ): void {
		$this->assertFalse( $this->result( $overrides )->is_usable_for_mutation( 'mapped.test' ) );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function unusable_shapes(): array {
		return array(
			'incomplete read'    => array( array( 'read_complete' => false ) ),
			'transient'          => array( array( 'transient' => true ) ),
			'reference mismatch' => array( array( 'observed_ref' => 'ref-2' ) ),
			'hostname mismatch'  => array( array( 'observed_hostname' => 'other.test' ) ),
			'unbound reference'  => array( array( 'expected_ref' => null ) ),
			'verdict mismatch'   => array( array( 'verdict' => IdentityVerdict::MISMATCH ) ),
			'verdict absent'     => array( array( 'verdict' => IdentityVerdict::ABSENT ) ),
			'verdict ambiguous'  => array( array( 'verdict' => IdentityVerdict::AMBIGUOUS ) ),
			'verdict unknown'    => array( array( 'verdict' => IdentityVerdict::UNKNOWN ) ),
			'verdict recover'    => array( array( 'verdict' => IdentityVerdict::RECOVERABLE_CREATE ) ),
		);
	}

	public function test_a_marker_names_an_installation_and_mapping(): void {
		$marker = new ProviderMarker( 'install-a', 12, array() );

		$this->assertTrue( $marker->names( 'install-a', 12 ) );
		$this->assertFalse( $marker->names( 'install-b', 12 ) );
		$this->assertFalse( $marker->names( 'install-a', 13 ) );
	}

	public function test_a_foreign_marker_conflicts_and_a_matching_one_does_not(): void {
		$this->assertTrue(
			$this->result( array( 'marker' => new ProviderMarker( 'other', 12, array() ) ) )
				->has_conflicting_marker( 'install-a', 12 )
		);
		$this->assertFalse(
			$this->result( array( 'marker' => new ProviderMarker( 'install-a', 12, array() ) ) )
				->has_conflicting_marker( 'install-a', 12 )
		);
	}

	public function test_an_absent_marker_never_conflicts(): void {
		$this->assertFalse(
			$this->result( array( 'marker' => null ) )->has_conflicting_marker( 'install-a', 12 ),
			'an absent marker establishes nothing either way'
		);
	}

	public function test_recoverable_create_requires_an_unbound_reference_and_a_naming_marker(): void {
		$valid = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE, null, 'ref-9', 'mapped.test',
			new ProviderMarker( 'install-a', 12, array() ), MarkerSupport::SUPPORTED, true, false
		);
		$this->assertTrue( $valid->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );

		$bound = new IdentityResult(
			IdentityVerdict::RECOVERABLE_CREATE, 'ref-1', 'ref-9', 'mapped.test',
			new ProviderMarker( 'install-a', 12, array() ), MarkerSupport::SUPPORTED, true, false
		);
		$this->assertFalse( $bound->is_recoverable_create( 'install-a', 12, 'mapped.test' ) );

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
		public readonly string $challenge,
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
			$mapping->challenge,
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
Expected: PASS — 15 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/SslResourceContext.php src/Ssl/IdentityVerdict.php src/Ssl/MarkerSupport.php src/Ssl/ProviderMarker.php src/Ssl/IdentityResult.php tests/unit/Ssl/IdentityResultTest.php
git commit -m "Separate provider identity from mutation authorization

A marker naming this installation is additional evidence; a foreign one blocks;
an absent one establishes nothing. Ownership authority reads columns only."
```

---

### Task 2: Timing policy

**Files:**
- Create: `src/Ssl/TimingPolicy.php`
- Test: `tests/integration/Ssl/TimingPolicyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Ssl\TimingPolicy::PROVIDER_TIMEOUT_SECONDS` (10), `::SAFETY_MARGIN_SECONDS` (30), `::DEFAULT_LEASE_TTL` (120), `::DEFAULT_RECOVERY_GRACE` (180), `::MAX_TTL` (600), `::MAX_BACKOFF`, `::floor(): int`, `::lease_ttl(): int`, `::recovery_grace(): int`, `::authorization_ttl( int $lease_ttl ): int`, `::recovery_backoff( int $attempt ): int`.

One source of truth. No service computes its own clamp: a lease shorter than the
provider timeout plus the margin would let recovery fence a request that is still
legitimately in flight (spec §12.6). This test is an integration test because it
exercises WordPress filters.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/TimingPolicyTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Ssl\TimingPolicy;
use WP_UnitTestCase;

final class TimingPolicyTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pd_mutation_lease_ttl' );
		remove_all_filters( 'pd_recovery_grace_seconds' );
		remove_all_filters( 'pd_authorization_ttl' );
		parent::tear_down();
	}

	public function test_the_floor_is_the_provider_timeout_plus_the_margin(): void {
		$this->assertSame(
			TimingPolicy::PROVIDER_TIMEOUT_SECONDS + TimingPolicy::SAFETY_MARGIN_SECONDS,
			TimingPolicy::floor()
		);
	}

	public function test_the_defaults_exceed_the_floor(): void {
		$this->assertGreaterThan( TimingPolicy::floor(), TimingPolicy::lease_ttl() );
		$this->assertGreaterThan( TimingPolicy::floor(), TimingPolicy::recovery_grace() );
	}

	public function test_a_lease_filter_below_the_floor_is_raised_to_it(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): int => 5 );

		$this->assertSame(
			TimingPolicy::floor(),
			TimingPolicy::lease_ttl(),
			'recovery must never begin while the original request is still in flight'
		);
	}

	public function test_a_recovery_filter_below_the_floor_is_raised_to_it(): void {
		add_filter( 'pd_recovery_grace_seconds', static fn(): int => 1 );

		$this->assertSame( TimingPolicy::floor(), TimingPolicy::recovery_grace() );
	}

	public function test_a_filter_above_the_ceiling_is_clamped(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): int => 99999 );

		$this->assertSame( TimingPolicy::MAX_TTL, TimingPolicy::lease_ttl() );
	}

	public function test_a_non_numeric_filter_falls_back_to_the_default(): void {
		add_filter( 'pd_mutation_lease_ttl', static fn(): string => 'soon' );

		$this->assertSame( TimingPolicy::DEFAULT_LEASE_TTL, TimingPolicy::lease_ttl() );
	}

	public function test_the_authorization_never_outlives_its_lease(): void {
		add_filter( 'pd_authorization_ttl', static fn(): int => 300 );

		$this->assertLessThanOrEqual( 60, TimingPolicy::authorization_ttl( 60 ) );
		$this->assertLessThanOrEqual( 120, TimingPolicy::authorization_ttl( 120 ) );
	}

	public function test_recovery_backoff_grows_and_is_capped(): void {
		$this->assertLessThan( TimingPolicy::recovery_backoff( 5 ), TimingPolicy::recovery_backoff( 3 ) );
		$this->assertSame( TimingPolicy::MAX_BACKOFF, TimingPolicy::recovery_backoff( 99 ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter TimingPolicyTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\TimingPolicy" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/TimingPolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * One source of truth for every lease and recovery duration. The floor exists
 * because a lease shorter than the provider timeout plus a margin would let
 * recovery fence a request that is still legitimately in flight.
 */
final class TimingPolicy {

	public const PROVIDER_TIMEOUT_SECONDS = 10;

	public const SAFETY_MARGIN_SECONDS = 30;

	public const DEFAULT_LEASE_TTL = 120;

	public const DEFAULT_RECOVERY_GRACE = 180;

	public const MAX_TTL = 600;

	public const MAX_BACKOFF = 21600;

	public static function floor(): int {
		return self::PROVIDER_TIMEOUT_SECONDS + self::SAFETY_MARGIN_SECONDS;
	}

	public static function lease_ttl(): int {
		return self::clamp(
			apply_filters( 'pd_mutation_lease_ttl', self::DEFAULT_LEASE_TTL ),
			self::DEFAULT_LEASE_TTL
		);
	}

	public static function recovery_grace(): int {
		return self::clamp(
			apply_filters( 'pd_recovery_grace_seconds', self::DEFAULT_RECOVERY_GRACE ),
			self::DEFAULT_RECOVERY_GRACE
		);
	}

	public static function authorization_ttl( int $lease_ttl ): int {
		$requested = apply_filters( 'pd_authorization_ttl', self::DEFAULT_LEASE_TTL );
		$requested = is_numeric( $requested ) ? (int) $requested : self::DEFAULT_LEASE_TTL;

		return max( 30, min( $lease_ttl, min( 300, $requested ) ) );
	}

	public static function recovery_backoff( int $attempt ): int {
		return min( self::MAX_BACKOFF, 60 * ( 2 ** max( 0, $attempt ) ) );
	}

	/** @param mixed $value */
	private static function clamp( $value, int $default ): int {
		$seconds = is_numeric( $value ) ? (int) $value : $default;

		// Raised to the floor rather than rejected: a short lease is a
		// misconfiguration, not a reason to stop working.
		return max( self::floor(), min( self::MAX_TTL, $seconds ) );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter TimingPolicyTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/TimingPolicy.php tests/integration/Ssl/TimingPolicyTest.php
git commit -m "Centralize lease and recovery timing behind a floor

A filter value below the provider timeout plus the margin is raised to the
floor, so no configuration can let recovery fence a request that is still
legitimately in flight."
```

---

### Task 3: The mutation lease

**Files:**
- Create: `src/Ssl/LeaseBinding.php`, `src/Ssl/LeaseOutcome.php`, `src/Ssl/MutationLease.php`
- Test: `tests/integration/Ssl/MutationLeaseTest.php`

**Interfaces:**
- Consumes: `Schema`, `Clock` (Plan 02), `MutationKind`, `MutationPhase` (Plan 02), `TimingPolicy` (Task 2).
- Produces:
  - `PostDomain\Ssl\LeaseBinding` — readonly `int $mapping_id`, `int $revision`, `string $token`, `MutationKind $kind`, `string $host`, `?string $provider_id`, `?string $provider_ref`, `string $challenge`, `?string $requested_method`, `?OwnershipOrigin $ownership_origin`, `?string $owner_installation_id`.
  - `PostDomain\Ssl\LeaseOutcome` — typed, column-allowlisted; `::state()`, `::bound()`, `::adopted()`, `::method_confirmed()`, `::failure()`, `::checked()`, `::provider_state()`, `::attempted()`, `::raw()`, `::merge()`, `::columns()`.
  - `PostDomain\Ssl\MutationLease::__construct( Clock $clock )` with `::acquire()`, `::consume()`, `::release_reserved()`, `::finalize()`, `::delete_row()`, `::clear_expired_reserved()`, `::claim_recovery()`, `::extend_recovery()`.

The consumption CAS re-checks **every** value in `LeaseBinding`, including the two
ownership columns, with null-safe comparisons (spec §12.6).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/MutationLeaseTest.php`:

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
use PostDomain\Ssl\LeaseBinding;
use PostDomain\Ssl\LeaseOutcome;
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

	private function seed( bool $owned = false ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				$owned ? OwnershipOrigin::CREATED : null,
				$owned ? 'install-a' : null,
				$owned ? 'test-driver' : null,
				$owned ? 'ref-1' : null
			)
		);
	}

	private function binding( Mapping $m, string $token, int $revision, MutationKind $kind = MutationKind::CREATE ): LeaseBinding {
		return new LeaseBinding(
			$m->id, $revision, $token, $kind, $m->host, $m->ssl_provider, $m->ssl_ref,
			$m->challenge, $m->ssl_method, $m->ssl_ownership_origin, $m->ssl_owner_installation_id
		);
	}

	private function force_lease( int $id, MutationPhase $phase, int $offset ): string {
		global $wpdb;

		$token = bin2hex( random_bytes( 16 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => $token,
				'ssl_mutation_kind'       => MutationKind::REMOVE->value,
				'ssl_mutation_phase'      => $phase->value,
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
			),
			array( 'id' => $id )
		);

		return $token;
	}

	public function test_acquiring_on_a_free_row_reserves_it(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );

		$this->assertNotNull( $lease );
		$this->assertSame( MutationPhase::RESERVED, $this->repo->by_id( $m->id )?->ssl_mutation_phase );
	}

	public function test_acquiring_against_a_stale_revision_fails(): void {
		$m = $this->seed();

		$this->assertNull( $this->lease->acquire( $m->id, $m->revision + 5, MutationKind::CREATE ) );
	}

	/**
	 * @dataProvider phase_and_expiry
	 */
	public function test_acquisition_fails_against_any_existing_lease( string $phase, int $offset ): void {
		$m = $this->seed();
		$this->force_lease( $m->id, MutationPhase::from( $phase ), $offset );
		$current = $this->repo->by_id( $m->id );

		$this->assertNull(
			$this->lease->acquire( $m->id, (int) $current?->revision, MutationKind::CREATE ),
			'expiry transfers the row to recovery; it does not free it'
		);
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function phase_and_expiry(): array {
		return array(
			'reserved live'      => array( 'reserved', 600 ),
			'reserved expired'   => array( 'reserved', -600 ),
			'in flight live'     => array( 'in_flight', 600 ),
			'in flight expired'  => array( 'in_flight', -600 ),
			'recovering live'    => array( 'recovering', 600 ),
			'recovering expired' => array( 'recovering', -600 ),
		);
	}

	public function test_consuming_moves_reserved_to_in_flight_and_returns_that_revision(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );

		$in_flight = $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] )
		);

		$this->assertSame( $lease['revision'] + 1, $in_flight );
		$this->assertSame( MutationPhase::IN_FLIGHT, $this->repo->by_id( $m->id )?->ssl_mutation_phase );
	}

	public function test_consuming_twice_fails_the_second_time(): void {
		$m       = $this->seed();
		$lease   = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );
		$binding = $this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] );

		$this->assertNotNull( $this->lease->consume( $binding ) );
		$this->assertNull( $this->lease->consume( $binding ), 'one execution per authorization' );
	}

	/**
	 * @dataProvider changed_columns
	 * @param array<string, string|null> $change
	 */
	public function test_consuming_fails_when_any_bound_value_changed( array $change ): void {
		global $wpdb;

		$m       = $this->seed( true );
		$lease   = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );
		$binding = $this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] );

		$wpdb->update( Schema::domains_table(), $change, array( 'id' => $m->id ) ); // phpcs:ignore WordPress.DB

		$this->assertNull( $this->lease->consume( $binding ) );
	}

	/** @return array<string, array{0: array<string, string|null>}> */
	public static function changed_columns(): array {
		return array(
			'host'               => array( array( 'host' => 'moved.test' ) ),
			'provider'           => array( array( 'ssl_provider' => 'other-driver' ) ),
			'reference'          => array( array( 'ssl_ref' => 'ref-2' ) ),
			'challenge'          => array( array( 'challenge' => str_repeat( 'z', 32 ) ) ),
			'method'             => array( array( 'ssl_method' => 'http' ) ),
			'ownership origin'   => array( array( 'ssl_ownership_origin' => 'adopted' ) ),
			'owner installation' => array( array( 'ssl_owner_installation_id' => 'someone-else' ) ),
			'ownership cleared'  => array( array( 'ssl_ownership_origin' => null ) ),
			'owner cleared'      => array( array( 'ssl_owner_installation_id' => null ) ),
		);
	}

	public function test_consuming_fails_when_the_lease_expired(): void {
		global $wpdb;

		$m       = $this->seed();
		$lease   = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );
		$binding = $this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $m->id )
		);

		$this->assertNull( $this->lease->consume( $binding ) );
	}

	public function test_releasing_a_reserved_lease_clears_all_four_columns(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );

		$this->assertTrue(
			$this->lease->release_reserved( $m->id, $lease['revision'], $lease['token'], MutationKind::CREATE )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertNull( $after?->ssl_mutation_token );
		$this->assertNull( $after?->ssl_mutation_kind );
		$this->assertNull( $after?->ssl_mutation_phase );
		$this->assertNull( $after?->ssl_mutation_expires_at );
	}

	public function test_release_refuses_a_wrong_token_kind_or_revision(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );

		$this->assertFalse( $this->lease->release_reserved( $m->id, $lease['revision'], str_repeat( '0', 32 ), MutationKind::CREATE ) );
		$this->assertFalse( $this->lease->release_reserved( $m->id, $lease['revision'], $lease['token'], MutationKind::REMOVE ) );
		$this->assertFalse( $this->lease->release_reserved( $m->id, $lease['revision'] + 9, $lease['token'], MutationKind::CREATE ) );
	}

	public function test_release_never_clears_an_in_flight_lease(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );
		$in    = (int) $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] )
		);

		$this->assertFalse(
			$this->lease->release_reserved( $m->id, $in, $lease['token'], MutationKind::CREATE ),
			'an in-flight lease may have reached the provider'
		);
	}

	public function test_finalize_applies_an_allowlisted_outcome_and_clears_the_lease(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );
		$in    = (int) $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] )
		);

		$this->assertTrue(
			$this->lease->finalize(
				$m->id, $in, $lease['token'], MutationKind::CREATE, MutationPhase::IN_FLIGHT,
				LeaseOutcome::bound( SslState::REQUESTED, 'ref-1', 'test-driver', OwnershipOrigin::CREATED, 'install-a' )
			)
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( SslState::REQUESTED, $after?->ssl_state );
		$this->assertSame( 'ref-1', $after?->ssl_ref );
		$this->assertNull( $after?->ssl_mutation_token );
	}

	public function test_finalize_fails_under_a_replaced_token(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::CREATE );
		$in    = (int) $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'] )
		);

		$this->force_lease( $m->id, MutationPhase::RECOVERING, 600 );

		$this->assertFalse(
			$this->lease->finalize(
				$m->id, $in, $lease['token'], MutationKind::CREATE, MutationPhase::IN_FLIGHT,
				LeaseOutcome::state( SslState::ACTIVE )
			),
			'a fenced worker cannot apply its result'
		);
	}

	public function test_an_outcome_cannot_carry_an_unapproved_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		LeaseOutcome::raw( array( 'post_id' => 99 ) );
	}

	public function test_an_expired_reserved_lease_clears_without_a_read(): void {
		$m     = $this->seed();
		$token = $this->force_lease( $m->id, MutationPhase::RESERVED, -600 );

		$this->assertTrue( $this->lease->clear_expired_reserved( $m->id, $token ) );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_clearing_refuses_unexpired_reserved_and_any_in_flight(): void {
		$m = $this->seed();

		$live = $this->force_lease( $m->id, MutationPhase::RESERVED, 600 );
		$this->assertFalse( $this->lease->clear_expired_reserved( $m->id, $live ) );

		$flight = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 );
		$this->assertFalse( $this->lease->clear_expired_reserved( $m->id, $flight ) );
	}

	public function test_claiming_recovery_replaces_the_token_and_preserves_the_kind(): void {
		$m   = $this->seed();
		$old = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 );

		$claim = $this->lease->claim_recovery( $m->id, $old );

		$this->assertNotNull( $claim );
		$this->assertNotSame( $old, $claim['token'] );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( MutationPhase::RECOVERING, $after?->ssl_mutation_phase );
		$this->assertSame( MutationKind::REMOVE, $after?->ssl_mutation_kind );
		$this->assertSame( $after?->revision, $claim['revision'] );
	}

	public function test_claiming_recovery_refuses_an_unexpired_lease(): void {
		$m     = $this->seed();
		$token = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, 600 );

		$this->assertNull( $this->lease->claim_recovery( $m->id, $token ) );
	}

	public function test_extending_recovery_requires_the_owning_token(): void {
		$m     = $this->seed();
		$old   = $this->force_lease( $m->id, MutationPhase::IN_FLIGHT, -600 );
		$claim = $this->lease->claim_recovery( $m->id, $old );

		$this->assertFalse( $this->lease->extend_recovery( $m->id, $claim['revision'], $old ) );
		$this->assertTrue( $this->lease->extend_recovery( $m->id, $claim['revision'], $claim['token'] ) );
	}

	public function test_delete_row_requires_the_exact_owner(): void {
		$m     = $this->seed();
		$lease = $this->lease->acquire( $m->id, $m->revision, MutationKind::REMOVE );
		$in    = (int) $this->lease->consume(
			$this->binding( $this->repo->by_id( $m->id ), $lease['token'], $lease['revision'], MutationKind::REMOVE )
		);

		$this->assertFalse(
			$this->lease->delete_row( $m->id, $in, str_repeat( '0', 32 ), MutationKind::REMOVE, MutationPhase::IN_FLIGHT )
		);
		$this->assertNotNull( $this->repo->by_id( $m->id ) );

		$this->assertTrue(
			$this->lease->delete_row( $m->id, $in, $lease['token'], MutationKind::REMOVE, MutationPhase::IN_FLIGHT )
		);
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter MutationLeaseTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\LeaseBinding" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/LeaseBinding.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\OwnershipOrigin;

/** Every value the consumption CAS re-checks. */
final class LeaseBinding {

	public function __construct(
		public readonly int $mapping_id,
		public readonly int $revision,
		public readonly string $token,
		public readonly MutationKind $kind,
		public readonly string $host,
		public readonly ?string $provider_id,
		public readonly ?string $provider_ref,
		public readonly string $challenge,
		public readonly ?string $requested_method,
		public readonly ?OwnershipOrigin $ownership_origin,
		public readonly ?string $owner_installation_id
	) {}
}
```

Create `src/Ssl/LeaseOutcome.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;

/**
 * A typed finalization payload with an explicit column allowlist, so no caller
 * can put an arbitrary column name into SQL and every row invariant stays here.
 */
final class LeaseOutcome {

	public const ALLOWED_COLUMNS = array(
		'ssl_state',
		'ssl_ref',
		'ssl_provider',
		'ssl_ownership_origin',
		'ssl_owner_installation_id',
		'ssl_adopted_at',
		'ssl_adopted_by',
		'ssl_method',
		'ssl_method_requested_at',
		'ssl_marker_support',
		'ssl_checked_at',
		'ssl_next_attempt_at',
		'ssl_transient_count',
		'ssl_provider_state',
		'ssl_error',
		'deletion_attempts',
		'deletion_next_attempt_at',
	);

	/** @param array<string, string|int|null> $columns */
	private function __construct( private readonly array $columns ) {
		foreach ( array_keys( $columns ) as $column ) {
			if ( ! in_array( $column, self::ALLOWED_COLUMNS, true ) ) {
				throw new \InvalidArgumentException( "Column {$column} may not be finalized through a lease." );
			}
		}
	}

	/** @param array<string, string|int|null> $columns */
	public static function raw( array $columns ): self {
		return new self( $columns );
	}

	public static function state( SslState $state ): self {
		return new self( array( 'ssl_state' => $state->value, 'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public static function bound(
		SslState $state,
		string $ref,
		string $provider_id,
		OwnershipOrigin $origin,
		string $installation_id
	): self {
		return new self(
			array(
				'ssl_state'                 => $state->value,
				'ssl_ref'                   => $ref,
				'ssl_provider'              => $provider_id,
				'ssl_ownership_origin'      => $origin->value,
				'ssl_owner_installation_id' => $installation_id,
				'ssl_checked_at'            => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function adopted(
		SslState $state,
		string $ref,
		string $provider_id,
		string $installation_id,
		int $user_id
	): self {
		return new self(
			array(
				'ssl_state'                 => $state->value,
				'ssl_ref'                   => $ref,
				'ssl_provider'              => $provider_id,
				'ssl_ownership_origin'      => OwnershipOrigin::ADOPTED->value,
				'ssl_owner_installation_id' => $installation_id,
				'ssl_adopted_at'            => gmdate( 'Y-m-d H:i:s' ),
				'ssl_adopted_by'            => $user_id,
				'ssl_checked_at'            => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function method_confirmed( string $method ): self {
		return new self(
			array(
				'ssl_method'              => $method,
				'ssl_method_requested_at' => gmdate( 'Y-m-d H:i:s' ),
				'ssl_checked_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function failure( SslState $state, string $code, string $message ): self {
		return new self(
			array(
				'ssl_state'      => $state->value,
				'ssl_error'      => (string) wp_json_encode(
					array( 'code' => $code, 'message' => mb_substr( $message, 0, 500 ), 'at' => gmdate( 'Y-m-d H:i:s' ) )
				),
				'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function checked(): self {
		return new self( array( 'ssl_checked_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public static function attempted( int $attempts, int $next_attempt_in ): self {
		return new self(
			array(
				'deletion_attempts'        => $attempts,
				'deletion_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $next_attempt_in ),
			)
		);
	}

	/** @param array<string, mixed> $state */
	public static function provider_state( array $state ): self {
		return new self( array( 'ssl_provider_state' => (string) wp_json_encode( $state ) ) );
	}

	public function merge( self $other ): self {
		return new self( array_merge( $this->columns, $other->columns ) );
	}

	/** @return array<string, string|int|null> */
	public function columns(): array {
		return $this->columns;
	}
}
```

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
	public function acquire( int $mapping_id, int $revision, MutationKind $kind ): ?array {
		global $wpdb;

		$table   = Schema::domains_table();
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + TimingPolicy::lease_ttl() );

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
	 * Every bound value is re-checked, with null-safe comparisons.
	 *
	 * @return int|null The in-flight revision, or null when the provider must not be called.
	 */
	public function consume( LeaseBinding $b ): ?int {
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
				    AND ( ssl_provider <=> %s )
				    AND ( ssl_ref <=> %s )
				    AND challenge = %s
				    AND ( ssl_method <=> %s )
				    AND ( ssl_ownership_origin <=> %s )
				    AND ( ssl_owner_installation_id <=> %s )",
				MutationPhase::IN_FLIGHT->value,
				$this->clock->mysql(),
				$b->mapping_id,
				$b->revision,
				$b->token,
				$b->kind->value,
				MutationPhase::RESERVED->value,
				$this->clock->mysql(),
				$b->host,
				$b->provider_id,
				$b->provider_ref,
				$b->challenge,
				$b->requested_method,
				$b->ownership_origin?->value,
				$b->owner_installation_id
			)
		);

		return 1 === $affected ? $b->revision + 1 : null;
	}

	/** Releases a RESERVED lease after a refusal, before any provider call. */
	public function release_reserved( int $mapping_id, int $revision, string $token, MutationKind $kind ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = NULL, ssl_mutation_kind = NULL,
				        ssl_mutation_phase = NULL, ssl_mutation_expires_at = NULL,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token = %s
				    AND ssl_mutation_kind = %s AND ssl_mutation_phase = %s",
				$this->clock->mysql(),
				$mapping_id,
				$revision,
				$token,
				$kind->value,
				MutationPhase::RESERVED->value
			)
		);
	}

	/** Applies the result and clears the lease in one transition. */
	public function finalize(
		int $mapping_id,
		int $revision,
		string $token,
		MutationKind $kind,
		MutationPhase $phase,
		LeaseOutcome $outcome
	): bool {
		global $wpdb;

		$table  = Schema::domains_table();
		$sets   = array();
		$values = array();

		foreach ( $outcome->columns() as $column => $value ) {
			// Column names come from LeaseOutcome's allowlist, never from a caller.
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
		$values[] = $revision;
		$values[] = $token;
		$values[] = $kind->value;
		$values[] = $phase->value;

		$sql = "UPDATE {$table} SET " . implode( ', ', $sets )
			. ' WHERE id = %d AND revision = %d AND ssl_mutation_token = %s'
			. ' AND ssl_mutation_kind = %s AND ssl_mutation_phase = %s';

		return 1 === $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB
	}

	/** Deletes the row, owned by the exact lease. */
	public function delete_row(
		int $mapping_id,
		int $revision,
		string $token,
		MutationKind $kind,
		MutationPhase $phase
	): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$table}
				  WHERE id = %d AND revision = %d AND ssl_mutation_token = %s
				    AND ssl_mutation_kind = %s AND ssl_mutation_phase = %s",
				$mapping_id,
				$revision,
				$token,
				$kind->value,
				$phase->value
			)
		);
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

	/**
	 * Fences the original worker before any provider read.
	 *
	 * @return array{token: string, revision: int}|null
	 */
	public function claim_recovery( int $mapping_id, string $old_token ): ?array {
		global $wpdb;

		$table   = Schema::domains_table();
		$new     = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + TimingPolicy::recovery_grace() );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = %s, ssl_mutation_phase = %s,
				        ssl_mutation_expires_at = %s, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND ssl_mutation_token = %s
				    AND ssl_mutation_phase IN (%s, %s) AND ssl_mutation_expires_at <= %s",
				$new,
				MutationPhase::RECOVERING->value,
				$expires,
				$this->clock->mysql(),
				$mapping_id,
				$old_token,
				MutationPhase::IN_FLIGHT->value,
				MutationPhase::RECOVERING->value,
				$this->clock->mysql()
			)
		);

		if ( 1 !== $affected ) {
			return null;
		}

		$revision = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT revision FROM {$table} WHERE id = %d", $mapping_id ) // phpcs:ignore WordPress.DB
		);

		return array( 'token' => $new, 'revision' => $revision );
	}

	/** Extends a held RECOVERING lease without changing its owner. */
	public function extend_recovery( int $mapping_id, int $revision, string $token ): bool {
		global $wpdb;

		$table   = Schema::domains_table();
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + TimingPolicy::recovery_grace() );

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_expires_at = %s, revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token = %s AND ssl_mutation_phase = %s",
				$expires,
				$this->clock->mysql(),
				$mapping_id,
				$revision,
				$token,
				MutationPhase::RECOVERING->value
			)
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter MutationLeaseTest`
Expected: PASS — 30 tests (including the six phase-and-expiry cases and the nine
changed-column cases)

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/LeaseBinding.php src/Ssl/LeaseOutcome.php src/Ssl/MutationLease.php tests/integration/Ssl/MutationLeaseTest.php
git commit -m "Bind every authorization value into the consumption CAS

Ownership origin and owner installation are re-checked alongside host, provider,
reference, challenge, and method, with null-safe comparisons. Finalization takes
a typed outcome whose column names come from an allowlist, never from a caller."
```

---

### Task 4: The execution permit and the authorization

**Files:**
- Create: `src/Ssl/MutationOperation.php`, `src/Ssl/ExecutionPermit.php`, `src/Ssl/MutationAuthorization.php`, `src/Ssl/MutationRefusal.php`
- Test: `tests/unit/Ssl/ExecutionPermitTest.php`

**Interfaces:**
- Consumes: `MutationKind` (Plan 02), `LeaseBinding` (Task 3), `SslResourceContext` (Task 1).
- Produces:
  - `PostDomain\Ssl\MutationOperation` enum — `CREATE`, `ADOPT`, `CHANGE_METHOD`, `REMOVE`, with `::kind(): MutationKind`.
  - `PostDomain\Ssl\ExecutionPermit` — **private constructor**; created only by `::issue()`, which throws unless its immediate caller is `MutationGate`. Carries `MutationOperation $operation`, `int $mapping_id`, `int $in_flight_revision`, `string $lease_token`, `\DateTimeImmutable $expires_at`; plus `::assert_for( MutationOperation $op, SslResourceContext $ctx ): void`.
  - `PostDomain\Ssl\MutationAuthorization` — `MutationOperation $operation`, `LeaseBinding $binding`, `bool $override_foreign_marker`, `\DateTimeImmutable $expires_at`, `::is_expired()`.
  - `PostDomain\Ssl\MutationRefusal`.

A permit any service could construct would let that service call a driver without
consuming an authorization, so the constructor is private and issuance is
caller-checked.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/ExecutionPermitTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\SslResourceContext;

final class ExecutionPermitTest extends TestCase {

	private const TOKEN = '11111111111111111111111111111111';

	private function context( string $token, int $mapping_id = 12 ): SslResourceContext {
		return new SslResourceContext(
			$mapping_id, 'mapped.test', 'install-a', 'test-driver', 'ref-1', null, null,
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 'abc', 4, $token, 'txt'
		);
	}

	/** Builds a permit the way MutationGate does, bypassing the caller check for unit isolation. */
	private function issued( string $token, MutationOperation $operation = MutationOperation::CREATE ): ExecutionPermit {
		$reflection  = new \ReflectionClass( ExecutionPermit::class );
		$permit      = $reflection->newInstanceWithoutConstructor();
		$constructor = $reflection->getConstructor();
		$constructor?->setAccessible( true );
		$constructor?->invoke(
			$permit,
			$operation,
			12,
			5,
			$token,
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);

		return $permit;
	}

	public function test_the_constructor_is_private(): void {
		$reflection = new \ReflectionClass( ExecutionPermit::class );

		$this->assertTrue(
			(bool) $reflection->getConstructor()?->isPrivate(),
			'a freely constructible permit would let a service bypass consumption'
		);
	}

	public function test_issuing_from_outside_the_gate_throws(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'MutationGate' );

		ExecutionPermit::issue(
			MutationOperation::CREATE,
			12,
			5,
			self::TOKEN,
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_each_operation_maps_to_a_mutation_kind(): void {
		$this->assertSame( MutationKind::CREATE, MutationOperation::CREATE->kind() );
		$this->assertSame( MutationKind::ADOPT, MutationOperation::ADOPT->kind() );
		$this->assertSame( MutationKind::METHOD, MutationOperation::CHANGE_METHOD->kind() );
		$this->assertSame( MutationKind::REMOVE, MutationOperation::REMOVE->kind() );
	}

	public function test_assert_for_rejects_a_mismatched_operation(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for( MutationOperation::REMOVE, $this->context( self::TOKEN ) );
	}

	public function test_assert_for_rejects_a_mismatched_mapping(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for( MutationOperation::CREATE, $this->context( self::TOKEN, 99 ) );
	}

	public function test_assert_for_rejects_a_mismatched_token(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for(
			MutationOperation::CREATE,
			$this->context( '22222222222222222222222222222222' )
		);
	}

	public function test_assert_for_rejects_a_context_with_no_token(): void {
		$context = new SslResourceContext(
			12, 'mapped.test', 'install-a', 'test-driver', 'ref-1', null, null,
			'_x', 'v', 'abc', 4, null, 'txt'
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->issued( self::TOKEN )->assert_for( MutationOperation::CREATE, $context );
	}

	public function test_assert_for_accepts_a_matching_permit(): void {
		$permit = $this->issued( self::TOKEN );
		$permit->assert_for( MutationOperation::CREATE, $this->context( self::TOKEN ) );

		$this->assertSame( 12, $permit->mapping_id );
		$this->assertSame( 5, $permit->in_flight_revision );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter ExecutionPermitTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\ExecutionPermit" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/MutationOperation.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MutationOperation: string {
	case CREATE        = 'create';
	case ADOPT         = 'adopt';
	case CHANGE_METHOD = 'change_method';
	case REMOVE        = 'remove';

	public function kind(): MutationKind {
		return match ( $this ) {
			self::CREATE        => MutationKind::CREATE,
			self::ADOPT         => MutationKind::ADOPT,
			self::CHANGE_METHOD => MutationKind::METHOD,
			self::REMOVE        => MutationKind::REMOVE,
		};
	}
}
```

Create `src/Ssl/ExecutionPermit.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Proof that the authorization was consumed by the RESERVED -> IN_FLIGHT
 * transition. The constructor is private and issue() refuses any caller other
 * than MutationGate, so no service can fabricate one and skip consumption.
 */
final class ExecutionPermit {

	private function __construct(
		public readonly MutationOperation $operation,
		public readonly int $mapping_id,
		public readonly int $in_flight_revision,
		public readonly string $lease_token,
		public readonly \DateTimeImmutable $expires_at
	) {}

	public static function issue(
		MutationOperation $operation,
		int $mapping_id,
		int $in_flight_revision,
		string $lease_token,
		\DateTimeImmutable $expires_at
	): self {
		$frame  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? array();
		$caller = $frame['class'] ?? '';

		if ( MutationGate::class !== $caller ) {
			throw new \LogicException( 'Execution permits are issued only by MutationGate.' );
		}

		return new self( $operation, $mapping_id, $in_flight_revision, $lease_token, $expires_at );
	}

	public function assert_for( MutationOperation $operation, SslResourceContext $context ): void {
		if ( $this->operation !== $operation ) {
			throw new \InvalidArgumentException(
				sprintf( 'Permit is for %s, not %s.', $this->operation->value, $operation->value )
			);
		}

		if ( $this->mapping_id !== $context->mapping_id ) {
			throw new \InvalidArgumentException( 'Permit and context describe different mappings.' );
		}

		if ( null === $context->lease_token || ! hash_equals( $this->lease_token, $context->lease_token ) ) {
			throw new \InvalidArgumentException( 'Permit and context describe different executions.' );
		}
	}
}
```

Create `src/Ssl/MutationAuthorization.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/** In-process only: never persisted, serialized, or logged. */
final class MutationAuthorization {

	public function __construct(
		public readonly MutationOperation $operation,
		public readonly LeaseBinding $binding,
		public readonly bool $override_foreign_marker,
		public readonly \DateTimeImmutable $expires_at
	) {}

	public function is_expired( \DateTimeImmutable $now ): bool {
		return $this->expires_at <= $now;
	}
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

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter ExecutionPermitTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/MutationOperation.php src/Ssl/ExecutionPermit.php src/Ssl/MutationAuthorization.php src/Ssl/MutationRefusal.php tests/unit/Ssl/ExecutionPermitTest.php
git commit -m "Make execution permits issuable only by the gate

A permit any service could construct would let that service call a driver
without consuming an authorization, which is the whole thing the gate exists to
prevent."
```

---

### Task 5: The driver contract and the null driver

**Files:**
- Create: `src/Ssl/SslStatus.php`, `src/Ssl/RemovalOutcome.php`, `src/Ssl/RemovalResult.php`, `src/Ssl/ReconcileReport.php`, `src/Ssl/DriverCapabilities.php`, `src/Ssl/ValidationPlan.php`, `src/Contracts/SslDriver.php`, `src/Ssl/NullDriver.php`
- Test: `tests/unit/Ssl/NullDriverTest.php`

**Interfaces:**
- Consumes: `ExecutionPermit`, `MutationOperation` (Task 4), `SslResourceContext`, `IdentityResult` (Task 1).
- Produces: `PostDomain\Contracts\SslDriver` and `NullDriver`.

Every mutating method calls `$permit->assert_for( …, $ctx )` first, so a permit
for the wrong operation, mapping, or execution is refused at the driver boundary
as well as at the gate.

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
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\SslResourceContext;

final class NullDriverTest extends TestCase {

	private const TOKEN = '11111111111111111111111111111111';

	private function context( string $token = self::TOKEN ): SslResourceContext {
		return new SslResourceContext(
			12, 'mapped.test', 'install-a', 'null', null, null, null,
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 'abc', 4, $token
		);
	}

	private function permit( MutationOperation $operation ): ExecutionPermit {
		$reflection  = new \ReflectionClass( ExecutionPermit::class );
		$permit      = $reflection->newInstanceWithoutConstructor();
		$constructor = $reflection->getConstructor();
		$constructor?->setAccessible( true );
		$constructor?->invoke(
			$permit,
			$operation,
			12,
			5,
			self::TOKEN,
			new \DateTimeImmutable( '+1 minute', new \DateTimeZone( 'UTC' ) )
		);

		return $permit;
	}

	public function test_the_id_and_capabilities_are_stable(): void {
		$driver = new NullDriver();

		$this->assertSame( 'null', $driver->id() );
		$this->assertFalse( $driver->capabilities()->supports_markers );
		$this->assertSame( array(), $driver->capabilities()->validation_methods );
	}

	public function test_status_says_certificates_are_handled_elsewhere(): void {
		$status = ( new NullDriver() )->status( $this->context() );

		$this->assertSame( SslState::NONE, $status->state );
		$this->assertStringContainsString( 'outside', (string) $status->message );
	}

	public function test_identity_is_absent_complete_and_not_transient(): void {
		$identity = ( new NullDriver() )->identify( $this->context() );

		$this->assertSame( IdentityVerdict::ABSENT, $identity->verdict );
		$this->assertTrue( $identity->read_complete );
		$this->assertFalse( $identity->transient );
	}

	public function test_create_changes_nothing(): void {
		$status = ( new NullDriver() )->create( $this->context(), $this->permit( MutationOperation::CREATE ) );

		$this->assertSame( SslState::NONE, $status->state );
		$this->assertNull( $status->ref );
	}

	public function test_removal_reports_removed_because_nothing_exists(): void {
		$result = ( new NullDriver() )->remove( $this->context(), $this->permit( MutationOperation::REMOVE ) );

		$this->assertSame( RemovalOutcome::REMOVED, $result->outcome );
	}

	public function test_a_permit_for_the_wrong_operation_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new NullDriver() )->remove( $this->context(), $this->permit( MutationOperation::CREATE ) );
	}

	public function test_a_permit_for_a_different_execution_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new NullDriver() )->create(
			$this->context( '22222222222222222222222222222222' ),
			$this->permit( MutationOperation::CREATE )
		);
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

Create `src/Ssl/ValidationPlan.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPlan {

	/**
	 * @param array<string, array<int, object>> $dns
	 * @param array<int, object>                $http
	 * @param array<int, object>                $manual
	 * @param array<int, object>                $pending
	 * @param array<int, object>                $blockers
	 */
	public function __construct(
		public readonly array $dns,
		public readonly array $http,
		public readonly array $manual,
		public readonly array $pending,
		public readonly array $blockers
	) {}

	/** True when a purpose offers more than one genuinely sufficient route. */
	public function alternatives_for( string $purpose ): bool {
		$dns  = count( $this->dns[ $purpose ] ?? array() );
		$http = count(
			array_filter( $this->http, static fn( object $h ): bool => ( $h->purpose ?? '' ) === $purpose )
		);

		return ( $dns + $http ) > 1;
	}
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
 * Mutating methods take an ExecutionPermit and are invoked only by MutationGate.
 * Each asserts the permit against its own operation and context before acting.
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

		return new SslStatus(
			SslState::NONE,
			null,
			'handled_externally',
			'Certificates are handled outside this plugin.'
		);
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
		$permit->assert_for( MutationOperation::CREATE, $ctx );

		return $this->status( $ctx );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::ADOPT, $ctx );

		return $this->status( $ctx );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CHANGE_METHOD, $ctx );
		unset( $method );

		return $this->status( $ctx );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_for( MutationOperation::REMOVE, $ctx );

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

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter NullDriverTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/SslStatus.php src/Ssl/RemovalOutcome.php src/Ssl/RemovalResult.php src/Ssl/ReconcileReport.php src/Ssl/DriverCapabilities.php src/Ssl/ValidationPlan.php src/Contracts/SslDriver.php src/Ssl/NullDriver.php tests/unit/Ssl/NullDriverTest.php
git commit -m "Define the SSL driver contract with a working null default

Every mutating method asserts its permit against its own operation, mapping, and
execution, so the driver boundary re-checks what the gate already enforced."
```

---

### Task 6: The mutation gate

**Files:**
- Create: `src/Ssl/GateResult.php`, `src/Ssl/MutationGate.php`
- Create: `tests/integration/Ssl/Fixtures/RecordingDriver.php`
- Test: `tests/integration/Ssl/MutationGateTest.php`

**Interfaces:**
- Consumes: `MutationLease` (Task 3), `MutationAuthorization`, `ExecutionPermit`, `MutationOperation` (Task 4), `SslDriver` (Task 5).
- Produces:
  - `PostDomain\Ssl\GateResult` — readonly `object $result` (an `SslStatus` or `RemovalResult`), `int $in_flight_revision`, `string $lease_token`, `MutationOperation $operation`.
  - `PostDomain\Ssl\MutationGate::__construct( MutationLease $lease, Clock $clock )` with `::execute( SslDriver $driver, SslResourceContext $context, MutationAuthorization $auth, ?string $argument = null ): GateResult|MutationRefusal` and `::release( MutationAuthorization $auth ): void`.
  - `PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver` — the shared driver double used here and in Plans 08 and 09.

**The gate dispatches to the driver itself.** No service names a mutating method,
holds a permit, or passes a callback.

- [ ] **Step 1: Write the failing test**

Create the shared fixture `tests/integration/Ssl/Fixtures/RecordingDriver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl\Fixtures;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\DriverCapabilities;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\ProviderMarker;
use PostDomain\Ssl\ReconcileReport;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Ssl\SslStatus;
use PostDomain\Ssl\ValidationPlan;

/** A configurable driver double shared by Plans 07, 08, and 09. */
final class RecordingDriver implements SslDriver {

	public int $create_calls = 0;

	public int $adopt_calls = 0;

	public int $method_calls = 0;

	public int $remove_calls = 0;

	public int $identify_calls = 0;

	/** @var string[] */
	public array $phases_observed = array();

	private function __construct(
		private readonly ?string $created_ref,
		private readonly bool $create_is_ambiguous,
		private readonly IdentityVerdict $verdict,
		private readonly ?string $observed_ref,
		private readonly ?string $marker_installation,
		private readonly MarkerSupport $marker_support,
		private readonly bool $identity_complete = true,
		private readonly RemovalOutcome $removal = RemovalOutcome::REMOVED,
		private readonly ?string $confirmed_method = 'txt'
	) {}

	public static function succeeding( string $ref ): self {
		return new self( $ref, false, IdentityVerdict::MATCH, $ref, null, MarkerSupport::UNAVAILABLE );
	}

	public static function with_identity( IdentityVerdict $verdict ): self {
		return new self( 'ref-1', false, $verdict, 'ref-1', null, MarkerSupport::UNAVAILABLE );
	}

	public static function with_incomplete_identity(): self {
		return new self( 'ref-1', false, IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, false );
	}

	public static function with_foreign_marker(): self {
		return new self( 'ref-1', false, IdentityVerdict::MATCH, 'ref-1', 'someone-else', MarkerSupport::SUPPORTED );
	}

	public static function ambiguous_then_marked( string $ref ): self {
		return new self( null, true, IdentityVerdict::RECOVERABLE_CREATE, $ref, 'self', MarkerSupport::SUPPORTED );
	}

	public static function ambiguous_then_unmarked( string $ref ): self {
		return new self( null, true, IdentityVerdict::MISMATCH, $ref, null, MarkerSupport::UNAVAILABLE );
	}

	public static function ambiguous_then_foreign( string $ref ): self {
		return new self( null, true, IdentityVerdict::MISMATCH, $ref, 'someone-else', MarkerSupport::SUPPORTED );
	}

	public static function ambiguous_then_absent(): self {
		return new self( null, true, IdentityVerdict::ABSENT, null, null, MarkerSupport::SUPPORTED );
	}

	public static function removing( RemovalOutcome $outcome ): self {
		return new self( 'ref-1', false, IdentityVerdict::MATCH, 'ref-1', null, MarkerSupport::UNAVAILABLE, true, $outcome );
	}

	public static function confirming_method( string $method ): self {
		return new self(
			'ref-1', false, IdentityVerdict::MATCH, 'ref-1', null, MarkerSupport::UNAVAILABLE,
			true, RemovalOutcome::REMOVED, $method
		);
	}

	public function id(): string {
		return 'recording';
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities(
			MarkerSupport::SUPPORTED === $this->marker_support,
			array( 'txt', 'http' ),
			false
		);
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		return new SslStatus( SslState::REQUESTED, $ctx->provider_ref, null, null, $this->confirmed_method );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		++$this->identify_calls;

		$marker = null;

		if ( 'self' === $this->marker_installation ) {
			$marker = new ProviderMarker( $ctx->installation_id, $ctx->mapping_id, array() );
		} elseif ( null !== $this->marker_installation ) {
			$marker = new ProviderMarker( $this->marker_installation, $ctx->mapping_id, array() );
		}

		return new IdentityResult(
			$this->verdict,
			$ctx->provider_ref,
			$this->observed_ref,
			$ctx->host,
			$marker,
			$this->marker_support,
			$this->identity_complete,
			! $this->identity_complete
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CREATE, $ctx );
		++$this->create_calls;
		$this->observe_phase( $ctx );

		if ( $this->create_is_ambiguous ) {
			return new SslStatus( SslState::NONE, null, 'timeout', 'ambiguous', null, true );
		}

		return new SslStatus( SslState::REQUESTED, $this->created_ref );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::ADOPT, $ctx );
		++$this->adopt_calls;
		$this->observe_phase( $ctx );

		return new SslStatus( SslState::REQUESTED, $this->observed_ref );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CHANGE_METHOD, $ctx );
		++$this->method_calls;
		$this->observe_phase( $ctx );
		unset( $method );

		return new SslStatus( SslState::REQUESTED, $ctx->provider_ref, null, null, $this->confirmed_method );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_for( MutationOperation::REMOVE, $ctx );
		++$this->remove_calls;
		$this->observe_phase( $ctx );

		return new RemovalResult( $this->removal );
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

	private function observe_phase( SslResourceContext $ctx ): void {
		$row                     = ( new DbRepository() )->by_id( $ctx->mapping_id );
		$this->phases_observed[] = (string) $row?->ssl_mutation_phase?->value;
	}
}
```

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
use PostDomain\Ssl\GateResult;
use PostDomain\Ssl\LeaseBinding;
use PostDomain\Ssl\MutationAuthorization;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationOperation;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use WP_UnitTestCase;

final class MutationGateTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationLease $lease;

	private MutationGate $gate;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo  = new DbRepository();
		$this->lease = new MutationLease( new SystemClock() );
		$this->gate  = new MutationGate( $this->lease, new SystemClock() );
	}

	private function seed(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				null, null, 'recording', null
			)
		);
	}

	/** @return array{auth: MutationAuthorization, context: SslResourceContext, token: string, revision: int} */
	private function reserved( Mapping $m, MutationOperation $op ): array {
		$lease  = $this->lease->acquire( $m->id, $m->revision, $op->kind() );
		$leased = $this->repo->by_id( $m->id );

		$binding = new LeaseBinding(
			$leased->id, $lease['revision'], $lease['token'], $op->kind(), $leased->host,
			$leased->ssl_provider, $leased->ssl_ref, $leased->challenge, $leased->ssl_method,
			$leased->ssl_ownership_origin, $leased->ssl_owner_installation_id
		);

		return array(
			'auth'     => new MutationAuthorization(
				$op,
				$binding,
				false,
				new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
			),
			'context'  => SslResourceContext::from_mapping( $leased, 'install-a', '_x.mapped.test', $lease['token'] ),
			'token'    => $lease['token'],
			'revision' => $lease['revision'],
		);
	}

	public function test_the_phase_is_already_in_flight_when_the_driver_runs(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );

		$result = $this->gate->execute( $driver, $setup['context'], $setup['auth'] );

		$this->assertInstanceOf( GateResult::class, $result );
		$this->assertSame( 1, $driver->create_calls );
		$this->assertSame(
			array( 'in_flight' ),
			$driver->phases_observed,
			'consumption happens before the driver is entered'
		);
	}

	public function test_the_gate_returns_the_in_flight_revision_rather_than_a_guess(): void {
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );
		$result = $this->gate->execute( RecordingDriver::succeeding( 'ref-1' ), $setup['context'], $setup['auth'] );

		$this->assertSame( $setup['revision'] + 1, $result->in_flight_revision );
	}

	public function test_a_stale_authorization_never_reaches_the_driver(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );
		$b      = $setup['auth']->binding;

		$stale = new MutationAuthorization(
			MutationOperation::CREATE,
			new LeaseBinding(
				$b->mapping_id, $b->revision + 7, $b->token, $b->kind, $b->host,
				$b->provider_id, $b->provider_ref, $b->challenge, $b->requested_method,
				$b->ownership_origin, $b->owner_installation_id
			),
			false,
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->gate->execute( $driver, $setup['context'], $stale );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 0, $driver->create_calls );
	}

	public function test_the_same_authorization_cannot_begin_a_second_execution(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $this->seed(), MutationOperation::CREATE );

		$this->gate->execute( $driver, $setup['context'], $setup['auth'] );
		$second = $this->gate->execute( $driver, $setup['context'], $setup['auth'] );

		$this->assertInstanceOf( MutationRefusal::class, $second );
		$this->assertSame( 1, $driver->create_calls );
	}

	public function test_an_expired_authorization_is_refused_and_releases_the_reservation(): void {
		$m      = $this->seed();
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$setup  = $this->reserved( $m, MutationOperation::CREATE );

		$expired = new MutationAuthorization(
			MutationOperation::CREATE,
			$setup['auth']->binding,
			false,
			new \DateTimeImmutable( '-1 second', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->gate->execute( $driver, $setup['context'], $expired );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'authorization_expired', $result->precondition );
		$this->assertSame( 0, $driver->create_calls );
		$this->assertNull(
			$this->repo->by_id( $m->id )?->ssl_mutation_token,
			'a pre-consumption refusal releases the reservation'
		);
	}

	/**
	 * @dataProvider operations
	 */
	public function test_each_operation_dispatches_to_its_own_driver_method( string $operation, string $counter ): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );
		$op     = MutationOperation::from( $operation );
		$setup  = $this->reserved( $this->seed(), $op );

		$this->gate->execute( $driver, $setup['context'], $setup['auth'], 'txt' );

		$this->assertSame( 1, $driver->{$counter} );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function operations(): array {
		return array(
			'create' => array( 'create', 'create_calls' ),
			'adopt'  => array( 'adopt', 'adopt_calls' ),
			'method' => array( 'change_method', 'method_calls' ),
			'remove' => array( 'remove', 'remove_calls' ),
		);
	}

	public function test_only_the_gate_lexically_calls_a_mutating_driver_method(): void {
		$mutating  = array( 'create', 'adopt', 'change_validation_method', 'remove' );
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() || 'MutationGate.php' === $file->getFilename() ) {
				continue;
			}

			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];

				if ( ! is_array( $token )
					|| ! in_array( $token[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					continue;
				}

				$j = $i + 1;

				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}

				if ( ! is_array( $tokens[ $j ] ?? null ) || T_STRING !== $tokens[ $j ][0] ) {
					continue;
				}

				if ( ! in_array( $tokens[ $j ][1], $mutating, true ) ) {
					continue;
				}

				$k = $j + 1;

				while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					++$k;
				}

				if ( '(' === ( $tokens[ $k ] ?? null ) ) {
					$offenders[] = $file->getFilename() . ':' . $tokens[ $j ][2] . ' ->' . $tokens[ $j ][1] . '()';
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a mutating driver method may be called only from MutationGate'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter MutationGateTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\GateResult" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/GateResult.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class GateResult {

	/** @param SslStatus|RemovalResult $result */
	public function __construct(
		public readonly object $result,
		public readonly int $in_flight_revision,
		public readonly string $lease_token,
		public readonly MutationOperation $operation
	) {}
}
```

Create `src/Ssl/MutationGate.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\SslDriver;

/**
 * The only component that invokes a mutating driver method. Services hand it a
 * driver, a context, and an authorization; it consumes the authorization, issues
 * the permit, and dispatches.
 */
final class MutationGate {

	public function __construct(
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/** @return GateResult|MutationRefusal */
	public function execute(
		SslDriver $driver,
		SslResourceContext $context,
		MutationAuthorization $auth,
		?string $argument = null
	) {
		if ( $auth->is_expired( $this->clock->now() ) ) {
			$this->release( $auth );

			return new MutationRefusal( 'authorization_expired', true );
		}

		if ( $driver->id() !== $context->provider_id && 'null' !== $context->provider_id ) {
			$this->release( $auth );

			return new MutationRefusal( 'driver_context_mismatch', false );
		}

		$in_flight = $this->lease->consume( $auth->binding );

		if ( null === $in_flight ) {
			// The provider is never called. The lease is left alone: this worker
			// no longer owns it, so releasing it would be someone else's write.
			return new MutationRefusal( 'authorization_not_consumable', true, 'the mapping changed underneath' );
		}

		$permit = ExecutionPermit::issue(
			$auth->operation,
			$auth->binding->mapping_id,
			$in_flight,
			$auth->binding->token,
			$auth->expires_at
		);

		$result = match ( $auth->operation ) {
			MutationOperation::CREATE        => $driver->create( $context, $permit ),
			MutationOperation::ADOPT         => $driver->adopt( $context, $permit ),
			MutationOperation::CHANGE_METHOD => $driver->change_validation_method( $context, (string) $argument, $permit ),
			MutationOperation::REMOVE        => $driver->remove( $context, $permit ),
		};

		return new GateResult( $result, $in_flight, $auth->binding->token, $auth->operation );
	}

	/** Releases the reservation held by an authorization that will never be consumed. */
	public function release( MutationAuthorization $auth ): void {
		$this->lease->release_reserved(
			$auth->binding->mapping_id,
			$auth->binding->revision,
			$auth->binding->token,
			$auth->binding->kind
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter MutationGateTest`
Expected: PASS — 9 tests (including the four dispatch cases and the token scan)

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/GateResult.php src/Ssl/MutationGate.php tests/integration/Ssl/Fixtures/RecordingDriver.php tests/integration/Ssl/MutationGateTest.php
git commit -m "Make the gate the only caller of a mutating driver method

Services hand over a driver, context, authorization, and operation; the gate
consumes, issues the permit, and dispatches. A token scan over src/ proves no
other file names create, adopt, change_validation_method, or remove on an
object."
```

---

### Task 7: Lease recovery

**Files:**
- Create: `src/Ssl/RecoveryOutcome.php`, `src/Ssl/RecoveryResolver.php`, `src/Ssl/LeaseRecovery.php`
- Test: `tests/integration/Ssl/LeaseRecoveryTest.php`

**Interfaces:**
- Consumes: `MutationLease` (Task 3), `TimingPolicy` (Task 2), `MappingRepository` (Plan 02).
- Produces:
  - `PostDomain\Ssl\RecoveryOutcome` — readonly `bool $conclusive`, `?LeaseOutcome $apply`, `bool $delete_row`, `string $note`; named constructors `::inconclusive()`, `::apply()`, `::delete()`.
  - `PostDomain\Ssl\RecoveryResolver` interface — `resolve( Mapping $m, MutationKind $kind, string $recovery_token ): RecoveryOutcome`.
  - `PostDomain\Ssl\LeaseRecovery::__construct( MutationLease $lease, MappingRepository $repo, Clock $clock )` with `::due( int $batch ): Mapping[]` and `::recover( Mapping $m, RecoveryResolver $resolver ): string` returning `cleared`, `resolved`, `deleted`, `still_recovering`, or `skipped`.

`due()` is the **only** work selector in the plugin that finds rows by lease
expiry. The per-kind resolution lives behind `RecoveryResolver`; Plan 08 supplies
the driver-backed implementation that reads provider state and never re-issues a
mutation.

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
use PostDomain\Ssl\LeaseOutcome;
use PostDomain\Ssl\LeaseRecovery;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationPhase;
use PostDomain\Ssl\RecoveryOutcome;
use PostDomain\Ssl\RecoveryResolver;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use WP_UnitTestCase;

final class LeaseRecoveryTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private MutationLease $lease;

	private LeaseRecovery $recovery;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo     = new DbRepository();
		$this->lease    = new MutationLease( new SystemClock() );
		$this->recovery = new LeaseRecovery( $this->lease, $this->repo, new SystemClock() );
	}

	private function resolver( RecoveryOutcome $outcome, ?callable $spy = null ): RecoveryResolver {
		return new class( $outcome, $spy ) implements RecoveryResolver {
			/** @param callable|null $spy */
			public function __construct(
				private readonly RecoveryOutcome $outcome,
				private $spy
			) {}

			public function resolve( Mapping $mapping, MutationKind $kind, string $recovery_token ): RecoveryOutcome {
				if ( null !== $this->spy ) {
					( $this->spy )( $mapping, $kind, $recovery_token );
				}

				return $this->outcome;
			}
		};
	}

	private function seed( string $host, ?MutationPhase $phase, int $offset, MutationKind $kind = MutationKind::CREATE ): Mapping {
		global $wpdb;

		$m = $this->repo->save(
			new Mapping(
				0, $host, null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::REQUESTED,
				null, substr( md5( $host ), 0, 32 ), '_post-domain-challenge'
			)
		);

		if ( null !== $phase ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				Schema::domains_table(),
				array(
					'ssl_mutation_token'      => bin2hex( random_bytes( 16 ) ),
					'ssl_mutation_kind'       => $kind->value,
					'ssl_mutation_phase'      => $phase->value,
					'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
				),
				array( 'id' => $m->id )
			);
		}

		return $this->repo->by_id( $m->id );
	}

	public function test_only_expired_leases_are_selected(): void {
		$expired  = $this->seed( 'expired.test', MutationPhase::IN_FLIGHT, -600 );
		$live     = $this->seed( 'live.test', MutationPhase::IN_FLIGHT, 600 );
		$unleased = $this->seed( 'free.test', null, 0 );

		$ids = array_map( static fn( Mapping $m ): int => $m->id, $this->recovery->due( 50 ) );

		$this->assertContains( $expired->id, $ids );
		$this->assertNotContains( $live->id, $ids );
		$this->assertNotContains( $unleased->id, $ids );
	}

	public function test_an_expired_reserved_lease_clears_without_calling_the_resolver(): void {
		$m     = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );
		$calls = 0;

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::inconclusive( 'unused' ),
				static function () use ( &$calls ): void {
					++$calls;
				}
			)
		);

		$this->assertSame( 'cleared', $outcome );
		$this->assertSame( 0, $calls, 'nothing was sent, so nothing needs reading' );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_a_cleared_row_becomes_ordinarily_acquirable(): void {
		$m = $this->seed( 'reserved.test', MutationPhase::RESERVED, -600 );
		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertNotNull( $this->lease->acquire( $after->id, $after->revision, MutationKind::CREATE ) );
	}

	public function test_the_fence_precedes_the_resolver_and_hands_it_the_new_token(): void {
		$m        = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$observed = array();

		$this->recovery->recover(
			$m,
			$this->resolver(
				RecoveryOutcome::inconclusive( 'still reading' ),
				function ( Mapping $mapping, MutationKind $kind, string $token ) use ( &$observed ): void {
					$row        = ( new DbRepository() )->by_id( $mapping->id );
					$observed[] = array(
						'phase' => $row?->ssl_mutation_phase?->value,
						'token' => $row?->ssl_mutation_token,
						'kind'  => $kind->value,
						'given' => $token,
					);
				}
			)
		);

		$this->assertSame( 'recovering', $observed[0]['phase'] );
		$this->assertSame( $observed[0]['token'], $observed[0]['given'] );
		$this->assertSame( 'create', $observed[0]['kind'], 'the preserved kind drives the dispatch' );
	}

	public function test_the_fenced_original_worker_cannot_finalize(): void {
		$m   = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );
		$old = (string) $m->ssl_mutation_token;

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$this->assertFalse(
			$this->lease->finalize(
				$m->id, $m->revision, $old, MutationKind::CREATE, MutationPhase::IN_FLIGHT,
				LeaseOutcome::state( SslState::ACTIVE )
			)
		);
	}

	public function test_a_conclusive_outcome_is_applied_under_the_recovery_token(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver( RecoveryOutcome::apply( LeaseOutcome::state( SslState::ACTIVE ), 'confirmed active' ) )
		);

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'resolved', $outcome );
		$this->assertSame( SslState::ACTIVE, $after?->ssl_state );
		$this->assertNull( $after?->ssl_mutation_token, 'the lease is cleared with the result' );
	}

	public function test_a_conclusive_removal_deletes_the_row(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600, MutationKind::REMOVE );

		$outcome = $this->recovery->recover(
			$m,
			$this->resolver( RecoveryOutcome::delete( 'provider confirms absent' ) )
		);

		$this->assertSame( 'deleted', $outcome );
		$this->assertNull( $this->repo->by_id( $m->id ) );
	}

	public function test_an_inconclusive_outcome_stays_recovering_and_renews_the_lease(): void {
		$m = $this->seed( 'inflight.test', MutationPhase::IN_FLIGHT, -600 );

		$outcome = $this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'provider silent' ) ) );

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( 'still_recovering', $outcome );
		$this->assertSame( MutationPhase::RECOVERING, $after?->ssl_mutation_phase );
		$this->assertGreaterThan(
			gmdate( 'Y-m-d H:i:s' ),
			(string) $after?->ssl_mutation_expires_at,
			'the recovery TTL is renewed under the owning token'
		);
	}

	public function test_an_expired_recovering_lease_can_be_taken_over(): void {
		$m   = $this->seed( 'recovering.test', MutationPhase::RECOVERING, -600 );
		$old = (string) $m->ssl_mutation_token;

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$this->assertNotSame( $old, $this->repo->by_id( $m->id )?->ssl_mutation_token );
	}

	public function test_a_superseded_recovery_worker_cannot_apply_a_result(): void {
		$m   = $this->seed( 'recovering.test', MutationPhase::RECOVERING, -600 );
		$old = (string) $m->ssl_mutation_token;

		$this->recovery->recover( $m, $this->resolver( RecoveryOutcome::inconclusive( 'x' ) ) );

		$this->assertFalse(
			$this->lease->finalize(
				$m->id, $m->revision, $old, MutationKind::CREATE, MutationPhase::RECOVERING,
				LeaseOutcome::state( SslState::ACTIVE )
			)
		);
	}

	public function test_recovery_never_issues_a_provider_mutation(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Ssl/LeaseRecovery.php' );

		foreach ( array( 'MutationGate', 'MutationAuthorization', 'ExecutionPermit' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $source );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter LeaseRecoveryTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\RecoveryOutcome" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/RecoveryOutcome.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class RecoveryOutcome {

	private function __construct(
		public readonly bool $conclusive,
		public readonly ?LeaseOutcome $apply,
		public readonly bool $delete_row,
		public readonly string $note
	) {}

	public static function inconclusive( string $note ): self {
		return new self( false, null, false, $note );
	}

	public static function apply( LeaseOutcome $outcome, string $note ): self {
		return new self( true, $outcome, false, $note );
	}

	public static function delete( string $note ): self {
		return new self( true, null, true, $note );
	}
}
```

Create `src/Ssl/RecoveryResolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\Mapping;

/**
 * Reads provider state for a fenced mutation and decides what it means. It never
 * issues another provider mutation.
 */
interface RecoveryResolver {

	public function resolve( Mapping $mapping, MutationKind $kind, string $recovery_token ): RecoveryOutcome;
}
```

Create `src/Ssl/LeaseRecovery.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\Schema;

/**
 * The only claimant of expired leases, and the only work selector in the plugin
 * that finds rows by lease expiry.
 */
final class LeaseRecovery {

	public function __construct(
		private readonly MutationLease $lease,
		private readonly MappingRepository $repo,
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

	public function recover( Mapping $mapping, RecoveryResolver $resolver ): string {
		$token = $mapping->ssl_mutation_token;
		$phase = $mapping->ssl_mutation_phase;
		$kind  = $mapping->ssl_mutation_kind;

		if ( null === $token || null === $phase || null === $kind ) {
			return 'skipped';
		}

		if ( MutationPhase::RESERVED === $phase ) {
			// Nothing was sent: clear without contacting the provider.
			return $this->lease->clear_expired_reserved( $mapping->id, $token ) ? 'cleared' : 'skipped';
		}

		$claim = $this->lease->claim_recovery( $mapping->id, $token );

		if ( null === $claim ) {
			return 'skipped';
		}

		// Re-read under the recovery token so the resolver sees current state.
		$fenced = $this->repo->by_id( $mapping->id );

		if ( null === $fenced ) {
			return 'skipped';
		}

		$outcome = $resolver->resolve( $fenced, $kind, $claim['token'] );

		EventLog::record(
			$mapping->id,
			$mapping->host,
			'ssl',
			$phase->value,
			$outcome->conclusive ? 'recovered' : 'recovering',
			'cron',
			array( 'kind' => $kind->value, 'note' => $outcome->note )
		);

		if ( ! $outcome->conclusive ) {
			$this->lease->extend_recovery( $mapping->id, $claim['revision'], $claim['token'] );

			return 'still_recovering';
		}

		if ( $outcome->delete_row ) {
			return $this->lease->delete_row(
				$mapping->id,
				$claim['revision'],
				$claim['token'],
				$kind,
				MutationPhase::RECOVERING
			) ? 'deleted' : 'skipped';
		}

		$applied = $this->lease->finalize(
			$mapping->id,
			$claim['revision'],
			$claim['token'],
			$kind,
			MutationPhase::RECOVERING,
			$outcome->apply ?? LeaseOutcome::checked()
		);

		return $applied ? 'resolved' : 'skipped';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter LeaseRecoveryTest`
Expected: PASS — 11 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/RecoveryOutcome.php src/Ssl/RecoveryResolver.php src/Ssl/LeaseRecovery.php tests/integration/Ssl/LeaseRecoveryTest.php
git commit -m "Recover expired leases to a conclusive, token-owned result

An expired RESERVED lease clears with no read. An expired IN_FLIGHT lease is
fenced first, then resolved by reading, and the result is applied under the
recovery token — or the row deleted under it. An inconclusive read renews the
recovery lease instead of stalling forever."
```

---

### Task 8: Installation identity and clone detection

**Files:**
- Create: `src/Ssl/Environment.php`
- Test: `tests/integration/Ssl/EnvironmentTest.php`

**Interfaces:**
- Consumes: `Schema` (Plan 02), `Challenge` (Plan 06).
- Produces: `PostDomain\Ssl\Environment::installation_id()`, `::primary_host()`, `::remember_primary_host()`, `::check()`, `::is_blocked()`, `::resolve_as_restore()`, `::resolve_as_clone()`.

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
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class EnvironmentTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		$this->repo = new DbRepository();
	}

	private function owned(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'cloudflare-saas', 'ref-1'
			)
		);
	}

	public function test_the_installation_id_is_generated_once_and_persists(): void {
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

	public function test_a_changed_primary_host_blocks(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );

		$mismatch = Environment::check();

		$this->assertSame( 'old-host.test', $mismatch['stored'] );
		$this->assertTrue( Environment::is_blocked() );
	}

	public function test_restore_keeps_identity_ownership_and_challenges(): void {
		$m  = $this->owned();
		$id = Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_restore();

		$after = $this->repo->by_id( $m->id );

		$this->assertSame( $id, Environment::installation_id() );
		$this->assertFalse( Environment::is_blocked() );
		$this->assertSame( OwnershipOrigin::CREATED, $after?->ssl_ownership_origin );
		$this->assertSame( $m->challenge, $after?->challenge );
	}

	public function test_clone_replaces_identity_clears_ownership_and_rotates_challenges(): void {
		$m  = $this->owned();
		$id = Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		Environment::resolve_as_clone();

		$after = $this->repo->by_id( $m->id );

		$this->assertNotSame( $id, Environment::installation_id() );
		$this->assertNull( $after?->ssl_ownership_origin );
		$this->assertNull( $after?->ssl_owner_installation_id );
		$this->assertNull( $after?->ssl_ref );
		$this->assertSame( SslState::NONE, $after?->ssl_state );
		$this->assertNotSame( $m->challenge, $after?->challenge );
		$this->assertSame( VerificationState::UNVERIFIED, $after?->verification_state );
	}

	public function test_a_clone_holds_no_ownership_authority(): void {
		$m = $this->owned();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();
		Environment::resolve_as_clone();

		$after   = $this->repo->by_id( $m->id );
		$context = SslResourceContext::from_mapping(
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

use PostDomain\Mapping\SslState;
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

	/** @return array{stored: string, current: string}|null */
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

		/** @var string[] $ids */
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
					'ssl_state'                 => SslState::NONE->value,
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
Expected: PASS — 6 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/Environment.php tests/integration/Ssl/EnvironmentTest.php
git commit -m "Detect a clone and strip its inherited authority

A clone gets a new installation id, cleared ownership columns, and rotated
challenges, so it fails both the ownership and fresh-proof preconditions without
needing a rule of its own."
```

---

### Task 9: Registry, cooldowns, shared preconditions, and the deletion authorizer

**Files:**
- Create: `src/Ssl/SslDriverRegistry.php`, `src/Ssl/Cooldown.php`, `src/Ssl/AuthorizerSupport.php`, `src/Ssl/DeletionAuthorizer.php`
- Test: `tests/integration/Ssl/DeletionAuthorizerTest.php`

**Interfaces:**
- Consumes: everything above, `FreshProof` (Plan 06).
- Produces:
  - `SslDriverRegistry::register()`, `::get()`, `::default()`.
  - `Cooldown::active_for()`, `::set()`.
  - `PostDomain\Ssl\AuthorizerSupport::open_window( MappingRepository $repo, SslDriverRegistry $reg, MutationLease $lease, Mapping $m, MutationOperation $op ): array{driver: SslDriver, context: SslResourceContext, lease: array{token: string, revision: int}, mapping: Mapping}|MutationRefusal`, `::check_identity( SslDriver $d, SslResourceContext $c, bool $require_bound_match ): ?MutationRefusal`, and `::refuse( MutationLease $l, Mapping $m, ?array $held, MutationKind $k, string $precondition, bool $transient ): MutationRefusal` — which **releases the reservation**.
  - `DeletionAuthorizer::authorize( Mapping $m ): array{auth: MutationAuthorization, context: SslResourceContext, driver: SslDriver}|MutationRefusal`.

Plan 08's create, adopt, and method-change authorizers reuse `AuthorizerSupport`
so all four share one precondition core and one release rule.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/DeletionAuthorizerTest.php`:

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
use PostDomain\Ssl\Cooldown;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MutationAuthorization;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationLease;
use PostDomain\Ssl\MutationRefusal;
use PostDomain\Ssl\NullDriver;
use PostDomain\Ssl\SslDriverRegistry;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
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

	private function authorizer( RecordingDriver $driver, FreshProof $proof ): DeletionAuthorizer {
		$registry = new SslDriverRegistry( new NullDriver() );
		$registry->register( $driver );

		return new DeletionAuthorizer(
			$this->repo,
			$registry,
			$proof,
			new MutationLease( new SystemClock() ),
			new SystemClock()
		);
	}

	private function deletable( string $host = 'mapped.test' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, $host, null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, substr( md5( $host ), 0, 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(), 'recording', 'ref-1'
			)
		);
	}

	private function assert_released( int $mapping_id ): void {
		$this->assertNull(
			$this->repo->by_id( $mapping_id )?->ssl_mutation_token,
			'a refusal before consumption must release the reservation'
		);
	}

	public function test_all_preconditions_met_yields_an_authorization(): void {
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertIsArray( $result );
		$this->assertInstanceOf( MutationAuthorization::class, $result['auth'] );
	}

	public function test_environment_unresolved(): void {
		update_option( 'pd_environment_mismatch', array( 'stored' => 'a', 'current' => 'b' ), false );

		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'environment_unresolved', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_driver_not_registered(): void {
		global $wpdb;

		$m = $this->deletable();
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_provider' => 'absent-driver' ),
			array( 'id' => $m->id )
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->repo->by_id( $m->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'driver_not_registered', $result->precondition );
	}

	public function test_provider_cooldown(): void {
		Cooldown::set( 'recording', 300, '429' );

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->deletable() );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'provider_cooldown', $result->precondition );
	}

	public function test_lease_unavailable(): void {
		$m = $this->deletable();
		( new MutationLease( new SystemClock() ) )->acquire( $m->id, $m->revision, MutationKind::CREATE );

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $this->repo->by_id( $m->id ) );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'lease_unavailable', $result->precondition );
	}

	public function test_identity_not_confirmed(): void {
		$m      = $this->deletable();
		$result = $this->authorizer(
			RecordingDriver::with_identity( IdentityVerdict::MISMATCH ),
			$this->proof( DnsOutcome::MATCH )
		)->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'identity_not_confirmed', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_identity_incomplete_is_transient(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::with_incomplete_identity(), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertTrue( $result->transient );
		$this->assert_released( $m->id );
	}

	public function test_conflicting_marker(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::with_foreign_marker(), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'conflicting_marker', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_no_ownership_authority(): void {
		$m = $this->repo->save(
			new Mapping(
				0, 'unowned.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge',
				null, null, 'recording', null
			)
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_a_foreign_owner_has_no_authority(): void {
		$m = $this->repo->save(
			new Mapping(
				0, 'foreign.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, 'someone-elses-installation', 'recording', 'ref-1'
			)
		);

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'no_ownership_authority', $result->precondition );
	}

	public function test_fresh_proof_failed(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MISMATCH ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertSame( 'fresh_proof_failed', $result->precondition );
		$this->assert_released( $m->id );
	}

	public function test_fresh_proof_transient(): void {
		$m      = $this->deletable();
		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::TRANSIENT ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
		$this->assertTrue( $result->transient );
		$this->assert_released( $m->id );
	}

	public function test_cached_verification_is_not_a_fresh_proof(): void {
		$m = $this->deletable();

		$this->assertSame( VerificationState::VERIFIED, $m->verification_state );

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::NO_RECORD ) )
			->authorize( $m );

		$this->assertInstanceOf( MutationRefusal::class, $result );
	}

	public function test_authorization_survives_pruning_every_event(): void {
		global $wpdb;

		$m = $this->deletable();
		$wpdb->query( 'DELETE FROM ' . Schema::events_table() ); // phpcs:ignore WordPress.DB

		$result = $this->authorizer( RecordingDriver::succeeding( 'ref-1' ), $this->proof( DnsOutcome::MATCH ) )
			->authorize( $m );

		$this->assertIsArray(
			$result,
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

Create `src/Ssl/AuthorizerSupport.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\Challenge;

/**
 * The precondition core every authorizer shares, including the rule that a
 * refusal after acquisition releases the reservation.
 */
final class AuthorizerSupport {

	/**
	 * @return array{driver: SslDriver, context: SslResourceContext, lease: array{token: string, revision: int}, mapping: Mapping}|MutationRefusal
	 */
	public static function open_window(
		MappingRepository $repo,
		SslDriverRegistry $registry,
		MutationLease $lease,
		Mapping $mapping,
		MutationOperation $operation
	) {
		if ( Environment::is_blocked() ) {
			return new MutationRefusal( 'environment_unresolved', false );
		}

		$driver = null === $mapping->ssl_provider
			? $registry->default()
			: $registry->get( $mapping->ssl_provider );

		if ( null === $driver ) {
			return new MutationRefusal( 'driver_not_registered', false );
		}

		if ( Cooldown::active_for( $driver->id() ) ) {
			return new MutationRefusal( 'provider_cooldown', true );
		}

		$held = $lease->acquire( $mapping->id, $mapping->revision, $operation->kind() );

		if ( null === $held ) {
			return new MutationRefusal( 'lease_unavailable', true );
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return self::refuse( $lease, $mapping, $held, $operation->kind(), 'challenge_name_invalid', false );
		}

		$leased = $repo->by_id( $mapping->id );

		if ( null === $leased ) {
			return new MutationRefusal( 'mapping_vanished', true );
		}

		return array(
			'driver'  => $driver,
			'context' => SslResourceContext::from_mapping(
				$leased,
				Environment::installation_id(),
				$name,
				$held['token']
			),
			'lease'   => $held,
			'mapping' => $leased,
		);
	}

	/**
	 * Records the refusal and releases the reservation.
	 *
	 * @param array{token: string, revision: int}|null $held
	 */
	public static function refuse(
		MutationLease $lease,
		Mapping $mapping,
		?array $held,
		MutationKind $kind,
		string $precondition,
		bool $transient
	): MutationRefusal {
		if ( null !== $held ) {
			$lease->release_reserved( $mapping->id, $held['revision'], $held['token'], $kind );
		}

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

	public static function check_identity(
		SslDriver $driver,
		SslResourceContext $context,
		bool $require_bound_match
	): ?MutationRefusal {
		$identity = $driver->identify( $context );

		if ( $identity->transient || ! $identity->read_complete ) {
			return new MutationRefusal( 'identity_incomplete', true );
		}

		if ( $identity->has_conflicting_marker( $context->installation_id, $context->mapping_id ) ) {
			return new MutationRefusal( 'conflicting_marker', false );
		}

		if ( $require_bound_match && ! $identity->is_usable_for_mutation( $context->host ) ) {
			return new MutationRefusal( 'identity_not_confirmed', false );
		}

		return null;
	}

	public static function binding_for( Mapping $leased, array $held, MutationKind $kind ): LeaseBinding {
		return new LeaseBinding(
			$leased->id,
			$held['revision'],
			$held['token'],
			$kind,
			$leased->host,
			$leased->ssl_provider,
			$leased->ssl_ref,
			$leased->challenge,
			$leased->ssl_method,
			$leased->ssl_ownership_origin,
			$leased->ssl_owner_installation_id
		);
	}
}
```

Create `src/Ssl/DeletionAuthorizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\FreshProof;

final class DeletionAuthorizer {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly SslDriverRegistry $registry,
		private readonly FreshProof $proof,
		private readonly MutationLease $lease,
		private readonly Clock $clock
	) {}

	/**
	 * @return array{auth: MutationAuthorization, context: SslResourceContext, driver: \PostDomain\Contracts\SslDriver}|MutationRefusal
	 */
	public function authorize( Mapping $mapping ) {
		$window = AuthorizerSupport::open_window(
			$this->repo,
			$this->registry,
			$this->lease,
			$mapping,
			MutationOperation::REMOVE
		);

		if ( $window instanceof MutationRefusal ) {
			return $window;
		}

		$driver  = $window['driver'];
		$context = $window['context'];
		$held    = $window['lease'];
		$leased  = $window['mapping'];

		$identity_refusal = AuthorizerSupport::check_identity( $driver, $context, true );

		if ( null !== $identity_refusal ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::REMOVE,
				$identity_refusal->precondition, $identity_refusal->transient
			);
		}

		if ( ! $context->has_ownership_authority() ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::REMOVE, 'no_ownership_authority', false
			);
		}

		$outcome = $this->proof->prove( $leased );

		if ( DnsOutcome::TRANSIENT === $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::REMOVE, 'fresh_proof_transient', true
			);
		}

		if ( DnsOutcome::MATCH !== $outcome ) {
			return AuthorizerSupport::refuse(
				$this->lease, $leased, $held, MutationKind::REMOVE, 'fresh_proof_failed', false
			);
		}

		$ttl = TimingPolicy::authorization_ttl( TimingPolicy::lease_ttl() );

		return array(
			'driver'  => $driver,
			'context' => $context,
			'auth'    => new MutationAuthorization(
				MutationOperation::REMOVE,
				AuthorizerSupport::binding_for( $leased, $held, MutationKind::REMOVE ),
				false,
				$this->clock->now()->modify( "+{$ttl} seconds" )
			),
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DeletionAuthorizerTest`
Expected: PASS — 14 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Ssl/SslDriverRegistry.php src/Ssl/Cooldown.php src/Ssl/AuthorizerSupport.php src/Ssl/DeletionAuthorizer.php tests/integration/Ssl/DeletionAuthorizerTest.php
git commit -m "Require every precondition before a deletion, and release on refusal

Cached verification is explicitly insufficient. Each refusal after acquisition
releases the reservation, so a rejected attempt never leaves the row leased and
waiting for a recovery pass that has nothing to recover."
```

---

## Gate for Plan 07

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `MutationGateTest` proves the token scan finds no mutating driver call
outside `MutationGate`, and that a failed consumption yields zero provider calls;
`ExecutionPermitTest` proves a permit cannot be issued outside the gate;
`MutationLeaseTest` proves acquisition fails against a lease in all three phases
expired and unexpired, and that each of the nine bound values invalidates
consumption when changed; `LeaseRecoveryTest` proves the fence precedes the read
and that recovery reaches a conclusive token-owned result; `DeletionAuthorizerTest`
proves every precondition individually and that each refusal releases the
reservation.
