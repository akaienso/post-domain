# Post Domain — Implementation Handoff

## What this is

The complete first implementation of the `post-domain` WordPress plugin, built
from the eleven plans under `docs/superpowers/plans/` against the design
specification at `docs/superpowers/specs/2026-08-27-post-domain-design.md`.

The plugin maps a domain name to a single post and **resolves** it rather than
redirecting: the address bar keeps the mapped domain, and the post's permalink
path never appears.

## Provenance

| | |
|---|---|
| Starting commit | `89fd1f4` (final reviewed plan set) |
| Branch | `implementation/initial-build` |
| Specification | `docs/superpowers/specs/2026-08-27-post-domain-design.md` |
| Journal | `docs/superpowers/implementation-journal.md` |

Where a planned example conflicted with the specification, the specification was
implemented and the deviation recorded in the journal. Deviations are numbered
there; this document does not duplicate them.

## Verification — every command below was executed and its output observed

| Command | Result |
|---|---|
| `composer lint` (`phpcs`, WordPress-Extra) | exit 0, no errors, no warnings |
| `composer analyse` (PHPStan level 8, 170 files) | `[OK] No errors` |
| `composer test` (unit) | **OK — 316 tests, 576 assertions** |
| `composer test:integration` | **OK — 695 tests, 1464 assertions** |
| `composer lint:plans` | exit 0 |
| `composer generate:status-map` then `git diff --exit-code` | byte-identical; the map regenerates reproducibly |
| Integration suite run twice in succession | identical result both times |
| `git status --short` | empty |

No test is reported here that was not run. Nothing is reported as passing on the
strength of a plan's expectation.

### Structural invariants, re-checked at HEAD

| Invariant | Check | Result |
|---|---|---|
| One gate for bound-resource access | `grep -rn "DriverFactory::for_mapping" src/` | exactly one hit: `src/Ssl/BoundResource.php:33` |
| No mutating driver call outside the gate | `grep` for `->create(`, `->adopt(`, `->change_validation_method(`, `->remove(` excluding `MutationGate.php` and `NullDriver.php` | none |
| No secret in source, references, or fixtures | pattern scan for tokens and bearer credentials | none |
| No AI or co-author attribution | `git log` scan over the whole branch | none; sole author `Rob Moore <io@rmoore.dev>` |
| Company name | scan for the jammed form | none present |

### The nine binding corrections

All nine hold at HEAD and are covered by tests that were run:

1. Every access to an existing provider resource passes through `BoundResource::driver_for()`.
2. `DriverFactory::for_mapping()` has one production caller: `BoundResource`.
3. Provider-environment drift is refused before lease acquisition and before any provider read or mutation.
4. The five binding columns are one durable binding: all null or all populated (`DbRepository::assert_valid()`, and `RepositoryWriteTest` exercises every partial subset).
5. `BoundResource` fails closed on any partial binding.
6. Clone resolution clears the complete durable binding.
7. Successful create, adoption and their recovery paths establish the complete binding in one owner-pinned CAS.
8. Drift tests prove zero reads, zero mutations, no lease acquisition and no local state change.
9. Refusal objects label driver ids and provider-environment ids accurately — `driver_id` holds a driver id; `expected_environment` and `configured_environment` hold environment ids.

## Confirmation of what was *not* done

Nothing was deployed, pushed, merged, or opened as a pull request. No Cloudflare
API token was requested, configured, or used. No live DNS record, certificate,
provider resource, or client account was read or mutated. Every piece of
Cloudflare behaviour is exercised through fixtures, mocks and local test doubles,
and the pinned schema snapshot in `references/` is a committed file. The branch
sits locally at `5ca784a` awaiting review.

## Tasks

| Plan | Task | Deliverable |
|---|---|---|
| 01 | 1 | Composer project and the environment gate |
| 01 | 2 | Plugin bootstrap in the specified order |
| 01 | 3 | wp-env harness and activation behaviour against real WordPress |
| 01 | 4 | Authority parsing |
| 01 | 5 | Infrastructure allowlist |
| 01 | 6 | IDN normalization, single implementation |
| 01 | 7 | Host normalization |
| 01 | 8 | Trusted proxies |
| 01 | 9 | Prefixed build, static analysis, and CI |
| 01 | 10 | Mechanically validating the plans' own PHP examples |
| 02 | 1 | State enums and their transition tables |
| 02 | 2 | Schema install and upgrade |
| 02 | 3 | The mapping value object and repository reads |
| 02 | 4 | Row invariants and compare-and-swap writes |
| 02 | 5 | The audit event log and atomic state transitions |
| 02 | 6 | Alias resolution and delete |
| 02 | 7 | Injected clock, scheduler, and HTTP client |
| 02 | 8 | Uninstall |
| 03 | 1 | Endpoint classification from the raw path |
| 03 | 2 | Representation and path decomposition |
| 03 | 3 | Host context, phase A |
| 03 | 4 | The unknown-host guard |
| 03 | 5 | Admin redirect, method-aware, ajax exempt |
| 03 | 6 | Serving eligibility and content policy |
| 03 | 7 | The preserved query-var allowlist |
| 03 | 8 | Dispositions and the mapped-host guard |
| 03 | 9 | Composition root and hook registration |
| 04 | 1 | Path normalization |
| 04 | 2 | The routing contract and the default subtree walk |
| 04 | 3 | Collisions stay ambiguous |
| 04 | 4 | Mandatory round-trip verification |
| 04 | 5 | The resolver and the unmatched policy |
| 04 | 6 | Bounded query scope with membership enforcement |
| 04 | 7 | Feed and sitemap wiring |
| 04 | 8 | Wire the resolver into parse_request |
| 04 | 9 | The `CteSubtreeAdapter` capability gate |
| 05 | 1 | The URL policy and its protected paths |
| 05 | 2 | Absolute-URL validation and scheme downgrade |
| 05 | 3 | The compatibility matrix and the core link adapters |
| 05 | 4 | Feeds, comments, embeds, sitemaps, and the home option |
| 05 | 5 | Canonical policy and its adapters |
| 05 | 6 | CORS with strict origin parsing |
| 05 | 7 | Background context for cron, CLI, and mail |
| 06 | 1 | Challenge tokens and record composition |
| 06 | 2 | The DNS-over-HTTPS resolver |
| 06 | 3 | The restricted native resolver |
| 06 | 4 | Grace arithmetic |
| 06 | 5 | The verifier, its lease, and the discarded result |
| 06 | 6 | Fresh proof |
| 06 | 7 | Queue, budget, and cron topology |
| 07 | 1 | Resource context and identity |
| 07 | 2 | Timing policy |
| 07 | 3 | The mutation lease |
| 07 | 4 | The execution permit and the authorization |
| 07 | 5 | The driver contract and the null driver |
| 07 | 6 | The mutation gate |
| 07 | 7 | Lease recovery |
| 07 | 8 | Installation identity and clone detection |
| 07 | 9 | Registry, driver factory, cooldowns, shared preconditions, and the deletion authorizer |
| 08 | 1 | Ambiguous-create decisions |
| 08 | 2 | Provisioning |
| 08 | 3 | Explicit adoption |
| 08 | 4 | Validation-method change |
| 08 | 5 | Durable deletion and force-local-delete |
| 08 | 6 | Driver-backed recovery and the SSL sweep |
| 09 | 1 | Pinned schema, policy, and the generator |
| 09 | 2 | Combining the two status axes |
| 09 | 3 | Typed apex capability |
| 09 | 4 | The validation plan and its translations |
| 09 | 5 | The driver, credentials, and error 1413 |
| 09 | 6 | Making the driver reachable in production |
| 10 | 1 | The error vocabulary, the guard, and the resource shape |
| 10 | 2 | The collection, registered only on the primary host |
| 10 | 3 | The individual resource |
| 10 | 4 | Verification probe and challenge rotation |
| 10 | 5 | The validation plan and the SSL operations |
| 10 | 6 | Environment status and resolution |
| 11 | 1 | Admin menu, list table, and the environment notice |
| 11 | 2 | The validation plan, rendered by purpose |
| 11 | 3 | Diagnostics and the browser-side CORS probe |
| 11 | 4 | The README |
| 11 | 5 | The cross-subsystem acceptance suite |

Total: 82 tasks


## Commits, in order

```
6da36d7 Start the implementation journal
e370bff Add the Composer project and the environment gate
5a1ab84 Bootstrap the plugin in the order the design requires
adefa1c Parse authority, normalize hosts, and pin one UTS-46 implementation
69dea01 Add the prefixed build config, coding standard, and static analysis
4826e68 Run the integration suite against real WordPress
200ac11 Install the schema and read mappings
5ce4705 Write mappings under CAS, log events atomically, and resolve aliases
480ab56 Record Plan 02 completion in the journal
4acfd43 Generate challenge tokens and compose the TXT record name
72bb130 Resolve verification TXT records over DoH with two-endpoint agreement
d4a2fcd Restrict the native resolver to outcomes it can actually justify
9c25b8b Classify endpoints from the raw path alone
3237bff Increment then compare, and keep transient results out of the count
255b4c4 Check the plans' own PHP parses, resolves, and agrees with itself
ac70dbe Build the host context in the specified order
7fabc73 Lease a mapping before resolving and discard a stale result
f50fadc Prove ownership live, with no stored state consulted
970f86e Reject unknown hosts with 421 and malformed ones with 400
f425251 Send admin and login on a mapped host back to the primary host
5bb2825 Allowlist the query vars promoted into the query, subtract the routing ones
22be070 Freeze policy in three phases, each where its answer is checkable
9526d51 Generate the Cloudflare status map from a pinned, digested snapshot
f76df22 Give every request exactly one named disposition
4af146f Record the Cloudflare status-map work in the journal
58fc83b Wire the request pipeline at the specified hook priorities
6601b26 Skip a mapping that no longer exists before paying for a DNS query
98c26ac Separate provider identity from mutation authorization
6513a42 Centralize lease and recovery timing above an exclusive floor
a5e7c3c Keep the generated status map out of the formatter's reach
f0cc0fa Make execution permits issuable only by the gate
f60b07a Run schema upgrades only where dbDelta is acceptable
6ff8103 Define the SSL driver contract with a working null default
c399eb6 Combine the two Cloudflare status axes and type apex capability
b673bf8 Bind every authorization value into the consumption CAS
25d8321 Make the gate the only caller of a mutating driver method
46eaa56 Normalize subtree paths and reject what must never resolve
654bf34 Document every boundary the plugin cannot enforce for itself
f2e5585 Resolve and generate subtree paths behind one contract
6e6db62 Keep the subtree files clean under the coding standard
992d67f Recover expired leases to a conclusive, token-owned result
db3118e Detect a clone and strip its inherited authority
ed8285a Leave colliding segments unresolved rather than picking a winner
a55d7ea Verify every generated path resolves back to its own post
6813221 Resolve mapped requests and handle misses without bouncing a POST
e02bd63 Record the parallel track split in the journal
f7403fe Bound feed and sitemap scope, then check membership anyway
423e5d9 Scope feeds to the subtree and validate every post before output
18859d9 Resolve routed requests after the disposition guard has run
934a1c1 Gate the recursive-CTE scope adapter behind evidence and a probe
7ba0db0 Resolve drivers through one factory and refuse malformed identities
a18b708 Decide rebasing in one policy, with protected paths forced closed
3f27d33 Validate filter-returned URLs and refuse a scheme downgrade
378ce59 Silence two coding-standard warnings without changing behaviour
6899450 Read both shapes Cloudflare ships for its error arrays
5884590 Rebase core link surfaces and assert on rendered output
818e647 Rebase feeds, comments, embeds, and sitemaps; keep the home option opt-in
8456ff4 Run the lease-recovery and deletion-authorizer tests on an owned session
d86b4c8 Compute canonical per request and filter core's redirect proposal
eb5606c Register the verification cron topology from the bootstrap
c7f443e Record the cron-wiring decomposition in the journal
41597f9 Authorize the requesting origin for CORS, never a wildcard
af15e90 Let cron, CLI, and mail borrow a mapping's context explicitly
c3c7883 Avoid reserved keywords in parameter names
2e1b04e feat(ssl): provider mutation services and the Cloudflare for SaaS driver
5388ea6 feat(ssl): driver-backed lease recovery and the reconciliation sweep
9eb7738 test: prove the subsystems compose end to end
290b2ee feat(admin): settings, mapping list, domain detail, and diagnostics
5ca784a feat(rest): the management API, registered only on the primary host
```

## Subsystems and where they live

| Area | Path |
|---|---|
| Bootstrap and hook topology | `src/Plugin.php` |
| Host parsing, IDN, public suffix | `src/Support/`, `references/public_suffix_list.dat` |
| Data model, schema, event log | `src/Mapping/`, `src/Support/Schema.php` |
| Request pipeline and dispositions | `src/Routing/` |
| URL generation and canonicalisation | `src/Url/` |
| Ownership verification | `src/Verification/` |
| SSL authorization, leases, recovery | `src/Ssl/` |
| Cloudflare for SaaS driver | `src/Ssl/CloudflareSaasDriver.php`, `references/cloudflare-*` |
| REST management API | `src/Rest/` |
| Admin screens and diagnostics | `src/Admin/`, `src/Http/` |

## Tests that could not be run, and why

- **`composer test:integration:wp-env`.** The `wp-env` `tests-cli` image fails to
  build: `composer global require --dev phpunit/phpunit` exits 100. A native
  harness was built instead — `bin/integration-env.sh` brings up an isolated
  `pd-mysql` container on port 33306 plus the wordpress-develop test suite under
  `tmp/wp-tests/`, and `composer test:integration` uses it. `.wp-env.json` is
  left unchanged so CI can still use it if the image is fixed. The integration
  suite itself ran in full; only that one entry point is unavailable.
- **Nothing else.** Every other prescribed check ran.

## Deferred and blocked

- **`CteSubtreeAdapter` remains disabled and unwired**, as instructed. Its
  capability probe and disabled gate are implemented exactly as designed; the
  target-environment evidence that would justify enabling it is still
  unavailable.
- **`GET /environment` and the installation id.** Spec §15.2's table lists it in
  the response; Plan 10 withholds it and has a test asserting it stays out. The
  plan's behaviour was implemented, because keeping an identifier out of a
  response is the reversible choice. **This needs a decision.**
- ~~`DELETE /domains/{id}/ssl` deletes the whole mapping row.~~ **Resolved in the
  correction pass.** The two operations are now separate services sharing one
  `RemovalWorkflow`: `DELETE /domains/{id}` still performs whole-mapping deletion
  through §14.15, and `DELETE /domains/{id}/ssl` removes only the provider
  resource, retaining the row and clearing the five binding columns together.
- **Absent because no plan task covers them**: collection pagination and filters,
  `title` and `favicon_attachment_id`, `DELETE ?force=true`, and several §15.1
  resource members (`verification` detail, `deletion`, `branding`,
  `provider_state`, `validation_plan`, timestamps). `_compute=serving` is
  implemented.

## Known limitations

- **A host with `autocommit = 0` cannot commit a transition.** `AtomicTransition`
  detects an ambient transaction and refuses, by design — the detect-and-refuse
  policy. On a host that runs every connection with autocommit disabled, that
  refusal is permanent rather than situational, and no state transition will ever
  commit. This is a genuine production limitation of the current policy, not a
  test artefact; it is what made `OwnedSessionTestCase` necessary.
- **`Schema::probe_engine()` was blind to restricted hosts** until this session.
  It asked only `INFORMATION_SCHEMA.TABLES`, which lists neither temporary tables
  nor tables the connecting user lacks privileges to see, and answered `unknown`
  — silently dropping event atomicity on a database that fully supports it. It
  now falls back to `SHOW CREATE TABLE`.
- **The Public Suffix List is a committed snapshot.** Apex detection is only as
  current as `references/public_suffix_list.dat`.
- **The Cloudflare status map is generated from a pinned snapshot**
  (`references/cloudflare-api-schema.2026-08-27.json`, sha256 `5593002e5363b8…`).
  New provider status values will not be classified until the snapshot is
  refreshed and the map regenerated.

## For the reviewer

```bash
cd /Volumes/Kobzar/workbench/Blueprint/Plugins/post-domain/post-domain
git log --oneline 89fd1f4..HEAD          # 69 commits
bin/integration-env.sh                   # brings up pd-mysql and tmp/wp-tests
composer lint && composer analyse && composer test && composer test:integration
composer lint:plans
composer generate:status-map && git diff --exit-code
```

Read `docs/superpowers/implementation-journal.md` alongside this document: every
deviation from a plan example is numbered there with its reasoning, and the two
decisions listed under *Deferred and blocked* are the only places where the
implementation deliberately stopped short of choosing.


---

## Correction pass — 2026-08-28

Six verified defects fixed on the same branch. Full reasoning per finding is in
`docs/superpowers/implementation-journal.md`.

| # | Defect | Fix |
|---|---|---|
| 1 | `DELETE /domains/{id}` marked a row `pending_removal` and nothing ever processed it; every retry outcome left the next-attempt time permanently due | `Ssl\DeletionSchedule` selects due, unleased pending-removal rows; `Ssl\CronWiring` runs them on `pd_ssl_sweep` after recovery; `RemovalWorkflow::retry_schedule()` gives every outcome a future time |
| 2 | The verification result CAS omitted the leased revision, so a stale DNS result could overwrite a concurrent edit | `VerificationLease` carries the exact leased revision; the CAS binds id, revision, token and challenge |
| 3 | A single DoH endpoint, or a duplicated one, could produce a hard outcome | Endpoints are qualified and deduplicated before any request; fewer than two distinct HTTPS endpoints returns TRANSIENT |
| 4 | `DELETE /domains/{id}/ssl` hard-deleted the whole mapping | `SslResourceRemoval` shares `RemovalWorkflow` with `DeletionService` but retains the row and clears the five binding columns together |
| 5 | Cron scheduled every hook once at `time() + 60`, ignoring its declared interval; `pd_maintenance` had no listener | Recurring events on plugin-owned schedules, replacing stale cadences; `Verification\Maintenance` implements the four §13.6 duties, diagnostics only |
| 6 | `pd_rebase_url` results bypassed the strict URL validator | Routed through `AbsoluteUrl::validated()` with HTTPS required and any non-443 port refused |

### Verification, every command executed and observed

| Command | Result |
|---|---|
| `composer lint` | exit 0 |
| `composer analyse` | `[OK] No errors`, level 8 |
| `composer test` | OK — 329 tests, 591 assertions |
| `composer test:integration` | OK — 731 tests, 1647 assertions, four identical consecutive runs |
| `composer lint:plans` | exit 0 |
| `composer generate:status-map` + `git diff --exit-code` | byte-identical |
| `git diff --check` | clean |
| Focused, all six findings | OK — 72 integration tests, 255 assertions, plus 13 DoH unit cases |

### A note for CI

The integration suite must not be run as two concurrent processes against one
database. Doing so produces deadlocks and connection errors that look like test
flakiness; run serially it is deterministic.

No unresolved blocker.

IMPLEMENTATION SESSION COMPLETE
