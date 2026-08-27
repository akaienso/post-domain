# post-domain — Implementation Plan Suite Index

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement each plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md` (approved at commit `0c1b0dd`)

**Why a suite:** the specification spans 20 sections and 72 subsections across
seven substantial subsystems — host model, data model, request pipeline, routing,
URL generation, verification, and SSL. One plan would be too large to execute or
review task-by-task. The split below is by **reviewable deliverable**, not by
technical layer: a reviewer can accept or reject each plan's working software
without reading the next.

---

## Implementation order

| # | Plan | Deliverable a reviewer can accept or reject |
|---|---|---|
| 01 | `2026-08-27-post-domain-01-foundation.md` | An installable plugin that boots on single-site WordPress, is inert on multisite, and normalizes any `Host` header correctly — with the full toolchain, the plan-example check, and CI green |
| 02 | `2026-08-27-post-domain-02-data-model.md` | The two tables, row invariants, alias rules, CAS writes, state enums, and a clean uninstall |
| 03 | `2026-08-27-post-domain-03-request-pipeline.md` | Requests classified and dispositioned: 400 / 421 / 404 / 503 / serve, with the three policy phases frozen in order |
| 04 | `2026-08-27-post-domain-04-routing.md` | A mapped host resolves its subtree both directions, collisions stay ambiguous, feeds and sitemaps are membership-validated |
| 05 | `2026-08-27-post-domain-05-url-generation.md` | Every URL surface in the compatibility matrix emits mapped-host URLs, asserted on rendered output; canonical, CORS, and the auth boundaries hold |
| 06 | `2026-08-27-post-domain-06-verification.md` | Domains prove ownership by TXT over DoH, with grace, backoff, leases, and cron |
| 07 | `2026-08-27-post-domain-07-ssl-authorization.md` | The mutation lease, gate, permits, and authorizers — no provider mutation can begin without a consumed authorization |
| 08 | `2026-08-27-post-domain-08-ssl-lifecycle.md` | Create, adopt, method change, delete, recover, reconcile — every ambiguous outcome resolved by reading |
| 09 | `2026-08-27-post-domain-09-cloudflare-driver.md` | A working Cloudflare for SaaS driver with a generated status map and a validation plan |
| 10 | `2026-08-27-post-domain-10-rest-api.md` | The management REST API, primary-host-only, with ETags and preconditions |
| 11 | `2026-08-27-post-domain-11-admin-and-docs.md` | The admin screens, diagnostics, acceptance suite, and README |

## Dependencies

```
01 foundation
 └─ 02 data model
     ├─ 03 request pipeline
     │   └─ 04 routing
     │       └─ 05 url generation
     ├─ 06 verification            (needs 02 schema; independent of 03–05)
     └─ 07 ssl authorization       (needs 02 schema + 06 FreshProof)
         └─ 08 ssl lifecycle
             └─ 09 cloudflare driver
10 rest api          (needs 02, 03, 06, 07, 08; 09 optional at runtime)
11 admin and docs    (needs everything; last)
```

Plans 03–05 and 06–09 are two independent tracks after 02. They may be executed
in parallel by separate workers; 10 integrates both.

**Three things cross every plan and are settled once, early.** `AtomicTransition`
(Plan 02, Task 5) is the only sanctioned way to write a state change and its
event, and it returns a typed result rather than a boolean. `DriverFactory`
(Plan 07, Task 9) is the only production source of SSL drivers; Plan 09 adds
Cloudflare to its built-in list rather than constructing it anywhere of its own,
so REST, cron, reconciliation, recovery, Admin, and CLI cannot end up with
different registries. And the provider environment is bound twice, for two different lifetimes: a
**mutation** is bound to the environment it began against (Plan 02's two lease
columns, Plan 07's acquisition and recovery rules), and a **resource** is bound
for as long as it exists (`ssl_provider_environment`, promoted on successful
create or adoption, checked by `BoundResource` before any read or mutation). A
configuration change can therefore never make recovery question the wrong account,
nor make an ordinary status read adopt one account's answer about another
account's certificate.

`composer lint:plans` (Plan 01, Task 10) checks these documents' own PHP against
eight defect classes: syntax, unresolved imports, unresolved fully qualified
names, a bare short name with no import, duplicated imports, a type declared twice
outside an explicit "Replace" step, a pinned API called with the wrong arity, and
a skipped fragment carrying concurrency, authorization, transaction, deletion, or
provider-binding logic without a `covered-by` marker naming its test. It inspects
only complete examples and **lists** the fragments it skips, so a clean run cannot
be mistaken for coverage it does not have. It deliberately does **not** check SQL:
placeholder/value agreement is proven by success-path tests instead, because the
`release_reserved()` defect it would have had to catch is invisible to any
percent-sign count.

## Specification coverage by plan

| Plan | Spec sections |
|---|---|
| 01 | §1, §1.1, §1.2, §2, §2.1, §2.2, §3.1, §3.2, §3.3, §3.4, §3.5, §14.16 (host-level wildcard rejection), §18 (toolchain, including the plan-example check) |
| 02 | §3.7, §12.1, §12.2 (columns, including the two mutation-binding columns and the durable `ssl_provider_environment`), §12.3 (including the InnoDB transition-and-event transaction, its typed result, the ambient-transaction rule, and the unconfirmed-commit rule), §12.4, §12.5, §12.6 (columns + invariants only), §12.7, §18 (uninstall) |
| 03 | §3.6, §4, §4.1, §4.2, §4.3, §4.4, §5.1, §5.2, §5.3, §5.4, §9 (redirect + REST registration), §11.1, §11.4, §11.8 (host and request rows), and the Phase C invocations of §11.2 and §11.3 |
| 04 | §6, §6.1, §6.2, §6.3, §10, §11.2, §11.3 (the subtree filters themselves), §11.8 (subtree and scope rows), §20 |
| 05 | §7, §7.1, §7.2, §7.3, §7.4, §7.5, §8, §8.1, §9 (CORS and ajax), §11.5, §11.8 (URL rows) |
| 06 | §13.1, §13.2, §13.3, §13.4, §13.5, §13.6, §12.3 (verification transitions), §11.6 (verification rows), §11.8 (label row) |
| 07 | §12.2 (behaviour), §12.6 (protocol, including the durable resource environment and the mutation binding with their drift rules, the kind-and-phase-pinned recovery CAS, and the bounded re-read), §14.1, §14.2, §14.3, §14.4, §14.5, §14.8, §14.9, §11.6 (`pd_ssl_drivers` default, driver, lease, ttl rows) |
| 08 | §12.6 (fencing at finalization), §14.4 (the precondition set enforced per operation), §14.6, §14.7, §14.10, §14.15, §14.17 |
| 09 | §12.6 (`environment_id()` for Cloudflare), §14.11, §14.12, §14.13, §14.14, §14.16 (never requesting a wildcard), §14.18, §11.6 (`pd_ssl_drivers` — where Cloudflare joins the default, method, apex rows), §11.8 (method and apex rows) |
| 10 | §15, §15.1, §15.2, §15.3, §14.16 (rejecting a wildcard host), §11.6 (capability row) |
| 11 | §16, §16.1, §16.2 (including the certificate-provider selection and its diagnostics), §17 (acceptance), §19, §20 (gate reporting) |

§11.7 (actions) is implemented incrementally: each plan fires the actions its own
subsystem owns, listed in that plan's task that produces the state change.
§17 (testing) is not a single plan — every task in every plan is test-first, and
Plan 11 adds only the cross-subsystem acceptance suite.

## Integration gates

A plan is complete only when its gate passes. The next plan does not start until
its predecessor's gate is green.

| After | Gate |
|---|---|
| 01 | `composer test`, `composer lint`, `composer analyse`, `composer lint:plans` all pass — the last one against all seven defect classes, with every skipped fragment listed; plugin activates on wp-env single-site and refuses multisite activation |
| 02 | Schema installs and upgrades idempotently; every row invariant rejected at the repository, including all six lease columns moving together and `ssl_provider_environment` moving with `ssl_ref`; a transition and its event commit or roll back together on InnoDB and never precede the CAS on any engine, with a typed result that never reports an unstarted transaction or an unconfirmed commit as committed; a transaction opened elsewhere on the connection is detected and neither committed nor rolled back, and the transition does not run; an unconfirmed commit is never settled by re-reading the same connection; `uninstall.php` leaves a seeded post untouched |
| 03 | The disposition matrix integration test passes for all five outcomes across every host kind |
| 04 | The round-trip property test passes over a generated fixture tree; no unbounded scope executes |
| 05 | The rendered-output compatibility matrix passes for every row in spec §7.2 |
| 06 | A seeded mapping goes `unverified → pending → verified` against a stubbed resolver, and a transient result never deactivates it |
| 07 | No provider mutation is reachable without a consumed permit; the lease race tests pass; **every** lease-owning CAS pins every value its caller possesses **and has a passing success-path test against a real row with a bystander row untouched**, so a shifted value list cannot masquerade as a correctly-refused wrong owner; the driver and provider environment are bound before any provider call and pinned by the consumption CAS; recovery against a deregistered driver or a changed environment reads nothing and stays fenced; malformed or duplicate driver identifiers are refused before a lease exists; lease TTL and recovery grace strictly exceed the provider timeout plus the margin; a mapping with no provider never resolves to `NullDriver` by default |
| 08 | Every ambiguous outcome test resolves by a provider read, through the driver the lease was bound to and never one chosen from current configuration; a create or adoption promotes its environment into `ssl_provider_environment`, and a bound resource whose environment has drifted receives zero reads and zero mutations; a conclusive recovery leaves no recovery schedule behind; only a lost CAS is reported as fencing, and an unconfirmed commit claims nothing rather than re-reading its own connection; every precondition failure proves zero mutating provider calls; a failed finalization writes nothing, deletes nothing, logs nothing, and returns `FENCED`; reconciliation counts no zero-row update; force-local-delete cannot overwrite a lease |
| 09 | The status map generates offline from the digested snapshot, all 16 hostname and 21 SSL values are classified, CI fails on an unclassified value or a digest mismatch, and a configured Cloudflare driver is reachable from a mapping whose stored provider is null |
| 10 | Management routes are absent from `/wp-json/` discovery on a mapped host, every registered route is answered by a real handler introduced in the same task, and no fenced mutation is reported with a success status |
| 11 | Full suite green; README covers every item in spec §19 |

## Deferred capability gate

Spec §20 leaves exactly one item to implementation: the `CteSubtreeAdapter`
MySQL/MariaDB minimum-version matrix. It is **Plan 04, Task 9**, which is a hard
gate rather than a placeholder: the adapter ships disabled, the probe is real, and
enabling it anywhere requires evidence this repository does not yet contain. That
task names the exact evidence required. No other task may assume the adapter is
available.
