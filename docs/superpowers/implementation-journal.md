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
