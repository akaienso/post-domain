# post-domain 02 — Data model and repository Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two tables, every row invariant enforced at one write path, compare-and-swap
writes, the three independent state machines, alias rules, and an uninstall that
leaves posts untouched.

**Architecture:** `Schema` owns DDL and the schema-version option. `DbRepository`
is the only code that touches `pd_domains`, so PHP enforces the invariants that
`CHECK` constraints cannot express portably across MySQL 5.7, 8, and MariaDB.
Ownership provenance is first-class column state; the event log is written but
never read by a decision.

**Tech Stack:** As Plan 01, plus `dbDelta()` and `$wpdb`.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit every constraint from Plan 01, and add:

- **UTC everywhere.** Every `DATETIME` is written with `gmdate( 'Y-m-d H:i:s' )`.
  `current_time()` is never called (spec §12.4).
- **`DbRepository::save()` is the only write path to `pd_domains`** (spec §12.1).
- **Events are audit-only.** No authorization, routing, or state transition ever
  reads `pd_domain_events` (spec §12.3).
- **Ownership authority is `ssl_ownership_origin IS NOT NULL` AND
  `ssl_owner_installation_id === pd_installation_id`.** There is no boolean
  duplicating it (spec §12.2).
- **`host` is `VARCHAR(230)`** — `253 − 22 − 1`, the TXT-name constraint made
  structural (spec §12.1).
- `host` and `challenge` are `CHARACTER SET ascii COLLATE ascii_bin` (spec §12.1).

---

## File map

| File | Responsibility |
|---|---|
| `src/Support/Schema.php` | DDL, schema version, engine probe, install and upgrade |
| `src/Mapping/VerificationState.php` | Enum plus its legal transitions |
| `src/Mapping/ActivationState.php` | Enum plus its legal transitions |
| `src/Mapping/SslState.php` | Enum plus its legal transitions |
| `src/Mapping/OwnershipOrigin.php` | `created` / `adopted` |
| `src/Ssl/MutationKind.php` | `create` / `adopt` / `method` / `remove` |
| `src/Ssl/MutationPhase.php` | `reserved` / `in_flight` / `recovering` |
| `src/Mapping/Mapping.php` | Readonly row value object |
| `src/Contracts/MappingRepository.php` | The repository interface everything else depends on |
| `src/Mapping/DbRepository.php` | The single write path; CAS; invariant enforcement |
| `src/Mapping/AliasResolver.php` | Alias rules and canonical-host derivation |
| `src/Mapping/EventLog.php` | Append-only audit writer |
| `src/Contracts/Clock.php` | `now(): DateTimeImmutable` |
| `src/Contracts/Scheduler.php` | Cron scheduling, injected for testability |
| `src/Contracts/HttpClient.php` | Outbound HTTP, injected for testability |
| `uninstall.php` | Drops both tables and the plugin's own options only |
| `tests/integration/Mapping/*Test.php` | Repository behaviour against real MySQL |
| `tests/unit/Mapping/*Test.php` | Enums and transition tables |

---

### Task 1: State enums and their transition tables

**Files:**
- Create: `src/Mapping/VerificationState.php`, `src/Mapping/ActivationState.php`, `src/Mapping/SslState.php`, `src/Mapping/OwnershipOrigin.php`, `src/Ssl/MutationKind.php`, `src/Ssl/MutationPhase.php`
- Test: `tests/unit/Mapping/StateTransitionTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: six backed string enums, each with `::from( string )` from PHP, plus
  `VerificationState::can_transition_to( self $to ): bool`,
  `ActivationState::can_transition_to( self $to ): bool`,
  `SslState::can_transition_to( self $to ): bool`.

The three states are independent. Only verification and activation gate serving,
and only by AND (spec §12.7).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Mapping/StateTransitionTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationPhase;

final class StateTransitionTest extends TestCase {

	public function test_verification_values(): void {
		$this->assertSame(
			array( 'unverified', 'pending', 'verified', 'failed' ),
			array_map( static fn( VerificationState $s ): string => $s->value, VerificationState::cases() )
		);
	}

	public function test_verification_legal_transitions(): void {
		$this->assertTrue( VerificationState::UNVERIFIED->can_transition_to( VerificationState::PENDING ) );
		$this->assertTrue( VerificationState::PENDING->can_transition_to( VerificationState::VERIFIED ) );
		$this->assertTrue( VerificationState::PENDING->can_transition_to( VerificationState::FAILED ) );
		$this->assertTrue( VerificationState::VERIFIED->can_transition_to( VerificationState::FAILED ) );
		$this->assertTrue( VerificationState::FAILED->can_transition_to( VerificationState::PENDING ) );
	}

	public function test_rotation_can_reach_unverified_from_anywhere(): void {
		foreach ( VerificationState::cases() as $state ) {
			$this->assertTrue(
				$state->can_transition_to( VerificationState::UNVERIFIED ),
				"rotation must reset {$state->value} to unverified"
			);
		}
	}

	public function test_verification_illegal_transitions(): void {
		$this->assertFalse( VerificationState::UNVERIFIED->can_transition_to( VerificationState::VERIFIED ) );
		$this->assertFalse( VerificationState::FAILED->can_transition_to( VerificationState::VERIFIED ) );
	}

	public function test_activation_toggles_both_ways(): void {
		$this->assertTrue( ActivationState::INACTIVE->can_transition_to( ActivationState::ACTIVE ) );
		$this->assertTrue( ActivationState::ACTIVE->can_transition_to( ActivationState::INACTIVE ) );
	}

	public function test_ssl_states_include_pending_removal(): void {
		$this->assertSame(
			array( 'none', 'requested', 'pending_validation', 'active', 'failed', 'pending_removal', 'revoked' ),
			array_map( static fn( SslState $s ): string => $s->value, SslState::cases() )
		);
	}

	public function test_ssl_legal_transitions(): void {
		$this->assertTrue( SslState::NONE->can_transition_to( SslState::REQUESTED ) );
		$this->assertTrue( SslState::NONE->can_transition_to( SslState::ACTIVE ) );
		$this->assertTrue( SslState::REQUESTED->can_transition_to( SslState::PENDING_VALIDATION ) );
		$this->assertTrue( SslState::PENDING_VALIDATION->can_transition_to( SslState::ACTIVE ) );
		$this->assertTrue( SslState::ACTIVE->can_transition_to( SslState::PENDING_REMOVAL ) );
		$this->assertTrue( SslState::PENDING_REMOVAL->can_transition_to( SslState::REVOKED ) );
		$this->assertTrue( SslState::REVOKED->can_transition_to( SslState::REQUESTED ) );
	}

	public function test_revoked_is_only_reachable_from_pending_removal(): void {
		foreach ( SslState::cases() as $state ) {
			if ( SslState::PENDING_REMOVAL === $state || SslState::REVOKED === $state ) {
				continue;
			}

			$this->assertFalse(
				$state->can_transition_to( SslState::REVOKED ),
				"{$state->value} must not reach revoked directly"
			);
		}
	}

	public function test_supporting_enum_values(): void {
		$this->assertSame( array( 'created', 'adopted' ), array_map(
			static fn( OwnershipOrigin $o ): string => $o->value,
			OwnershipOrigin::cases()
		) );
		$this->assertSame( array( 'create', 'adopt', 'method', 'remove' ), array_map(
			static fn( MutationKind $k ): string => $k->value,
			MutationKind::cases()
		) );
		$this->assertSame( array( 'reserved', 'in_flight', 'recovering' ), array_map(
			static fn( MutationPhase $p ): string => $p->value,
			MutationPhase::cases()
		) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter StateTransitionTest`
Expected: FAIL — `Error: Enum "PostDomain\Mapping\VerificationState" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Mapping/VerificationState.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum VerificationState: string {
	case UNVERIFIED = 'unverified';
	case PENDING    = 'pending';
	case VERIFIED   = 'verified';
	case FAILED     = 'failed';

	public function can_transition_to( self $to ): bool {
		// Rotating the challenge resets any state to unverified.
		if ( self::UNVERIFIED === $to ) {
			return true;
		}

		return match ( $this ) {
			self::UNVERIFIED => self::PENDING === $to,
			self::PENDING    => in_array( $to, array( self::PENDING, self::VERIFIED, self::FAILED ), true ),
			self::VERIFIED   => in_array( $to, array( self::VERIFIED, self::FAILED ), true ),
			self::FAILED     => self::PENDING === $to,
		};
	}
}
```

Create `src/Mapping/ActivationState.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum ActivationState: string {
	case INACTIVE = 'inactive';
	case ACTIVE   = 'active';

	public function can_transition_to( self $to ): bool {
		unset( $to );

		return true;
	}
}
```

Create `src/Mapping/SslState.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum SslState: string {
	case NONE               = 'none';
	case REQUESTED          = 'requested';
	case PENDING_VALIDATION = 'pending_validation';
	case ACTIVE             = 'active';
	case FAILED             = 'failed';
	case PENDING_REMOVAL    = 'pending_removal';
	case REVOKED            = 'revoked';

	public function can_transition_to( self $to ): bool {
		if ( self::REVOKED === $to ) {
			return self::PENDING_REMOVAL === $this || self::REVOKED === $this;
		}

		if ( self::PENDING_REMOVAL === $to ) {
			return in_array(
				$this,
				array( self::REQUESTED, self::PENDING_VALIDATION, self::ACTIVE, self::FAILED, self::PENDING_REMOVAL ),
				true
			);
		}

		if ( self::FAILED === $to ) {
			return true;
		}

		return match ( $this ) {
			self::NONE               => in_array( $to, array( self::NONE, self::REQUESTED, self::ACTIVE ), true ),
			self::REQUESTED          => in_array( $to, array( self::REQUESTED, self::PENDING_VALIDATION, self::ACTIVE ), true ),
			self::PENDING_VALIDATION => in_array( $to, array( self::PENDING_VALIDATION, self::ACTIVE ), true ),
			self::ACTIVE             => self::ACTIVE === $to,
			self::PENDING_REMOVAL    => false,
			self::REVOKED            => self::REQUESTED === $to,
		};
	}
}
```

Create `src/Mapping/OwnershipOrigin.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum OwnershipOrigin: string {
	case CREATED = 'created';
	case ADOPTED = 'adopted';
}
```

Create `src/Ssl/MutationKind.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MutationKind: string {
	case CREATE = 'create';
	case ADOPT  = 'adopt';
	case METHOD = 'method';
	case REMOVE = 'remove';
}
```

Create `src/Ssl/MutationPhase.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MutationPhase: string {
	case RESERVED   = 'reserved';
	case IN_FLIGHT  = 'in_flight';
	case RECOVERING = 'recovering';
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter StateTransitionTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Mapping/VerificationState.php src/Mapping/ActivationState.php src/Mapping/SslState.php src/Mapping/OwnershipOrigin.php src/Ssl/MutationKind.php src/Ssl/MutationPhase.php tests/unit/Mapping/StateTransitionTest.php
git commit -m "Model the three independent states and their legal transitions

Revoked is reachable only from pending_removal: revoked means a removal we asked
for, and a resource that simply vanished at the provider is a failure with its
own code, not a revocation."
```

---

### Task 2: Schema install and upgrade

**Files:**
- Create: `src/Support/Schema.php`
- Test: `tests/integration/Support/SchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Support\Schema::VERSION` (int), `::install(): void`,
  `::maybe_upgrade(): void`, `::domains_table(): string`, `::events_table(): string`,
  `::engine(): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Support/SchemaTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class SchemaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/** @return string[] */
	private function columns( string $table ): array {
		global $wpdb;

		/** @var string[] $names */
		$names = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB

		return $names;
	}

	public function test_both_tables_exist(): void {
		global $wpdb;

		foreach ( array( Schema::domains_table(), Schema::events_table() ) as $table ) {
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
			);
		}
	}

	public function test_the_domains_table_carries_every_specified_column(): void {
		$columns = $this->columns( Schema::domains_table() );

		foreach (
			array(
				'id', 'host', 'alias_of', 'post_id', 'revision',
				'verification_state', 'activation_state', 'ssl_state', 'integrity_error',
				'challenge', 'challenge_label', 'challenge_rotated_at', 'verified_at',
				'last_checked_at', 'last_outcome', 'hard_failure_count', 'transient_failure_count',
				'verification_deadline', 'verify_next_attempt_at', 'verify_lease_token',
				'verify_lease_expires_at', 'resolver_class',
				'ssl_provider', 'ssl_ref', 'ssl_ownership_origin', 'ssl_owner_installation_id',
				'ssl_adopted_at', 'ssl_adopted_by', 'ssl_method', 'ssl_method_requested_at',
				'ssl_marker_support', 'ssl_checked_at', 'ssl_next_attempt_at',
				'ssl_transient_count', 'ssl_provider_state', 'ssl_error',
				'ssl_mutation_token', 'ssl_mutation_kind', 'ssl_mutation_phase',
				'ssl_mutation_expires_at',
				'deletion_requested_at', 'deletion_attempts', 'deletion_next_attempt_at',
				'title', 'favicon_attachment_id', 'created_at', 'updated_at', 'created_by',
			) as $column
		) {
			$this->assertContains( $column, $columns, "missing column {$column}" );
		}
	}

	public function test_host_is_230_bytes_of_ascii_bin(): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT CHARACTER_MAXIMUM_LENGTH AS len, COLLATION_NAME AS collation
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				Schema::domains_table(),
				'host'
			),
			ARRAY_A
		);

		$this->assertSame( '230', (string) $row['len'] );
		$this->assertSame( 'ascii_bin', $row['collation'] );
	}

	public function test_host_and_challenge_are_unique(): void {
		global $wpdb;

		/** @var array<int, array<string, string>> $indexes */
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::domains_table(), ARRAY_A ); // phpcs:ignore WordPress.DB
		$unique  = array();

		foreach ( $indexes as $index ) {
			if ( '0' === (string) $index['Non_unique'] ) {
				$unique[] = $index['Key_name'];
			}
		}

		$this->assertContains( 'host', $unique );
		$this->assertContains( 'challenge', $unique );
	}

	public function test_installing_twice_is_idempotent(): void {
		Schema::install();
		Schema::install();

		$this->assertCount( 49, $this->columns( Schema::domains_table() ) );
	}

	public function test_the_schema_version_is_recorded(): void {
		$this->assertSame( Schema::VERSION, (int) get_option( 'pd_schema_version' ) );
	}

	public function test_the_engine_is_recorded(): void {
		$this->assertNotSame( '', Schema::engine() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter SchemaTest`
Expected: FAIL — `Error: Class "PostDomain\Support\Schema" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/Schema.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class Schema {

	public const VERSION = 1;

	public static function domains_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pd_domains';
	}

	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pd_domain_events';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$domains = self::domains_table();
		$events  = self::events_table();

		dbDelta(
			"CREATE TABLE {$domains} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				host varchar(230) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				alias_of bigint(20) unsigned NULL,
				post_id bigint(20) unsigned NULL,
				revision int(10) unsigned NOT NULL DEFAULT 1,
				verification_state varchar(20) NOT NULL DEFAULT 'unverified',
				activation_state varchar(20) NOT NULL DEFAULT 'inactive',
				ssl_state varchar(20) NOT NULL DEFAULT 'none',
				integrity_error varchar(60) NULL,
				challenge char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				challenge_label varchar(63) NOT NULL,
				challenge_rotated_at datetime NULL,
				verified_at datetime NULL,
				last_checked_at datetime NULL,
				last_outcome varchar(20) NULL,
				hard_failure_count smallint(5) unsigned NOT NULL DEFAULT 0,
				transient_failure_count smallint(5) unsigned NOT NULL DEFAULT 0,
				verification_deadline datetime NULL,
				verify_next_attempt_at datetime NULL,
				verify_lease_token char(32) NULL,
				verify_lease_expires_at datetime NULL,
				resolver_class varchar(191) NULL,
				ssl_provider varchar(60) NULL,
				ssl_ref varchar(191) NULL,
				ssl_ownership_origin varchar(10) NULL,
				ssl_owner_installation_id char(36) NULL,
				ssl_adopted_at datetime NULL,
				ssl_adopted_by bigint(20) unsigned NULL,
				ssl_method varchar(10) NULL,
				ssl_method_requested_at datetime NULL,
				ssl_marker_support varchar(20) NULL,
				ssl_checked_at datetime NULL,
				ssl_next_attempt_at datetime NULL,
				ssl_transient_count smallint(5) unsigned NOT NULL DEFAULT 0,
				ssl_provider_state text NULL,
				ssl_error text NULL,
				ssl_mutation_token char(32) NULL,
				ssl_mutation_kind varchar(20) NULL,
				ssl_mutation_phase varchar(20) NULL,
				ssl_mutation_expires_at datetime NULL,
				deletion_requested_at datetime NULL,
				deletion_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				deletion_next_attempt_at datetime NULL,
				title varchar(255) NULL,
				favicon_attachment_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				created_by bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY host (host),
				UNIQUE KEY challenge (challenge),
				KEY post_id (post_id),
				KEY alias_of (alias_of),
				KEY verify_due (verification_state, verify_next_attempt_at),
				KEY ssl_due (ssl_state, ssl_next_attempt_at),
				KEY deletion_due (deletion_next_attempt_at),
				KEY ssl_lease (ssl_mutation_expires_at)
			) {$charset} ENGINE=InnoDB"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				domain_id bigint(20) unsigned NOT NULL,
				host varchar(230) NOT NULL,
				type varchar(40) NOT NULL,
				from_state varchar(20) NULL,
				to_state varchar(20) NULL,
				actor varchar(60) NULL,
				detail longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY domain_created (domain_id, created_at)
			) {$charset} ENGINE=InnoDB"
		);

		update_option( 'pd_schema_version', self::VERSION, false );
		update_option( 'pd_schema_engine', self::probe_engine(), false );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'pd_schema_version', 0 ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	public static function engine(): string {
		return (string) get_option( 'pd_schema_engine', '' );
	}

	private static function probe_engine(): string {
		global $wpdb;

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				self::domains_table()
			)
		);

		return null === $engine ? 'unknown' : (string) $engine;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter SchemaTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Support/Schema.php tests/integration/Support/SchemaTest.php
git commit -m "Create the domains and events tables

host is 230 bytes because the composed TXT record name must fit 253 and the
default label costs 23. The limit is structural rather than a runtime check."
```

---

### Task 3: The mapping value object and repository reads

**Files:**
- Create: `src/Mapping/Mapping.php`, `src/Contracts/MappingRepository.php`, `src/Mapping/DbRepository.php`
- Test: `tests/integration/Mapping/RepositoryReadTest.php`

**Interfaces:**
- Consumes: `Schema` (Task 2), the enums (Task 1).
- Produces:
  - `PostDomain\Mapping\Mapping` — readonly, with `int $id`, `string $host`, `?int $alias_of`, `?int $post_id`, `int $revision`, `VerificationState $verification_state`, `ActivationState $activation_state`, `SslState $ssl_state`, `?string $integrity_error`, `string $challenge`, `string $challenge_label`, `?OwnershipOrigin $ssl_ownership_origin`, `?string $ssl_owner_installation_id`, `?string $ssl_provider`, `?string $ssl_ref`, `?string $ssl_method`, `?string $ssl_mutation_token`, `?MutationKind $ssl_mutation_kind`, `?MutationPhase $ssl_mutation_phase`, `?string $ssl_mutation_expires_at`.
  - `PostDomain\Contracts\MappingRepository` with `by_host( string $ascii_host ): ?Mapping`, `by_id( int $id ): ?Mapping`, `all( array $args = array() ): array`, `save( Mapping $m ): Mapping`, `delete( int $id ): void`.
  - `PostDomain\Mapping\DbRepository` implementing it.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Mapping/RepositoryReadTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter RepositoryReadTest`
Expected: FAIL — `Error: Class "PostDomain\Mapping\DbRepository" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Mapping/Mapping.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationPhase;

final class Mapping {

	public function __construct(
		public readonly int $id,
		public readonly string $host,
		public readonly ?int $alias_of,
		public readonly ?int $post_id,
		public readonly int $revision,
		public readonly VerificationState $verification_state,
		public readonly ActivationState $activation_state,
		public readonly SslState $ssl_state,
		public readonly ?string $integrity_error,
		public readonly string $challenge,
		public readonly string $challenge_label,
		public readonly ?OwnershipOrigin $ssl_ownership_origin = null,
		public readonly ?string $ssl_owner_installation_id = null,
		public readonly ?string $ssl_provider = null,
		public readonly ?string $ssl_ref = null,
		public readonly ?string $ssl_method = null,
		public readonly ?string $ssl_mutation_token = null,
		public readonly ?MutationKind $ssl_mutation_kind = null,
		public readonly ?MutationPhase $ssl_mutation_phase = null,
		public readonly ?string $ssl_mutation_expires_at = null
	) {}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['host'],
			null === $row['alias_of'] ? null : (int) $row['alias_of'],
			null === $row['post_id'] ? null : (int) $row['post_id'],
			(int) $row['revision'],
			VerificationState::from( (string) $row['verification_state'] ),
			ActivationState::from( (string) $row['activation_state'] ),
			SslState::from( (string) $row['ssl_state'] ),
			$row['integrity_error'],
			(string) $row['challenge'],
			(string) $row['challenge_label'],
			null === $row['ssl_ownership_origin'] ? null : OwnershipOrigin::from( (string) $row['ssl_ownership_origin'] ),
			$row['ssl_owner_installation_id'],
			$row['ssl_provider'],
			$row['ssl_ref'],
			$row['ssl_method'],
			$row['ssl_mutation_token'],
			null === $row['ssl_mutation_kind'] ? null : MutationKind::from( (string) $row['ssl_mutation_kind'] ),
			null === $row['ssl_mutation_phase'] ? null : MutationPhase::from( (string) $row['ssl_mutation_phase'] ),
			$row['ssl_mutation_expires_at']
		);
	}

	public function is_alias(): bool {
		return null !== $this->alias_of;
	}
}
```

Create `src/Contracts/MappingRepository.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Mapping\Mapping;

interface MappingRepository {

	public function by_host( string $ascii_host ): ?Mapping;

	public function by_id( int $id ): ?Mapping;

	/**
	 * @param array<string, mixed> $args
	 * @return Mapping[]
	 */
	public function all( array $args = array() ): array;

	public function save( Mapping $m ): Mapping;

	public function delete( int $id ): void;
}
```

Create `src/Mapping/DbRepository.php` with the read half only for now:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Support\Schema;

final class DbRepository implements MappingRepository {

	public function by_host( string $ascii_host ): ?Mapping {
		global $wpdb;

		$table = Schema::domains_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE host = %s", $ascii_host ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return null === $row ? null : Mapping::from_row( $row );
	}

	public function by_id( int $id ): ?Mapping {
		global $wpdb;

		$table = Schema::domains_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return null === $row ? null : Mapping::from_row( $row );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return Mapping[]
	 */
	public function all( array $args = array() ): array {
		global $wpdb;

		unset( $args );
		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	public function save( Mapping $m ): Mapping {
		throw new \RuntimeException( 'save() lands in Task 4.' );
	}

	public function delete( int $id ): void {
		throw new \RuntimeException( 'delete() lands in Task 6.' );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter RepositoryReadTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Mapping/Mapping.php src/Contracts/MappingRepository.php src/Mapping/DbRepository.php tests/integration/Mapping/RepositoryReadTest.php
git commit -m "Read mappings through one repository

Host lookup is exact and case sensitive because normalization already lowercased
it. A case-insensitive collation would let EXAMPLE.COM match a row it was never
normalized into."
```

---

### Task 4: Row invariants and compare-and-swap writes

**Files:**
- Modify: `src/Mapping/DbRepository.php` (implement `save()`)
- Create: `src/Mapping/InvalidMapping.php`
- Test: `tests/integration/Mapping/RepositoryWriteTest.php`

**Interfaces:**
- Consumes: Task 3's `Mapping` and `DbRepository`.
- Produces: `DbRepository::save( Mapping $m ): Mapping` — inserts when `id === 0`, otherwise updates under CAS on `(id, revision)` and returns the row with its incremented revision. Throws `PostDomain\Mapping\InvalidMapping` on any invariant breach and `PostDomain\Mapping\StaleRevision` when the CAS matches nothing.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Mapping/RepositoryWriteTest.php`:

```php
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
			0, $host, null, $post_id, 1,
			VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
			null, str_repeat( 'a', 32 ), '_post-domain-challenge'
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
				$saved->id, $saved->host, null, 43, $saved->revision,
				VerificationState::PENDING, ActivationState::INACTIVE, SslState::NONE,
				null, $saved->challenge, $saved->challenge_label
			)
		);

		$this->assertSame( 2, $updated->revision );
		$this->assertSame( 43, $this->repo->by_id( $saved->id )?->post_id );
	}

	public function test_a_stale_revision_is_rejected(): void {
		$saved = $this->repo->save( $this->canonical( 'example.test' ) );
		$this->repo->save(
			new Mapping(
				$saved->id, $saved->host, null, 43, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, $saved->challenge, $saved->challenge_label
			)
		);

		$this->expectException( StaleRevision::class );
		$this->repo->save(
			new Mapping(
				$saved->id, $saved->host, null, 44, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, $saved->challenge, $saved->challenge_label
			)
		);
	}

	public function test_a_canonical_row_without_a_post_is_rejected(): void {
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0, 'example.test', null, null, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_an_alias_carrying_a_post_is_rejected(): void {
		$parent = $this->repo->save( $this->canonical( 'example.test' ) );

		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0, 'www.example.test', $parent->id, 42, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_an_alias_of_an_alias_is_rejected(): void {
		$parent = $this->repo->save( $this->canonical( 'example.test' ) );
		$alias  = $this->repo->save(
			new Mapping(
				0, 'www.example.test', $parent->id, null, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'd', 32 ), '_post-domain-challenge'
			)
		);

		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0, 'deep.example.test', $alias->id, null, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'e', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_a_partial_lease_is_rejected(): void {
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0, 'example.test', null, 42, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'f', 32 ), '_post-domain-challenge',
				null, null, null, null, null,
				str_repeat( '9', 32 ), MutationKind::CREATE, null, null
			)
		);
	}

	public function test_a_complete_lease_is_accepted(): void {
		$saved = $this->repo->save(
			new Mapping(
				0, 'example.test', null, 42, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'g', 32 ), '_post-domain-challenge',
				null, null, null, null, null,
				str_repeat( '9', 32 ), MutationKind::CREATE, MutationPhase::RESERVED,
				gmdate( 'Y-m-d H:i:s', time() + 120 )
			)
		);

		$this->assertSame( MutationPhase::RESERVED, $this->repo->by_id( $saved->id )?->ssl_mutation_phase );
	}

	public function test_partial_ownership_provenance_is_rejected(): void {
		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				0, 'example.test', null, 42, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'h', 32 ), '_post-domain-challenge',
				\PostDomain\Mapping\OwnershipOrigin::CREATED, null, 'cloudflare-saas', 'ref-1'
			)
		);
	}

	public function test_an_illegal_state_transition_is_rejected(): void {
		$saved = $this->repo->save( $this->canonical( 'example.test' ) );

		$this->expectException( InvalidMapping::class );
		$this->repo->save(
			new Mapping(
				$saved->id, $saved->host, null, 42, $saved->revision,
				VerificationState::VERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, $saved->challenge, $saved->challenge_label
			)
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter RepositoryWriteTest`
Expected: FAIL — `RuntimeException: save() lands in Task 4.`

- [ ] **Step 3: Write minimal implementation**

Create `src/Mapping/InvalidMapping.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

final class InvalidMapping extends \InvalidArgumentException {}
```

Create `src/Mapping/StaleRevision.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

final class StaleRevision extends \RuntimeException {}
```

Replace `DbRepository::save()` with:

```php
	public function save( Mapping $m ): Mapping {
		global $wpdb;

		$this->assert_valid( $m );

		$table = Schema::domains_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = array(
			'host'                      => $m->host,
			'alias_of'                  => $m->alias_of,
			'post_id'                   => $m->post_id,
			'verification_state'        => $m->verification_state->value,
			'activation_state'          => $m->activation_state->value,
			'ssl_state'                 => $m->ssl_state->value,
			'integrity_error'           => $m->integrity_error,
			'challenge'                 => $m->challenge,
			'challenge_label'           => $m->challenge_label,
			'ssl_ownership_origin'      => $m->ssl_ownership_origin?->value,
			'ssl_owner_installation_id' => $m->ssl_owner_installation_id,
			'ssl_provider'              => $m->ssl_provider,
			'ssl_ref'                   => $m->ssl_ref,
			'ssl_method'                => $m->ssl_method,
			'ssl_mutation_token'        => $m->ssl_mutation_token,
			'ssl_mutation_kind'         => $m->ssl_mutation_kind?->value,
			'ssl_mutation_phase'        => $m->ssl_mutation_phase?->value,
			'ssl_mutation_expires_at'   => $m->ssl_mutation_expires_at,
			'updated_at'                => $now,
		);

		if ( 0 === $m->id ) {
			$data['revision']   = 1;
			$data['created_at'] = $now;

			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB

			$saved = $this->by_id( (int) $wpdb->insert_id );

			if ( null === $saved ) {
				throw new \RuntimeException( 'The inserted mapping could not be read back.' );
			}

			return $saved;
		}

		$existing = $this->by_id( $m->id );

		if ( null === $existing ) {
			throw new InvalidMapping( 'Cannot update a mapping that does not exist.' );
		}

		$this->assert_transitions( $existing, $m );

		$sets   = array();
		$values = array();

		foreach ( $data as $column => $value ) {
			$sets[]   = "{$column} = %s";
			$values[] = $value;
		}

		$sql = "UPDATE {$table} SET " . implode( ', ', $sets )
			. ', revision = revision + 1 WHERE id = %d AND revision = %d';

		$values[] = $m->id;
		$values[] = $m->revision;

		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB

		if ( 1 !== $affected ) {
			throw new StaleRevision(
				sprintf( 'Mapping %d changed underneath revision %d.', $m->id, $m->revision )
			);
		}

		$saved = $this->by_id( $m->id );

		if ( null === $saved ) {
			throw new \RuntimeException( 'The updated mapping could not be read back.' );
		}

		return $saved;
	}

	private function assert_valid( Mapping $m ): void {
		if ( null === $m->alias_of && null === $m->post_id ) {
			throw new InvalidMapping( 'A canonical mapping must carry a post_id.' );
		}

		if ( null !== $m->alias_of && null !== $m->post_id ) {
			throw new InvalidMapping( 'An alias mapping must not carry a post_id.' );
		}

		if ( null !== $m->alias_of ) {
			$parent = $this->by_id( $m->alias_of );

			if ( null === $parent ) {
				throw new InvalidMapping( 'An alias must point at an existing mapping.' );
			}

			if ( $parent->is_alias() ) {
				throw new InvalidMapping( 'Aliases may not chain.' );
			}
		}

		$lease = array(
			null !== $m->ssl_mutation_token,
			null !== $m->ssl_mutation_kind,
			null !== $m->ssl_mutation_phase,
			null !== $m->ssl_mutation_expires_at,
		);

		if ( count( array_unique( $lease ) ) > 1 ) {
			throw new InvalidMapping( 'The four lease columns move together.' );
		}

		$owned = array(
			null !== $m->ssl_ownership_origin,
			null !== $m->ssl_owner_installation_id,
			null !== $m->ssl_ref,
		);

		if ( count( array_unique( $owned ) ) > 1 ) {
			throw new InvalidMapping( 'Ownership origin, owner installation, and provider ref move together.' );
		}
	}

	private function assert_transitions( Mapping $from, Mapping $to ): void {
		if ( ! $from->verification_state->can_transition_to( $to->verification_state ) ) {
			throw new InvalidMapping(
				sprintf(
					'Illegal verification transition %s -> %s.',
					$from->verification_state->value,
					$to->verification_state->value
				)
			);
		}

		if ( ! $from->ssl_state->can_transition_to( $to->ssl_state ) ) {
			throw new InvalidMapping(
				sprintf( 'Illegal SSL transition %s -> %s.', $from->ssl_state->value, $to->ssl_state->value )
			);
		}
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter RepositoryWriteTest`
Expected: PASS — 10 tests

- [ ] **Step 5: Commit**

```bash
git add src/Mapping/DbRepository.php src/Mapping/InvalidMapping.php src/Mapping/StaleRevision.php tests/integration/Mapping/RepositoryWriteTest.php
git commit -m "Enforce every row invariant at the single write path

CHECK constraints are unreliable across MySQL 5.7, 8, and MariaDB, so the
invariants live in PHP at the only code that touches the table."
```

---

### Task 5: The audit event log

**Files:**
- Create: `src/Mapping/EventLog.php`
- Test: `tests/integration/Mapping/EventLogTest.php`

**Interfaces:**
- Consumes: `Schema` (Task 2).
- Produces: `PostDomain\Mapping\EventLog::record( int $domain_id, string $host, string $type, ?string $from, ?string $to, ?string $actor, array $detail = array() ): void` and `::for_domain( int $domain_id ): array`, plus `::prune( int $retention_days ): int`.

Events are a support artifact. Nothing in authorization, routing, or state
transition reads this table, which is what makes pruning always safe (spec §12.3).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Mapping/EventLogTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Mapping;

use PostDomain\Mapping\EventLog;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class EventLogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	public function test_an_event_records_and_reads_back(): void {
		EventLog::record( 7, 'example.test', 'verification', 'pending', 'verified', 'cron', array( 'outcome' => 'match' ) );

		$events = EventLog::for_domain( 7 );

		$this->assertCount( 1, $events );
		$this->assertSame( 'verification', $events[0]['type'] );
		$this->assertSame( 'example.test', $events[0]['host'] );
		$this->assertSame( 'match', json_decode( (string) $events[0]['detail'], true )['outcome'] );
	}

	public function test_the_host_snapshot_survives_the_row_it_describes(): void {
		EventLog::record( 8, 'gone.test', 'ssl', 'pending_removal', 'revoked', 'cron' );

		$this->assertSame( 'gone.test', EventLog::for_domain( 8 )[0]['host'] );
	}

	public function test_pruning_removes_only_events_past_retention(): void {
		global $wpdb;

		EventLog::record( 9, 'example.test', 'admin', null, null, 'admin:1' );

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE ' . Schema::events_table() . ' SET created_at = %s WHERE domain_id = 9',
				gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS )
			)
		);

		EventLog::record( 10, 'fresh.test', 'admin', null, null, 'admin:1' );

		$this->assertSame( 1, EventLog::prune( 90 ) );
		$this->assertCount( 0, EventLog::for_domain( 9 ) );
		$this->assertCount( 1, EventLog::for_domain( 10 ) );
	}

	public function test_no_source_file_outside_the_event_log_reads_the_events_table(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() || 'EventLog.php' === $file->getFilename() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $source, 'events_table()' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'events are audit-only: no decision may read them'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter EventLogTest`
Expected: FAIL — `Error: Class "PostDomain\Mapping\EventLog" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Mapping/EventLog.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Support\Schema;

/**
 * Append-only support artifact. Nothing reads this to make a decision, which is
 * why pruning it is always safe.
 */
final class EventLog {

	/**
	 * @param array<string, mixed> $detail
	 */
	public static function record(
		int $domain_id,
		string $host,
		string $type,
		?string $from = null,
		?string $to = null,
		?string $actor = null,
		array $detail = array()
	): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Schema::events_table(),
			array(
				'domain_id'  => $domain_id,
				'host'       => $host,
				'type'       => $type,
				'from_state' => $from,
				'to_state'   => $to,
				'actor'      => $actor,
				'detail'     => array() === $detail ? null : (string) wp_json_encode( $detail ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	public static function for_domain( int $domain_id ): array {
		global $wpdb;

		$table = Schema::events_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE domain_id = %d ORDER BY created_at ASC", $domain_id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return $rows;
	}

	public static function prune( int $retention_days ): int {
		global $wpdb;

		$table  = Schema::events_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter EventLogTest`
Expected: PASS — 4 tests

- [ ] **Step 5: Commit**

```bash
git add src/Mapping/EventLog.php tests/integration/Mapping/EventLogTest.php
git commit -m "Record an append-only audit log that nothing reads to decide

The host snapshot exists because rows are eventually hard-deleted and the
history has to stay readable afterwards. A test enforces that no other source
file touches the table."
```

---

### Task 6: Alias resolution and delete

**Files:**
- Create: `src/Mapping/AliasResolver.php`
- Modify: `src/Mapping/DbRepository.php` (implement `delete()`)
- Test: `tests/integration/Mapping/AliasTest.php`

**Interfaces:**
- Consumes: `MappingRepository` (Task 3), `Mapping` (Task 3).
- Produces:
  - `PostDomain\Mapping\AliasResolver::__construct( MappingRepository $repo )`
  - `::canonical_for( Mapping $m ): ?Mapping` — the alias's parent, or the mapping itself.
  - `::canonical_host( Mapping $m ): string`
  - `::effective_post_id( Mapping $m ): ?int`
  - `::aliases_of( int $canonical_id ): Mapping[]`
  - `DbRepository::delete( int $id ): void` — throws `PostDomain\Mapping\AliasInUse` while aliases point at the row.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Mapping/AliasTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Mapping;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasInUse;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class AliasTest extends WP_UnitTestCase {

	private DbRepository $repo;
	private AliasResolver $aliases;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->aliases = new AliasResolver( $this->repo );
	}

	private function make( string $host, ?int $alias_of, ?int $post_id, string $challenge ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, $host, $alias_of, $post_id, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( $challenge, 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_an_alias_derives_its_target_from_the_canonical_row(): void {
		$canonical = $this->make( 'example.test', null, 42, 'a' );
		$alias     = $this->make( 'www.example.test', $canonical->id, null, 'b' );

		$this->assertSame( 42, $this->aliases->effective_post_id( $alias ) );
		$this->assertSame( 'example.test', $this->aliases->canonical_host( $alias ) );
	}

	public function test_a_canonical_row_is_its_own_canonical(): void {
		$canonical = $this->make( 'example.test', null, 42, 'c' );

		$this->assertSame( $canonical->id, $this->aliases->canonical_for( $canonical )?->id );
		$this->assertSame( 'example.test', $this->aliases->canonical_host( $canonical ) );
	}

	public function test_aliases_carry_their_own_challenge(): void {
		$canonical = $this->make( 'example.test', null, 42, 'd' );
		$alias     = $this->make( 'www.example.test', $canonical->id, null, 'e' );

		$this->assertNotSame(
			$canonical->challenge,
			$alias->challenge,
			'ownership proof is per host and cannot be inherited'
		);
	}

	public function test_aliases_of_lists_the_children(): void {
		$canonical = $this->make( 'example.test', null, 42, 'f' );
		$this->make( 'www.example.test', $canonical->id, null, 'g' );
		$this->make( 'shop.example.test', $canonical->id, null, 'h' );

		$this->assertCount( 2, $this->aliases->aliases_of( $canonical->id ) );
	}

	public function test_deleting_a_canonical_row_with_aliases_is_refused(): void {
		$canonical = $this->make( 'example.test', null, 42, 'i' );
		$this->make( 'www.example.test', $canonical->id, null, 'j' );

		$this->expectException( AliasInUse::class );
		$this->repo->delete( $canonical->id );
	}

	public function test_deleting_an_alias_then_its_canonical_succeeds(): void {
		$canonical = $this->make( 'example.test', null, 42, 'k' );
		$alias     = $this->make( 'www.example.test', $canonical->id, null, 'l' );

		$this->repo->delete( $alias->id );
		$this->repo->delete( $canonical->id );

		$this->assertNull( $this->repo->by_id( $canonical->id ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter AliasTest`
Expected: FAIL — `Error: Class "PostDomain\Mapping\AliasResolver" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Mapping/AliasInUse.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

final class AliasInUse extends \RuntimeException {}
```

Create `src/Mapping/AliasResolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Contracts\MappingRepository;

final class AliasResolver {

	public function __construct( private readonly MappingRepository $repo ) {}

	public function canonical_for( Mapping $m ): ?Mapping {
		return $m->is_alias() ? $this->repo->by_id( (int) $m->alias_of ) : $m;
	}

	public function canonical_host( Mapping $m ): string {
		return $this->canonical_for( $m )?->host ?? $m->host;
	}

	public function effective_post_id( Mapping $m ): ?int {
		return $this->canonical_for( $m )?->post_id;
	}

	/** @return Mapping[] */
	public function aliases_of( int $canonical_id ): array {
		return array_values(
			array_filter(
				$this->repo->all(),
				static fn( Mapping $m ): bool => $m->alias_of === $canonical_id
			)
		);
	}
}
```

Replace `DbRepository::delete()` with:

```php
	public function delete( int $id ): void {
		global $wpdb;

		foreach ( $this->all() as $mapping ) {
			if ( $mapping->alias_of === $id ) {
				throw new AliasInUse(
					sprintf( 'Mapping %d still has aliases pointing at it.', $id )
				);
			}
		}

		$wpdb->delete( Schema::domains_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter AliasTest`
Expected: PASS — 6 tests

- [ ] **Step 5: Commit**

```bash
git add src/Mapping/AliasResolver.php src/Mapping/AliasInUse.php src/Mapping/DbRepository.php tests/integration/Mapping/AliasTest.php
git commit -m "Resolve aliases and refuse to orphan them

An alias derives its target and content policy from the canonical row but keeps
its own challenge, because DNS ownership is proved per host and cannot be
inherited."
```

---

### Task 7: Injected clock, scheduler, and HTTP client

**Files:**
- Create: `src/Contracts/Clock.php`, `src/Contracts/Scheduler.php`, `src/Contracts/HttpClient.php`, `src/Support/SystemClock.php`, `src/Support/WpCronScheduler.php`, `src/Support/WpHttpClient.php`, `src/Support/HttpResponse.php`
- Test: `tests/unit/Support/SystemClockTest.php`, `tests/integration/Support/WpCronSchedulerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `PostDomain\Contracts\Clock::now(): \DateTimeImmutable` and `::mysql(): string` (UTC `Y-m-d H:i:s`).
  - `PostDomain\Contracts\Scheduler::schedule( string $hook, \DateTimeImmutable $at, array $args = array() ): void`, `::unschedule( string $hook, array $args = array() ): void`, `::next( string $hook, array $args = array() ): ?\DateTimeImmutable`.
  - `PostDomain\Contracts\HttpClient::request( string $method, string $url, array $opts = array() ): HttpResponse` where `HttpResponse` is readonly `int $status`, `array $headers`, `string $body`, `?string $error`.

The clock and scheduler exist so grace logic and reconciliation are testable
without `sleep()` or real cron (spec §2.2).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/SystemClockTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\SystemClock;

final class SystemClockTest extends TestCase {

	public function test_now_is_utc(): void {
		$this->assertSame( 'UTC', ( new SystemClock() )->now()->getTimezone()->getName() );
	}

	public function test_mysql_format_matches_the_stored_shape(): void {
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			( new SystemClock() )->mysql()
		);
	}

	public function test_no_source_file_calls_current_time(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( str_contains( (string) file_get_contents( $file->getPathname() ), 'current_time(' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a site-local timestamp in a scheduling column drifts by hours across DST'
		);
	}
}
```

Create `tests/integration/Support/WpCronSchedulerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Support\WpCronScheduler;
use WP_UnitTestCase;

final class WpCronSchedulerTest extends WP_UnitTestCase {

	public function test_scheduling_and_reading_back_a_single_event(): void {
		$scheduler = new WpCronScheduler();
		$at        = new \DateTimeImmutable( '+10 minutes', new \DateTimeZone( 'UTC' ) );

		$scheduler->schedule( 'pd_test_hook', $at, array( 7 ) );

		$this->assertSame(
			$at->getTimestamp(),
			$scheduler->next( 'pd_test_hook', array( 7 ) )?->getTimestamp()
		);
	}

	public function test_unscheduling_removes_the_event(): void {
		$scheduler = new WpCronScheduler();
		$scheduler->schedule( 'pd_test_hook', new \DateTimeImmutable( '+5 minutes', new \DateTimeZone( 'UTC' ) ), array( 8 ) );
		$scheduler->unschedule( 'pd_test_hook', array( 8 ) );

		$this->assertNull( $scheduler->next( 'pd_test_hook', array( 8 ) ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --testsuite unit --filter SystemClockTest`
Expected: FAIL — `Error: Class "PostDomain\Support\SystemClock" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Contracts/Clock.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

interface Clock {

	public function now(): \DateTimeImmutable;

	/** UTC, in the shape every DATETIME column stores. */
	public function mysql(): string;
}
```

Create `src/Support/SystemClock.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Contracts\Clock;

final class SystemClock implements Clock {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
```

Create `src/Contracts/Scheduler.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

interface Scheduler {

	/** @param array<int, mixed> $args */
	public function schedule( string $hook, \DateTimeImmutable $at, array $args = array() ): void;

	/** @param array<int, mixed> $args */
	public function unschedule( string $hook, array $args = array() ): void;

	/** @param array<int, mixed> $args */
	public function next( string $hook, array $args = array() ): ?\DateTimeImmutable;
}
```

Create `src/Support/WpCronScheduler.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Contracts\Scheduler;

final class WpCronScheduler implements Scheduler {

	/** @param array<int, mixed> $args */
	public function schedule( string $hook, \DateTimeImmutable $at, array $args = array() ): void {
		$this->unschedule( $hook, $args );
		wp_schedule_single_event( $at->getTimestamp(), $hook, $args );
	}

	/** @param array<int, mixed> $args */
	public function unschedule( string $hook, array $args = array() ): void {
		$timestamp = wp_next_scheduled( $hook, $args );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
			$timestamp = wp_next_scheduled( $hook, $args );
		}
	}

	/** @param array<int, mixed> $args */
	public function next( string $hook, array $args = array() ): ?\DateTimeImmutable {
		$timestamp = wp_next_scheduled( $hook, $args );

		if ( false === $timestamp ) {
			return null;
		}

		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
```

Create `src/Support/HttpResponse.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class HttpResponse {

	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
		public readonly ?string $error = null
	) {}
}
```

Create `src/Contracts/HttpClient.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Support\HttpResponse;

interface HttpClient {

	/**
	 * @param array<string, mixed> $opts
	 */
	public function request( string $method, string $url, array $opts = array() ): HttpResponse;
}
```

Create `src/Support/WpHttpClient.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Contracts\HttpClient;

final class WpHttpClient implements HttpClient {

	/**
	 * @param array<string, mixed> $opts
	 */
	public function request( string $method, string $url, array $opts = array() ): HttpResponse {
		$args = array_merge(
			array(
				'method'      => $method,
				'timeout'     => 10,
				'redirection' => 0,
			),
			$opts
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new HttpResponse( 0, array(), '', $response->get_error_message() );
		}

		/** @var array<string, string> $headers */
		$headers = wp_remote_retrieve_headers( $response )->getAll();

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers,
			(string) wp_remote_retrieve_body( $response )
		);
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter SystemClockTest && composer test:integration -- --filter WpCronSchedulerTest`
Expected: PASS — 3 unit tests, 2 integration tests

- [ ] **Step 5: Commit**

```bash
git add src/Contracts/Clock.php src/Contracts/Scheduler.php src/Contracts/HttpClient.php src/Support/SystemClock.php src/Support/WpCronScheduler.php src/Support/WpHttpClient.php src/Support/HttpResponse.php tests/unit/Support/SystemClockTest.php tests/integration/Support/WpCronSchedulerTest.php
git commit -m "Inject the clock, scheduler, and HTTP client

Grace arithmetic and reconciliation have to be testable without sleeping or
waiting for real cron, and a test asserts no source file calls current_time()."
```

---

### Task 8: Uninstall

**Files:**
- Create: `uninstall.php`
- Test: `tests/integration/UninstallTest.php`

**Interfaces:**
- Consumes: `Schema` (Task 2).
- Produces: nothing consumed by later tasks; this is the plugin's exit path.

Drops both tables and the plugin's own options only. No post, meta, or option
belonging to anything else is touched (spec §18).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/UninstallTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

	public function test_uninstall_drops_our_tables_and_options_and_nothing_else(): void {
		global $wpdb;

		Schema::install();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Survives uninstall' ) );
		update_post_meta( $post_id, 'unrelated_meta', 'keep me' );
		update_option( 'unrelated_option', 'keep me', false );
		update_option( 'pd_settings', array( 'a' => 1 ), false );
		update_option( 'pd_installation_id', 'abc', false );

		define( 'WP_UNINSTALL_PLUGIN', 'post-domain/post-domain.php' );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::domains_table() ) )
		);
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::events_table() ) )
		);
		$this->assertFalse( get_option( 'pd_settings' ) );
		$this->assertFalse( get_option( 'pd_installation_id' ) );
		$this->assertFalse( get_option( 'pd_schema_version' ) );

		$this->assertSame( 'Survives uninstall', get_post( $post_id )?->post_title );
		$this->assertSame( 'keep me', get_post_meta( $post_id, 'unrelated_meta', true ) );
		$this->assertSame( 'keep me', get_option( 'unrelated_option' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter UninstallTest`
Expected: FAIL — `failed to open stream: No such file or directory` for `uninstall.php`

- [ ] **Step 3: Write minimal implementation**

Create `uninstall.php`:

```php
<?php
/**
 * Removes the plugin's own data and nothing else.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

$pd_tables = array(
	\PostDomain\Support\Schema::domains_table(),
	\PostDomain\Support\Schema::events_table(),
);

foreach ( $pd_tables as $pd_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$pd_table}" ); // phpcs:ignore WordPress.DB
}

$pd_options = array(
	'pd_schema_version',
	'pd_schema_engine',
	'pd_settings',
	'pd_ssl_credentials',
	'pd_installation_id',
	'pd_installation_primary_host',
	'pd_environment_mismatch',
	'pd_provider_cooldowns',
);

foreach ( $pd_options as $pd_option ) {
	delete_option( $pd_option );
}

$pd_hooks = array(
	'pd_verify_pending',
	'pd_verify_established',
	'pd_ssl_sweep',
	'pd_maintenance',
);

foreach ( $pd_hooks as $pd_hook ) {
	wp_clear_scheduled_hook( $pd_hook );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter UninstallTest`
Expected: PASS — 1 test, 9 assertions

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add uninstall.php tests/integration/UninstallTest.php
git commit -m "Remove only this plugin's data on uninstall

The test seeds an unrelated post, meta, and option and asserts all three
survive, because 'we dropped our tables' is not the same claim as 'we touched
nothing else'."
```

---

## Gate for Plan 02

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `Schema::install()` runs twice with no error, every invariant test in
`RepositoryWriteTest` passes, and `UninstallTest` proves a seeded post survives.
