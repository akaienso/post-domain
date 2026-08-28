# post-domain — implementation journal

**Starting commit:** `89fd1f4` (approved specification and plan suite)
**Branch:** `implementation/initial-build`

The specification is authoritative. Where a planned example conflicts with it, the
specification wins and the deviation is recorded here.

## Environment

| Tool | Version |
|---|---|
| PHP | 8.5.9 (cli) |
| Composer | 2.10.2 |
| Node | v26.7.0 |
| npm | 11.19.0 |
| Docker | 29.7.2 |

PHP extensions present: curl, dom, intl, json, mbstring, mysqli, pdo_mysql,
pdo_sqlite, sqlite3, tokenizer, xml, zip.

## Task log

| Plan | Task | Status | Commit | Notes |
|---|---|---|---|---|
| 01 | 1 | completed | e370bff | `composer.json`, `phpunit.xml.dist`, `src/Support/Environment.php` | `phpunit --filter EnvironmentTest` → 5 tests OK | `phpunit --testsuite unit` → OK | — | — |
| 01 | 2 | completed | 5a1ab84 | `post-domain.php`, `src/Support/Activation.php` | `--filter ActivationTest` → 4 OK | unit → 9 OK | — | — |
| 01 | 3 | in progress | — | `.wp-env.json`, `package.json`, `phpunit-integration.xml.dist`, `tests/bootstrap-integration.php` | — | — | Integration harness created; `wp-env start` running. `composer test:integration` deferred until the container is up. | — |
| 01 | 4 | completed | (see 4–8) | `src/Support/Authority.php`, `AuthorityParser.php` | included in unit run | unit → 68 OK | — | — |
| 01 | 5 | completed | (see 4–8) | `src/Support/InfrastructureAllowlist.php` | included | unit → 68 OK | — | — |
| 01 | 6 | completed | (see 4–8) | `src/Support/IdnaNormalizer.php`, `tests/unit/fixtures/uts46.txt` | included | unit → 68 OK | **Deviation 1** and **Deviation 2**, below | — |
| 01 | 7 | completed | (see 4–8) | `src/Support/HostNormalizer.php` | included | unit → 68 OK | — | — |
| 01 | 8 | completed | (see 4–8) | `src/Support/TrustedProxy.php` | included | unit → 68 OK | — | — |

## Deviations

**Deviation 1 — `IdnaNormalizerTest::test_the_global_idn_functions_are_never_called()` was self-contradictory.**
The planned test asserted the source does *not* contain `idn_to_ascii(` while also
asserting it *does* contain `Idn::idn_to_ascii` — the second string contains the
first, so the pair could never both hold. The invariant it means to express is
that no *unqualified* global call exists (spec §3.4: one bundled UTS-46
implementation, called through `Symfony\Polyfill\Intl\Idn\Idn` on every host).
Implemented as a negative-lookbehind match for a call not preceded by `Idn::`.

**Deviation 2 — UTS-46 fixture path.**
Plan 01's file map and Task 6 both place the fixture at
`tests/unit/fixtures/uts46.txt`, but the prescribed test loaded
`__DIR__ . '/../../fixtures/uts46.txt'`, which resolves to `tests/fixtures/`.
Corrected the test to `__DIR__ . '/../fixtures/uts46.txt'`, keeping the file where
the plan's file map says it lives and matching Plan 09's `tests/unit/fixtures/`
convention.

**Deviation 3 — `BuildConfigTest` matched scoper config by exact whitespace.**
The planned assertion looked for `'prefix' => 'PostDomain\\Vendor'` with a single
space, but WPCS aligns double arrows in multi-line arrays, so the file legitimately
has more. Replaced with a whitespace-tolerant regex; the invariant is the prefix
value, not its column.

**Deviation 4 — `phpcs.xml.dist` needed PSR-4 and fixture exclusions.**
`WordPress-Extra` enforces hyphenated-lowercase, `class-`-prefixed filenames, which
cannot coexist with the PSR-4 autoloading the plans specify in `composer.json`.
Excluded `WordPress.Files.FileName`. Also scoped
`WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` out of
`tests/` and `bin/`, which read local fixture files — `wp_remote_get()` is for
remote URLs and is not even loaded in the unit suite.

**Deviation 5 — PHPStan needed an explicit memory limit.**
`phpstan analyse` crashed its parallel worker at the default 128M. The `analyse`
script is now `phpstan analyse --memory-limit=1G`.

| Plan | Task | Status | Commit | Notes |
|---|---|---|---|---|
| 01 | 9 | completed | (this commit) | `scoper.inc.php`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.github/workflows/ci.yml`; `composer lint` → clean, `composer analyse` → `[OK] No errors`, unit → 73 OK. Deviations 3–5. |
| 01 | 3 | completed | (this commit) | `.wp-env.json`, `package.json`, `phpunit-integration.xml.dist`, `tests/bootstrap-integration.php`, `bin/integration-env.sh`, `tests/integration/ActivationTest.php` | — | `composer test:integration` → **OK (4 tests, 5 assertions)** against real WordPress 7.1 | **Deviation 6** | — |
| 01 | 10 | pending | — | plan-example checker | — | — | Deferred to the end of the session: it validates the planning documents, not the plugin, and nothing depends on it. | — |

**Deviation 6 — the integration harness runs natively, not through wp-env's container.**
`npx wp-env start` fails building its `tests-cli` image in this environment:
`composer global require --dev phpunit/phpunit:"..."` exits 100. `.wp-env.json`,
`package.json`, and a `test:integration:wp-env` script are kept exactly as Plan 01
prescribes, and CI still uses them. Locally, `bin/integration-env.sh` brings up an
isolated `mysql:8.4` container on port 33306 and a WordPress checkout plus the
official WordPress test suite under `tmp/` (gitignored), and `composer
test:integration` runs the same `phpunit-integration.xml.dist` against them.

Two consequences worth stating plainly. The harness runs **WordPress 7.1 on PHP
8.5**, not the pinned 6.4/8.1 — 6.4 predates PHP 8.5 and emits fatal-level
deprecations under it. 6.4 remains the declared *floor* and `Environment::MIN_WP`
still enforces it; what integration proves here is that the plugin works on a
current WordPress, not that it works on the oldest supported one. CI, which uses
the wp-env config unchanged, is what covers 6.4/8.1.

The container is disposable and isolated: a named `pd-mysql` container on a
non-default port with its own volume. The developer's local Homebrew MySQL was
**not** touched — its data directory is from an older major version and
initialising it would have destroyed the user's data.
| 02 | 1 | completed | (next commit) | `src/Mapping/{VerificationState,ActivationState,SslState,OwnershipOrigin}.php`, `src/Ssl/{MutationKind,MutationPhase}.php` | unit → 82 OK | unit 82 OK | — | — |
| 02 | 2 | completed | (next commit) | `src/Support/Schema.php` | `SchemaTest` → 16 OK | integration → 16 OK | **Deviation 7**, **Deviation 8** | — |
| 02 | 3 | completed | (next commit) | `src/Mapping/Mapping.php`, `src/Contracts/MappingRepository.php`, `src/Mapping/DbRepository.php` | `RepositoryReadTest` OK | integration → 16 OK | — | — |

**Deviation 7 — `SHOW TABLES` / `INFORMATION_SCHEMA` cannot see the tables under test.**
`WP_UnitTestCase` rewrites `CREATE TABLE` to `CREATE TEMPORARY TABLE` for the
duration of a test, and temporary tables appear in neither `SHOW TABLES` nor
`INFORMATION_SCHEMA.COLUMNS`. Two prescribed assertions in `SchemaTest` could
therefore never pass in the harness the plans themselves chose. Rewrote
`test_both_tables_exist` to describe each table (`SHOW COLUMNS`, asserting no
error and a non-empty result) and the `host` column check to read `SHOW FULL
COLUMNS`, which reports the same `varchar(230)` / `ascii_bin` facts.

**Deviation 8 — domains-table column count is 51, not 49.**
The expectation predates the two mutation-binding columns and the durable
`ssl_provider_environment` added during the plan corrections. Updated to 51.
| 02 | 4 | completed | (next commit) | `DbRepository::save()` + invariants, `InvalidMapping`, `StaleRevision` | `RepositoryWriteTest` → 44 OK (incl. 30 partial-binding cases) | integration → 96 OK | **Deviation 9**, **Deviation 10** | — |
| 02 | 5 | completed | (next commit) | `EventLog`, `AtomicTransition`, `TransitionOutcome`, `TransitionResult`, `tests/integration/OwnedSessionTestCase.php` | `AtomicTransitionTest` → 21 OK | integration → 96 OK | **Deviation 11**, **Deviation 12** | — |
| 02 | 6 | completed | (next commit) | `AliasResolver`, `AliasInUse`, `DbRepository::delete()` | `AliasTest` OK | integration → 96 OK | — | — |
| 02 | 7 | completed | (next commit) | `Contracts/{Clock,Scheduler,HttpClient}.php`, `Support/{SystemClock,WpCronScheduler,WpHttpClient,HttpResponse}.php`, `tests/Fixtures/FrozenClock.php` | `SystemClockTest`, `WpCronSchedulerTest` OK | unit 85 / integration 96 OK | **Deviation 13** | — |

**Deviation 9 — `DbRepository::save()` could not write NULL on the update path.**
The planned update built `"{$column} = %s"` for every column and bound the values
through `$wpdb->prepare()`, which casts a null bound to `%s` into the **empty
string**. A nullable column could therefore never be cleared, and reading the row
back blew up with `"" is not a valid backing value for enum OwnershipOrigin`.
Null values now emit a literal `NULL` in the SET clause; the column names come
from the method's own fixed list, never from a caller.

**Deviation 10 — positional `Mapping` constructor arguments in Plan 02's tests.**
Three lease fixtures passed five `null`s before the lease token, which predates
`ssl_provider_environment`. Corrected to six.

**Deviation 11 — `EventLogTest`'s "nothing else reads the events table" scan.**
It excluded only `EventLog.php`, but `Schema.php` *declares* `events_table()`.
Excluded both; the invariant is that nothing else *reads* the table.

**Deviation 12 — the WordPress test harness is always inside a transaction.**
`WP_UnitTestCase` wraps each test in a transaction *and* sets `autocommit = 0`,
under which a transaction is always implicitly open. `AtomicTransition` therefore
correctly refuses every transition inside a stock `WP_UnitTestCase`. Added
`tests/integration/OwnedSessionTestCase.php`, which commits the harness
transaction, restores `autocommit = 1`, and cleans the plugin tables by hand;
tests that exercise transition behaviour extend it. **No production code was
changed** — the refusal is the specified behaviour and is asserted directly by
`test_an_ambient_transaction_refuses_before_the_transition_runs()`.

This surfaced a genuine production consideration, recorded here as a limitation:
on a host that runs MySQL with `autocommit = 0`, `AtomicTransition` will refuse
every transition, because there is no transaction it can own. WordPress does not
set that, but a hosting environment could. See "Known limitations" in the handoff.

**Deviation 13 — two static-analysis corrections.**
`SslState::can_transition_to()`'s `match` did not handle `FAILED` as a source
state (PHPStan level 8); named it explicitly rather than adding an unreachable
default. `WpHttpClient` called `->getAll()` on the result of
`wp_remote_retrieve_headers()`, which is not always an object; guarded it.
| 02 | 8 | completed | (this commit) | `uninstall.php` | `UninstallTest` → 1 OK, 8 assertions | integration → 97 OK | — | — |
| 01 | 10 | completed | (next commit) | `bin/check-plan-examples.php`, `tests/unit/PlanExamplesTest.php`, `tests/fixtures/plan-examples/*.md` (9 fixtures) | `--filter PlanExamplesTest` → **15 OK, 26 assertions** | `composer lint:plans` → 268 examples, 49 fragments listed, 259 types, **0 errors** | **Deviation 14** | — |

**Deviation 14 — the checker demanded coverage markers from its own fixtures.**
The prescribed `bin/check-plan-examples.php` excluded `checker fixture` blocks from
linting but not from the uncovered-critical-fragment rule, so its own deliberately
broken examples failed the run. Skipped fixtures in that loop too; the rule's
purpose is real fragments, and the fixtures are still listed as skipped.
| 09 | 1 | completed | 9526d51 | `references/cloudflare-api-schema.2026-08-27.json` (16+21), `references/cloudflare-schema-provenance.json`, `references/cloudflare-status-policy.php`, `references/cloudflare-status-map.php`, `bin/extract-cloudflare-status-schema.sh`, `bin/generate-cloudflare-status-map.php`, `tests/unit/Ssl/StatusMapGeneratorTest.php`, `tests/unit/fixtures/cloudflare-schema-extra-value.json` | `--filter StatusMapGeneratorTest` → **11 OK, 92 assertions** | `composer generate:status-map && git diff --exit-code` → clean (reproducible) | **Deviation 15** | Done ahead of Plan 09's other tasks because it is offline and independent. |

**Deviation 15 — the digest-mismatch test did not perturb the file.**
It rewrote the snapshot as `rtrim($saved) . "\n"`, which reproduces a file already
ending in exactly one newline byte for byte, so the digest still matched and the
expected failure never occurred. Changed to append a newline.

Note on the snapshot: retrieved live from
`https://raw.githubusercontent.com/cloudflare/api-schemas/main/openapi.json`
(`info.version` 4.0.0) — the one network fetch the task authorises. It yielded
exactly the 16 hostname and 21 SSL values the plan pinned. No Cloudflare
credentials were used or requested; nothing was sent to Cloudflare.
| 09 | 2 | completed | (next commit) | `src/Ssl/CloudflareStatusMap.php` | `--filter CloudflareStatusMapTest` OK | unit OK | — | — |
| 09 | 3 | completed | (next commit) | `src/Support/PublicSuffix.php`, `src/Ssl/ApexRouting.php`, `src/Ssl/ApexCapability.php`, `references/public_suffix_list.dat` (committed, 16,424 lines) | `--filter ApexCapabilityTest` → 22 OK with the status-map tests | unit OK | — | Apex is decided against the registrable domain via the committed PSL, never a label count — `example.co.uk` proves it. |
| 11 | 4 | completed | (next commit) | `README.md` (24 sections, spec §19), `tests/unit/ReadmeTest.php` | `--filter ReadmeTest` → **28 OK, 28 assertions** | unit OK | — | Written ahead of Plan 11's other tasks because it depends on the specification, not on code. |

## Parallel tracks

Plans 01, 02, 09 (Tasks 1–3) and 11 (Task 4) were implemented in the primary
session. Plans 03–05 and Plans 06–07 were dispatched to two subagents working
concurrently on the same branch, with disjoint file ownership:

- **Track A** — Plans 03, 04, 05. Owns `src/Plugin.php`, `src/Routing/*`,
  `src/Url/*`, `src/Http/*`.
- **Track B** — Plans 06, 07. Owns `src/Verification/*`, `src/Ssl/*` (except the
  Plan 09 files above). Forbidden from touching `src/Plugin.php`; its cron wiring
  goes into `CronWiring` classes that the primary session hooks into
  `Plugin::boot()` during integration.

Both were told the harness facts already discovered (temporary tables,
`autocommit = 0` and `OwnedSessionTestCase`, the `wpdb::prepare()` null cast) so
they would not rediscover them.
| — | integration | completed | (this commit) | `src/Plugin.php` + `tests/integration/CronWiringTest.php` | `--filter CronWiringTest` → 1 OK | integration → 427 OK | **Deviation 16** | — |

**Deviation 16 — subsystem cron wiring lives in `CronWiring` classes.**
Plans 06 and 08 place their cron registrations and sweep methods inside
`Plugin::boot()`. Two agents were writing those plans concurrently and
`src/Plugin.php` is a single file, so each subsystem's wiring went into its own
`CronWiring` class (`PostDomain\Verification\CronWiring`, and the SSL equivalent)
and `Plugin::boot()` calls `::register()`. `CronWiringTest` asserts the hooks
still land, which is the only thing the decomposition could have broken.

## 2026-08-28 — Plans 08 and 09 complete

Both plans are green and committed (`2e1b04e`, `5388ea6`). Unit 316, integration
570, phpcs clean, PHPStan level 8 clean.

### `Schema::probe_engine()` gained a fallback — a production fix, not a test fix

Three `DeletionServiceTest` COMMIT_UNCERTAIN cases reported `removed` where the
specification requires `deferred`. The tests were right and the diagnosis led
somewhere real: `probe_engine()` asked only `INFORMATION_SCHEMA.TABLES`, which
lists neither temporary tables nor, on a restricted host, tables the connecting
user lacks privileges to see. It answered `unknown`, `AtomicTransition` took the
non-transactional path, and the deliberately-broken COMMIT was never reached.

The harness exposed it, but the defect is production's: on a shared host whose
grants hide INFORMATION_SCHEMA, event atomicity would have been silently dropped
on a database that fully supports it. `probe_engine()` now falls back to
`SHOW CREATE TABLE`, which sees both. `OwnedSessionTestCase` no longer needs to
force `pd_schema_engine`, so the harness is smaller as a result.

### Deviations recorded this session

17. `CloudflareRegistrationTest::test_a_provisioned_mapping_records_the_zone_it_lives_in`
    asserted `$refusal->driver_id === 'cf-zone:zone-1'` — an environment id in the
    driver-id field. That is exactly the conflation correction 9 exists to
    prevent. The test now asserts `driver_id`, `expected_environment` and
    `configured_environment` separately.
18. Plan 08 Task 6's `SweepTest` calls `new Plugin()`. `Plugin` is a singleton
    with a private constructor (Plan 03 Task 1). Changed to `Plugin::instance()`.
19. `ReconcilerTest` and `SweepTest` extend `OwnedSessionTestCase`, not
    `WP_UnitTestCase`: both assert committed transitions, which the WP harness's
    ambient transaction correctly refuses. Same rationale as deviation 12.
20. `DriverRecoveryResolverTest`'s mapping fixture bound `ssl_provider` and
    `ssl_ref` without `ssl_provider_environment`, which the durable-binding
    invariant now rejects. Made all five move together. Its host and challenge
    also vary per call, both being unique columns.
21. Stale `@return array{... lease: array{token: string, revision: int} ...}`
    docblocks on four authorizers described the lease before it became
    `LeaseOwner`. Corrected; that also resolved every downstream PHPStan error
    about accessing `$driver` on `array<string, int|string>`.
22. `CloudflareSaasDriver::payload()` was documented `array<string, mixed>|null`
    but Cloudflare returns a list for a query and an object for a single read.
    Widened to `array<array-key, mixed>|null`.
23. Unused constructor dependencies removed: `Clock` from `CreateService`,
    `AdoptionService` and `MethodChangeService`; `MappingRepository` from
    `DeletionService`. No caller outside `for_tests()` existed.
24. `phpcs.xml.dist` now scopes `file_put_contents` and `shell_exec` out of
    `tests/` and `bin/` alongside the existing `file_get_contents` exclusion.
    Developer tools run under PHP directly; `WP_Filesystem` is not loaded there.
    Neither exclusion reaches `src/`.

No `Ssl\CronWiring` was needed: `Verification\Schedule` already schedules
`pd_ssl_sweep` at 900s, so `Plugin::boot()` only adds the action.

## 2026-08-28 (later) — Plan 11 Task 5, the acceptance suite

Green: 4 tests, 11 assertions. A domain goes added → pending → verified →
activated → serving → deactivated, and the subtree resolves with links carrying
the mapped host, with no subsystem stubbed.

25. `AcceptanceTest` extends `OwnedSessionTestCase`. Its first test drives a real
    `Verifier::verify()`, which is a committed transition. Same rationale as
    deviations 12 and 19.
26. The plan's `AcceptanceTest` sets a serving context but no host context, so
    `Plugin::resolve_request()` returned early — it runs only for a routed
    request on a mapped host (spec §5.4). Added the `HostContext` the resolution
    step actually requires. The plan example was incomplete, not the production
    guard.
27. The same test asserted `https://acceptance.test/events/` without setting a
    permalink structure. Under the plain-permalink default `user_trailingslashit()`
    has no trailing-slash preference to reflect. Added
    `set_permalink_structure( '/%postname%/' )`, matching `RenderedOutputTest`.

Structural verification re-run at this point, all clean:
- `DriverFactory::for_mapping` has exactly one caller in `src/`: `src/Ssl/BoundResource.php:33`.
- No mutating driver call (`create`, `adopt`, `change_validation_method`, `remove`)
  exists outside `MutationGate.php` and `NullDriver.php`.
- Secrets scan over `src/`, `references/` and the root: no matches.
- `composer generate:status-map` followed by `git diff --exit-code` on the
  generated map: reproducible.
- `composer lint:plans`: exit 0.
