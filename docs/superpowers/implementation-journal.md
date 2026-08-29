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

## 2026-08-28 (later) — Plan 11 Tasks 1–3, the admin surface

Green as reported and re-run here: `AdminScreensTest` 11, `DomainDetailTest` 10,
`DiagnosticsTest` 14, `WiringTest` 2 — 49 tests, 104 assertions across
`tests/integration/Admin/`.

Wired into `Plugin::boot()` with one line, `\PostDomain\Admin\Wiring::register()`,
on the same terms as `Verification\CronWiring`.

28. `Admin\Wiring` exists so `Plugin` gains a line rather than two subsystems.
    `ProbeEndpoint::boot()` resolves its context through `Plugin::instance()`
    instead of a `use ( $plugin )` capture, since it no longer lives inside
    `boot()`'s scope. `Plugin` is a singleton, so it is the same object.
29. `ProbeEndpoint::page()` built its asset URL from `dirname( __DIR__ )`, which
    resolves to `src/` and would have 404'd on
    `…/post-domain/src/assets/probe.js`. Corrected to `dirname( __DIR__, 2 )`.
    The prescribed test only asserts the substring `probe.js`, so it would have
    passed against a broken URL either way.
30. `Diagnostics::drifted_resources()` could not satisfy its own test. With no
    Cloudflare credentials configured the refusal is `driver_not_registered`,
    which carries no environment — only `environment_changed` does — so the zone
    the test asserts on was unreachable. It now reads the environment from
    `$mapping->ssl_provider_environment`, the row's own durable binding, which is
    what spec §16.1 requires. No credential is involved.
31. `Diagnostics::ssl_driver()`'s message contradicted its own test. Reworded to
    match the test and spec §19 item 20.
32. Three `AdminScreensTest` nonce fixtures set only `$_POST['_wpnonce']`, but
    `check_admin_referer()` reads `$_REQUEST`. All three reached `wp_die()`. The
    fixtures now set `$_REQUEST` as a real POST does; the nonce check itself is
    untouched.
33. A `DomainDetail` closure parameter is typed for PHPStan level 8.
34. phpcs conformance only: one value per line in associative arrays, and the
    four `Diagnostics` queries use the `$table` +
    `phpcs:disable …InterpolatedNotPrepared` pattern already established by
    `src/Ssl/LeaseRecovery.php`. Every user value remains a placeholder.
    `ProbeEndpoint::page()` suppresses `NonEnqueuedScript`: the probe page has no
    `wp_head`.

## 2026-08-28 (later) — Plan 10, the REST management API

Green: `SerializerTest` 22, `CollectionTest` 14, `ResourceTest` 11,
`VerificationRoutesTest` 6, `SslRoutesTest` 17, `EnvironmentRoutesTest` 7 —
77 tests, 143 assertions across `tests/integration/Rest/`.

### Wiring applied by the primary session

`Plugin::boot()` gains `add_action( 'rest_api_init', … )` and
`Plugin::register_rest_routes()`, which returns early unless the host is
`HostKind::PRIMARY`. Registered, not guarded: on any other host the namespace
does not exist at all, so it is absent from dispatch and from `/wp-json/`
discovery. Covered by `Rest\RouteRegistrationTest` (4 tests), which fires
`rest_api_init` rather than calling the method — core refuses a route registered
anywhere else, and the hook is half of what is under test.

### Two real gaps the track found and could not fix from its own files

35. Plan 10 Task 2 says to make `Plugin::dns_resolver()` delegate to
    `ResolverFactory`. **There is no such method on `Plugin`.** The filter-reading
    construction lives in `Verification\CronWiring::dns_resolver()`.
    `ResolverFactory` was a byte-for-byte lift of it, leaving the same two
    filters read in two places. `CronWiring::dns_resolver()` now delegates, so
    exactly one place reads `pd_doh_endpoints` and `pd_dns_resolver` and cron and
    REST cannot end up proving ownership by different means.
36. **`pd_verify_now` had no listener.** `ManagementController::verify()`
    schedules the event so the REST request performs no DNS I/O, but nothing
    handled the hook, which made the on-demand probe of spec §15.2 a silent
    no-op. Added `CronWiring::verify_one()` and its registration, covered by
    `Verification\VerifyNowTest` (3 tests) — including that the hook has a
    listener at all, which is the assertion that would have caught this.

### Stale plan examples corrected against the specification

37. Three Plan 10 examples omitted `ssl_provider_environment` from the `Mapping`
    constructor tail, which would have shifted `ssl_ref` into the environment
    slot and produced exactly the partial binding `assert_valid()` rejects
    (`update()`, `rotate_challenge()` — which dropped the binding entirely — and
    the `SslRoutesTest` fixture).
38. `pd_method_unsupported` is **400** per spec §15.3, not the plan's 409.
39. `pd_txt_record_label` now actually runs at create and rotate per spec §13.1.
    The plan hardcoded the default, which left `Challenge::label_for()` with no
    production caller.
40. `/environment/resolve` accepts the specification's `choice` as well as the
    plan's `resolution`.
41. The `/plan` refusal field `driver` was renamed `driver_id` so the wire name
    matches its contents — the same conflation deviation 17 corrected.
42. `test_an_unestablished_removal_never_answers_204` was passing on an
    incidental 404; it now breaks `COMMIT` around the real removal.
43. A shared `$wp_rest_server` leaked routes between tests; every Rest class now
    gets a fresh one. `ResourceTest` and `SslRoutesTest` extend
    `OwnedSessionTestCase`.

### Open, and deliberately not decided here

- **`GET /environment` and the installation id.** Spec §15.2's table lists it in
  the response; the plan withholds it and has a test asserting it stays out. The
  plan's behaviour was implemented, because keeping an identifier out of a
  response is the reversible choice and deleting a test written to keep it out is
  not. This needs an operator decision, not a guess.
- **`DELETE /domains/{id}/ssl` deletes the whole mapping row** when the driver
  confirms removal, because `DeletionService::process()` is the durable
  mapping-deletion workflow of spec §14.15 step 3 and is the only service
  available. That is what the plan prescribes, but the route name promises
  something narrower. *(Superseded — fixed in the 2026-08-28 correction pass,
  finding 4.)*
- **Not covered by any Plan 10 task**, and therefore absent: collection
  pagination and filters, `title` and `favicon_attachment_id`,
  `DELETE ?force=true`, and several §15.1 resource members (`verification`
  detail, `deletion`, `branding`, `provider_state`, `validation_plan`,
  timestamps). `_compute=serving` is implemented.

## 2026-08-28 (later) — session close

All 82 tasks across the eleven plans are implemented. Final verification, every
command executed and its output observed:

- `composer lint` — exit 0, no errors, no warnings
- `composer analyse` — `[OK] No errors`, PHPStan level 8, 170 files
- `composer test` — OK, 316 tests, 576 assertions
- `composer test:integration` — OK, 695 tests, 1464 assertions, identical on a
  second consecutive run
- `composer lint:plans` — exit 0
- `composer generate:status-map` then `git diff --exit-code` — byte-identical
- `git status --short` — empty

The nine binding corrections all hold at HEAD. Nothing was deployed, pushed,
merged, or opened as a pull request; no Cloudflare token was requested or used;
no provider resource, DNS record, certificate, or client account was read or
mutated. Branch `implementation/initial-build` sits locally at `5ca784a`.

Two decisions were deliberately left open rather than guessed, both recorded in
`docs/superpowers/implementation-handoff.md`: whether `GET /environment` returns
the installation id, and whether `DELETE /domains/{id}/ssl` should delete the
whole mapping row.

IMPLEMENTATION SESSION COMPLETE

---

## 2026-08-28 — correction pass, six verified findings

Six defects found in review of `60233fd`, all confirmed against the code before
being fixed.

### 1. Durable mapping deletion never executed

`DELETE /domains/{id}` set `deletion_requested_at`, forced `activation_state =
inactive`, set `ssl_state = pending_removal` and `deletion_next_attempt_at =
now` — and nothing ever picked those rows up. `Plugin::sweep_ssl()` ran
`LeaseRecovery` and `Reconciler` and neither selects a pending-removal row. A
provider-backed mapping sat in `pending_removal` forever with its next-attempt
time permanently due.

`Ssl\DeletionSchedule` is the production selector: `pending_removal`, due, and
`ssl_mutation_token IS NULL`, so every leased row is skipped whatever its phase
or expiry. `Ssl\CronWiring` registers it on `pd_ssl_sweep` at priority 20, so
recovery at the default 10 still runs first — a fenced mutation's row is not yet
a fact anything should act on. It calls `Verification\Schedule::run_sweep()`
unmodified for the batch, the budget and the bounded continuation, and each row
goes through the unchanged authorizer → gate → permit → finalize CAS.

The retry schedule was wrong in the same place: every non-`FAILED` outcome took
`LeaseOutcome::checked()`, which leaves `deletion_next_attempt_at` untouched and
therefore permanently due — a hot loop. `RemovalWorkflow::retry_schedule()` now
gives `PENDING` a future time with no counter increment, `TRANSIENT` no
increment and the driver's `retry_after` when supplied (clamped) or bounded
backoff otherwise, and `FAILED` an increment plus bounded backoff. No branch can
leave the column at or before now.

`Ssl\DeletionSweepTest` fires the real hook: 8 tests, 48 assertions, including a
data provider asserting the *stored* next-attempt time and attempt count for all
four outcomes, and a two-sweep test proving no hot loop.

### 2. A stale DNS result could overwrite a concurrent edit

`Verifier::take_lease()` incremented the revision, but the result CAS bound only
`id`, `verify_lease_token` and `challenge`. An edit landing between acquisition
and application that did not rotate the challenge was silently overwritten by
the older DNS result.

`Verification\VerificationLease` now carries the token and the exact leased
revision, and the result CAS binds all four. The revision is computed as
`$mapping->revision + 1` rather than re-read — the winning CAS matched
`revision = $mapping->revision` and incremented it, so that is the value the row
holds, whereas a re-read would return the interloper's revision and hand the CAS
the very number that defeats it. `AtomicTransition` already ran the event
callback only after a true transition on both paths; that is now asserted rather
than assumed. Covered by a revision-only race test: state unchanged,
`last_outcome` still NULL, no event.

### 3. One DoH endpoint could produce a hard outcome

`DohResolver`'s docblock promised that a hard outcome requires two independent
endpoints to agree, but `txt()` only checked unanimity. With one endpoint, one
answer was trivially unanimous. Duplicate strings passed as two independent
resolvers, and a non-HTTPS endpoint merely cast a TRANSIENT vote instead of
disqualifying the run.

Qualification and deduplication now happen before any request is made. Fewer
than two distinct usable HTTPS endpoints returns TRANSIENT without contacting
anything. "Trivially equivalent" is documented and bounded: surrounding
whitespace, scheme and host case, an explicit `:443`, a trailing slash. A
non-default port or a query string still distinguishes two endpoints, and
userinfo is rejected rather than stripped. `ResolverFactory` passes a filtered
list through as given and deliberately does not top it back up from the
defaults — restoring a second endpoint would both ignore the filter and
manufacture the corroboration the operator removed.

13 unit cases plus 5 integration cases covering the factory, none reaching a
network.

### 4. `DELETE /domains/{id}/ssl` deleted the whole mapping

The SSL-specific route called `DeletionService::process()`, which on a confirmed
removal hard-deletes the row (§14.15 step 3) — destroying the host, the post
binding, verification state and aliases.

The two operations are now separate services sharing one `RemovalWorkflow`, so
the authorization, lease and gate machinery is identical and neither is a
shortcut. `DELETE /domains/{id}` still performs whole-mapping deletion.
`SslResourceRemoval` retains the row and, in the same single finalize CAS, nulls
all five binding columns together with the adoption fields, the error and
provider state, resets the deletion counters, and sets `ssl_state = revoked`.
`PENDING`, `TRANSIENT` and `FAILED` clear nothing — the binding survives until
the provider confirms.

Proved by a REST test asserting the mapping's host, post, verification state,
activation state, challenge and alias are all unchanged with the five columns
null, and by tests that `PENDING`/`TRANSIENT` retain the binding.

### 5. Cron never recurred, and `pd_maintenance` had no listener

`Schedule::HOOKS` declared 900/3600/900/86400, and `register_cron()` ignored
every one of them: `wp_schedule_single_event( time() + 60, $hook )`. Each hook
fired once, about a minute after `init`, and then never again.

`register_cron()` now uses WordPress recurring events with plugin-owned custom
schedules registered through `cron_schedules`. Recurring rather than
self-rescheduling on purpose: WP-Cron re-arms a recurring event *before* running
the callback, so the hook survives a callback that fatals, whereas a
self-rescheduling handler stops forever the first time it dies. An event already
at the right cadence is left alone; one at a wrong cadence — including the single
events the old code left on installed sites — is cleared and replaced.
`run_sweep()`'s bounded continuation is untouched.

`Verification\Maintenance` implements the four §13.6 duties. Pruning is the only
deletion and it touches the event log only, which is never a decision input. The
orphan-alias and dangling-target scans are diagnostics: they record events with
`auto_repaired => false` and delete nothing. `Reconciler::run()` is called, not
modified. Both scans are bounded by `pd_maintenance_scan_limit`.

Deactivation cleanup was checked and deliberately not added: §18 specifies cron
cleanup at *uninstall*, `uninstall.php` already clears all four hooks with a list
matching `Schedule::HOOKS`, and the specification gives deactivation no cleanup
behaviour anywhere.

### 6. `pd_rebase_url` results bypassed the strict validator

`UrlPolicy::validated()` was a weaker duplicate of `AbsoluteUrl::validated()`,
checking only the scheme family and the host. A filter could hand back an http
downgrade, userinfo, control characters, or an arbitrary port, and the plugin
emitted it into a page. It now goes through the same strict validator the
canonical filter uses, with HTTPS required unconditionally because a mapped host
is only ever addressed over HTTPS (§11.8), and rejects any port the contract
would not itself produce. Anything refused falls back to the original URL.
Seven cases, one per rejection reason plus an accepted one.

### The reported suite flakiness was the harness, not the code

Both tracks reported the full integration suite giving different error counts on
identical runs. It reproduced only while two agents were running PHPUnit
concurrently against the single shared `pd-mysql` container. Run serially with
nothing else touching the database, the suite gave **OK (731 tests, 1647
assertions) four consecutive times**. No production or test change was needed.
Worth knowing for CI: this suite must not run two processes against one database.

### Verification — every command executed, every result observed

- `composer lint` — exit 0
- `composer analyse` — `[OK] No errors`, PHPStan level 8
- `composer test` — OK, 329 tests, 591 assertions
- `composer test:integration` — OK, 731 tests, 1647 assertions, four identical consecutive runs
- `composer lint:plans` — exit 0
- `composer generate:status-map` then `git diff --exit-code` — byte-identical
- `git diff --check` — clean
- Focused across all six findings — OK, 72 tests, 255 assertions, plus 13 unit cases for the DoH quorum

Invariants re-checked and intact: one `DriverFactory::for_mapping()` caller, no
mutating driver call outside `MutationGate`, no secrets, no attribution.

`GET /environment`'s installation-id omission is unchanged, and
`CteSubtreeAdapter` remains disabled and unwired, both as instructed.

No unresolved blocker.

---

## 2026-08-29 — final runtime correction, three verified defects

### 1. An unfinished SSL-only removal could never be resumed

`DELETE /domains/{id}/ssl` on a `PENDING` or `TRANSIENT` provider answer wrote a
future `deletion_next_attempt_at` and left `ssl_state` alone. The only selector,
`DeletionSchedule::due()`, required `ssl_state = pending_removal` and always
dispatched `DeletionService`. So an SSL-only removal was either invisible to cron
forever, or — had it been visible — would have been finished by the wrong
workflow and hard-deleted a domain whose operator asked only for its certificate
to go.

The two removals are now distinguished by a persisted column,
`ssl_removal_scope`, with the values `mapping` and `resource` (`RemovalScope`).
It is a column rather than an inference: both removals legitimately pass through
the same `ssl_state` values, so the state cannot carry the answer, and the event
log is a record of what happened, never an input to what happens next (§12.3).
Schema `VERSION` is 2, so `maybe_upgrade()` adds the column on an existing site.

- `DeletionService::request()` claims `mapping` in the same CAS that requests the
  deletion — a CAS that only matches an unleased row, so an SSL-only removal in
  flight cannot have the scope rewritten underneath it.
- `SslResourceRemoval` claims `resource` inside its owner-pinned finalize CAS on
  every unconfirmed outcome, and clears it along with the binding on `REMOVED`.
- `DeletionSchedule::due()` selects on `ssl_removal_scope IS NOT NULL`, still
  skipping every leased row, and `Ssl\CronWiring` dispatches on the row's own
  scope to one of two services that share a single authorizer, lease and gate.
- `SslResourceRemoval` refuses outright, `409`, on a row already claimed for
  mapping deletion. Without that, a `DELETE /ssl` could quietly downgrade a
  requested deletion into a keep and the operator would never learn the domain
  stayed.

Stale scope is handled by the machinery already in place rather than by a new
check: the lease acquisition CAS pins the revision the sweep selected, so a row
that moved since selection loses the CAS and no provider call is made.

`Ssl\SslRemovalResumptionTest` — 9 tests, 37 assertions. Confirmed to be testing
the fix: pointing the selector back at `ssl_state` makes 4 of them fail.

### 2. A bounded continuation could never be scheduled

`run_sweep()` asked `wp_next_scheduled( $continuation )` about the sweep's *own*
hook. Once that hook carried a recurring event — which it has since the previous
pass — the answer was always a timestamp, so the continuation was never
scheduled and an over-budget batch simply waited for the next ordinary run, which
is the thing a continuation exists to avoid. The old test cleared the recurring
event first and therefore could not see this.

Continuations now have hooks of their own, `<hook>_continue`. Each re-fires its
sweep hook rather than calling a worker directly, so every listener runs again in
its registered order — load-bearing for `pd_ssl_sweep`, where recovery holds the
default priority and ordinary due work follows at 20.

A bug was found while testing this: registering the continuations with a closure
stacked a second listener on a second call, because `add_action` deduplicates on
callback identity and every closure is a fresh identity. One continuation would
then have fired the sweep twice. The handler is a named static method that
derives its sweep hook from `current_action()`.

The tests now leave the recurring event installed and assert the recurring event
survives, exactly one one-off continuation appears about a minute out, a second
unfinished run does not stack another, a completed batch schedules none, and the
continuation re-fires the whole hook in priority order.

### 3. Two endpoints on one resolver could corroborate each other

`DohResolver` deduplicated by complete normalized URL, so
`https://dns.google/resolve` and `https://dns.google/dns-query` counted as two
independent resolvers. Independence is now keyed by **authority** — normalized
host plus effective port — with path and query preserved in the request but not
identity-bearing. The first spelling of a repeated authority is the one that
votes; the rest are never requested. Below two distinct usable authorities the
resolver returns `TRANSIENT` without sending any request, so quorum cannot be
manufactured by observing side effects. 20 unit cases, asserting the number and
targets of requests where the requirement is about not sending them.

### Two test-isolation defects found and fixed along the way

Neither is a production defect; both were tests that passed for the wrong reason.

- `Rest\CollectionTest::test_the_namespace_is_absent_from_discovery_when_not_registered`
  failed when run alone, at `84a95cf` as well as here. Under the harness the
  site's own host *is* the primary host, so the plugin registered its routes
  legitimately and the test never established the mapped host its premise
  depends on. It now sets that host before firing `rest_api_init`. Full-suite
  ordering had been masking it.
- The two sweep test classes pin a primary host so their routes exist at all —
  `Plugin` is a singleton, so an earlier class's host context would otherwise
  decide whether the routes under test exist. Both restore a mapped host in
  `tear_down` so the pin does not leak the other way.

### Verification — every command executed, every result observed

- `composer lint` — exit 0
- `composer analyse` — `[OK] No errors`, PHPStan level 8
- `composer test` — OK, 336 tests, 602 assertions
- `composer test:integration` — OK, 743 tests, 1696 assertions, three identical consecutive runs
- `composer lint:plans` — exit 0
- `composer generate:status-map` then `git diff --exit-code` — byte-identical
- `git diff --check` — clean
- Focused: `SslRemovalResumptionTest|DeletionSweepTest|ScheduleTest` — OK, 33 tests, 182 assertions; `DohEndpointQuorumTest` — OK, 20 tests, 26 assertions
- The `Rest|Ssl|Routing` subset, which was order-sensitive at `84a95cf`, is now green

Invariants intact: one `DriverFactory::for_mapping()` caller, no mutating driver
call outside `MutationGate`, no attribution in history. `GET /environment` is
unchanged and `CteSubtreeAdapter` remains disabled and unwired.

No unresolved blocker.

---

## 2026-08-29 (later) — fail-closed correction, three data-integrity defects

### 1. An unreadable removal scope was treated as whole-mapping deletion

`Ssl\CronWiring` named `RESOURCE` explicitly and sent *everything else* to
`DeletionService`, while `DeletionSchedule::due()` selected any non-null scope.
A single corrupted byte in one column was therefore a deleted domain.

`RemovalScope::is_invalid()` now distinguishes the three states a persisted
value actually has — absent, a known case, or something this build does not
recognise. `tryFrom()` collapses the third into the first, and that collapse was
the defect.

- The dispatcher is an exhaustive `match` on the two cases plus `null`. There is
  no `default`, so a new case cannot silently inherit the deleting branch.
- `DeletionSchedule::due()` selects only the values this build can finish;
  `DeletionSchedule::undecidable()` surfaces the rest for diagnostics.
- Each unreadable due row is reported once per sweep as an `integrity` event with
  `auto_repaired => false`, and otherwise untouched. What the intent behind a
  corrupted scope was is not recoverable from the row, and guessing wrong in one
  direction deletes a domain.
- **Both services refuse the wrong scope on their own account.** A service that
  is safe only when something else routes correctly is not safe, so direct
  invocation of either with the other's scope — or with an unreadable one —
  returns `scope_conflict` and touches nothing.

`Ssl\RemovalScopeDispatchTest` — 15 tests, 50 assertions. Restoring the old
dispatcher fails all 15.

### 2. An ordinary PATCH could destroy a live provider-mutation lease

`DbRepository::save()` carried all six `ssl_mutation_*` columns in its update
data, and `ManagementController::update()` rebuilds a `Mapping` from the request
without them — so those six arrive at their null defaults. A routine PATCH with
a current ETag wrote six NULLs over a `RESERVED`, `IN_FLIGHT` or `RECOVERING`
lease, destroying the fencing token and the recovery record for an operation
that may already have been sent to a provider.

- The six columns are gone from `save()`'s data entirely. It can no longer clear
  a lease, and equally no longer mint one — the mirror-image hazard. New rows
  take the schema's null defaults.
- `ssl_mutation_token IS NULL` is now part of the update CAS rather than a check
  before it, so a lease acquired between the caller's read and the statement
  makes the write match zero rows instead of racing it.
- A zero-row update re-reads to report the actual reason: `MutationInProgress`
  when a lease holds the row, `StaleRevision` otherwise. A stale revision is the
  caller's to retry; a lease is not.
- `PATCH /domains/{id}` returns `409 pd_mutation_in_progress` for any lease,
  expired or unexpired. Expiry is not availability: the expired record is exactly
  what `LeaseRecovery` needs in order to find out what the provider did.
- Challenge rotation's existing refusal is unchanged, and ETag/revision
  behaviour for lease-free rows is unchanged.

A test-only action, `pd_test_before_repository_update`, opens the window between
the read and the CAS so the race can be exercised. Nothing in production listens
to it; it follows the existing `pd_test_after_provider_call` precedent.

`Rest\LeaseBoundaryTest` — 10 tests, 80 assertions, covering all six
phase/expiry combinations plus the post-read race. Restoring the old `save()`
and dropping the PATCH guard fails 8 of the 10.

`RepositoryWriteTest::test_a_complete_lease_is_accepted` asserted the opposite of
the new contract and is now
`test_a_caller_supplied_lease_is_never_persisted`.

### 3. Clone reset left mutation and removal state behind

`Environment::resolve_as_clone()` cleared four of the six lease columns, leaving
`ssl_mutation_driver` and `ssl_mutation_environment` — a row the repository's own
six-column invariant rejects. It also left `ssl_removal_scope`,
`deletion_requested_at`, `deletion_attempts`, `deletion_next_attempt_at` and the
provider retry and observation state of the *source* installation.

Worse than inert: clearing the token while leaving the removal columns actively
*made* a previously-shielded row selectable, because `DeletionSchedule::due()`
skips leased rows. A cloned database could hard-delete a mapping on the strength
of a deletion someone requested somewhere else.

All six lease columns now move together, the removal intent and schedule are
cleared, and the stale provider retry and observation state goes with them:
`ssl_next_attempt_at`, `ssl_transient_count`, `ssl_error`, `ssl_checked_at`,
`ssl_marker_support`, `ssl_method_requested_at` — all measured against a provider
environment the clone is no longer bound to.

`ssl_method` is deliberately retained: §14.8's clone row does not name it and
§14.12 treats the DCV method as configuration, so a clone re-requesting a
certificate should re-request under the method its operator chose.

`Ssl\CloneResetTest` — 9 tests, 54 assertions, asserting every column
individually by name, that neither `DeletionSchedule::due()` nor
`LeaseRecovery::due()` returns anything afterwards, that the SSL and maintenance
sweeps issue zero provider mutations, and that the row round-trips through
`assert_valid()`. Against the pre-fix method it produces 1 error and 4 failures.

### Verification — every command executed, every result observed

- `composer lint` — exit 0
- `composer analyse` — `[OK] No errors`, PHPStan level 8
- `composer test` — OK, 336 tests, 602 assertions
- `composer test:integration` — OK, 777 tests, 1884 assertions, three identical consecutive runs
- `composer lint:plans` — exit 0
- `composer generate:status-map` then `git diff --exit-code` — byte-identical
- `git diff --check` — clean
- Focused, all three: `RemovalScopeDispatchTest|LeaseBoundaryTest|CloneResetTest` — OK, 34 tests, 184 assertions
- The `3ed1fc3` corrections still hold: `SslRemovalResumptionTest|ScheduleTest|DeletionSweepTest` — OK, 33 tests, 182 assertions; `DohEndpointQuorumTest` — OK, 20 tests, 26 assertions

Invariants intact: one `DriverFactory::for_mapping()` caller, no mutating driver
call outside `MutationGate`, `save()` no longer writes any lease column. The
persisted removal-scope architecture, the distinct continuation hooks, the
authority-keyed DoH quorum, `GET /environment` and the disabled
`CteSubtreeAdapter` are all unchanged.

No unresolved blocker.
