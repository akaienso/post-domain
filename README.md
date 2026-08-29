# post-domain

Maps a domain name to a single WordPress post, **resolved rather than
redirected**. The address bar keeps the mapped domain and the post's permalink
path never appears.

## 1. What it does

One domain maps to one post. A request for that domain is *resolved* to the post:
no redirect is issued, and the browser never sees the primary host. Descendants
of that post derive their addresses from the subtree beneath it — there is no
path-to-post mapping table, only a hierarchy walk.

Mapped hosts are **exact hosts**. Wildcard mappings and wildcard certificates are
out of scope: a wildcard host is refused at the REST boundary and no wildcard is
ever requested from a certificate provider.

## 2. Requirements

- **WordPress 6.4** or later
- PHP 8.1 or later
- **Single site only.** Activation on **multisite** is refused, and the plugin is
  otherwise inert there — no hooks are registered at all. Domain mapping inside a
  network is a different problem with a different solution, and supporting both
  makes both worse.

## 3. The DNS records

Four separate purposes. They are not interchangeable, and only one of them is
permanent.

| Purpose | Record | Removable? |
|---|---|---|
| **post-domain ownership** | `TXT` at `_post-domain-challenge.<host>` with `post-domain-verify=<token>` | **No — it must never be removed.** Verification re-checks it; deleting it eventually marks the mapping failed and stops it serving. |
| **Provider hostname ownership** | provider-specified `TXT` (Cloudflare: `_cf-custom-hostname.<host>`) | Yes, once the provider reports the hostname active. |
| **Certificate validation (DCV)** | provider-specified `TXT`, or an HTTP token, per the chosen method | Yes, once the certificate is issued — but a renewal may need it again. |
| **Routing** | `CNAME` to the provider's target, or an apex arrangement (§13) | No, obviously. |

## 4. Filter reference

Everything site-specific is a filter with a documented default. Integrators must
register post types, statuses, and their callbacks by the default `init` priority 10,
because the plugin freezes content policy at `init` priority 99.

URLs generated **before** `init : 99` are **not rebased** — the mapping decision
does not exist yet. That is a real limit, not an oversight.

| Filter | Default | Postcondition |
|---|---|---|
| `pd_mapping_is_active` | `true` | A `false` result stops the mapping serving; it never changes stored state. |
| `pd_subtree_post_types` | the mapped post's own type | Must return registered public types. |
| `pd_subtree_adapter` | the descendant-walk adapter | Must satisfy the round-trip contract. |
| `pd_txt_record_label` | `_post-domain-challenge` | Must compose to a valid ≤253-byte name. |
| `pd_doh_endpoints` | Cloudflare and Google DoH | Two endpoints must agree for a hard outcome. |
| `pd_dns_resolver` | `DohResolver` | **Trusted code** — see §11. |
| `pd_ssl_drivers` | `[NullDriver, CloudflareSaasDriver]` | Ids must be unique and stable. |
| `pd_ssl_validation_method` | `txt` | One of `http`, `txt`, `email`. |
| `pd_apex_capability` | driver-derived | Typed; A/AAAA needs attested provenance. |
| `pd_mutation_lease_ttl` | `120` | Clamped; always exceeds provider timeout + margin. |
| `pd_authorization_ttl` | `120` | Never beyond the held lease. |
| `pd_cors_allowed_origin` | the origin when verified and active | See §16. |
| `pd_rest_capability` | `manage_options` | An empty value falls back to `manage_options`. |
| `pd_unknown_host_policy` | `421` | See §15. |

## 5. Adding an SSL driver

Implement `PostDomain\Contracts\SslDriver`. It receives an
`SslResourceContext` — a value object carrying the mapping id, host, installation
id, the durable provider binding, the challenge, and the lease token — and returns
typed results.

A driver expresses its own ownership proof through `identify()`, returning an
`IdentityResult` with a `ProviderMarker` when the provider supports custom
metadata and `MarkerSupport::UNAVAILABLE` when it does not. The plugin never
guesses on the driver's behalf: with markers unavailable, an ambiguous resource
requires an explicit adoption.

Mutating methods (`create()`, `adopt()`, `change_validation_method()`,
`remove()`) take an `ExecutionPermit`, which only `MutationGate` can issue. A
driver cannot be called outside the gate.

## 6. Ownership provenance

`ssl_ownership_origin` is `created` or `adopted`:

- **`created`** — this installation asked the provider to create the resource.
- **`adopted`** — an operator explicitly claimed a resource that already existed.

It lives in **columns**, alongside `ssl_owner_installation_id`, never in the event
log. Events are history: nothing in authorization, routing, or state transition
reads them, which is what makes pruning them always safe. Deletion is still
authorized after every event for a mapping has been pruned.

## 7. The provider-mutation lease

Three phases:

- **`RESERVED`** — one worker owns the mutation window. Authorization checks and
  provider *reads* may run. **Nothing has been sent.**
- **`IN_FLIGHT`** — the authorization has been consumed and a provider mutation
  may have been sent. Its outcome may be unknown.
- **`RECOVERING`** — a recovery worker has fenced the original and is reading
  provider state to find out what happened.

While a lease exists — held by another token, in any phase, **expired or not** —
every path refuses any write that changes a field bound into an authorization,
with `pd_mutation_in_progress` (409), and never touches the provider. An expired
lease still blocks ordinary work; expiry transfers the row to `LeaseRecovery` and
to nothing else.

An expired `RESERVED` lease is proof the mutation **never began**, so it is
cleared without contacting the provider. An expired `IN_FLIGHT` lease proves the
opposite: something may have been sent, so it is fenced first and then *read*.

## 8. Authorization

Before any mutation: the environment is resolved, the driver matches the durable
binding, a `RESERVED` lease is held, provider identity is read fresh and complete,
no conflicting marker exists, and a **fresh** DNS proof succeeds.

Cached verification is not enough. `verification_state = verified` says the
challenge was published at some point; a mutation needs to know it is published
**now**, because the alternative is mutating a certificate for a domain the
operator no longer controls.

Each refusal names its precondition: `fresh_proof_failed`,
`identity_not_confirmed`, `conflicting_marker`, `no_ownership_authority`,
`lease_unavailable`, `environment_unresolved`, `provider_environment_changed`.

## 9. Creation ambiguity

A create that times out has an unknown outcome. The plugin **reads** rather than
retries. If the provider supports markers and one names this installation and this
mapping, the resource is bound. If markers are unavailable, the plugin will not
guess which unbound resource is its own: it reports `provider_create_ambiguous`
and asks for an explicit **adopt**.

## 10. Clone detection

If the primary host changes, the plugin blocks and asks one question:

- **Restore / move** — same site, new address. Identity, ownership, and challenges
  are kept.
- **Clone** — a copy. A new installation id is generated, the complete durable
  binding is cleared, challenges are rotated, and every row returns to
  `unverified`. A **clone** adopts nothing remotely.

## 11. `pd_dns_resolver` is trusted code

A custom resolver does not *integrate with* the ownership proof — it **substitutes**
it. Whatever it returns is what the plugin believes about domain control. Treat
replacing it as equivalent to granting certificate-issuance authority.

## 12. The DCV method

Allowed values are `http`, `txt`, and `email`. The default is **txt**.

Email is never automated: it requires a human to click a link in a mailbox the
plugin cannot see. Automatic HTTP DCV is a valid success path — Cloudflare
performs it for non-wildcard hostnames even when `txt` is selected, so a
certificate issuing without the TXT record ever being published is expected, not
a bug.

## 13. Apex routing

An apex host cannot carry a `CNAME`. Three arrangements:

- **CNAME flattening** at the authoritative DNS provider — no extra records.
- **ALIAS / ANAME** at providers that offer it.
- **A/AAAA** — permitted **only** with attested Apex Proxying or **BYOIP**
  provenance, which are entitlement-gated. Ordinary origin addresses are never
  emitted as apex targets: pointing an apex at an origin IP bypasses the provider
  entirely and silently breaks the certificate.

## 14. Authoritative DNS posture

The engine is **provider-neutral**. Cloudflare DNS is *recommended* for
operational consistency, DNSSEC, and apex flattening, but is **not required**.

Three roles are separate and must not be conflated: DoH resolvers (used to read
proofs), Cloudflare for SaaS (used to issue certificates), and **authoritative
DNS** (where the customer's records live). No DNS is mutated by API — every record
is published by the domain owner. No paid entitlement is assumed. Prefer
client-owned accounts with least-privilege access.

## 15. The 421 default

An unknown host receives **421 Misdirected Request**, not the primary site. Only
an exact infrastructure allowlist is exempt: the primary host, `localhost`,
`127.0.0.1`, and the loopback forms — plus anything in `PD_ALLOWED_HOSTS`.

`PD_UNKNOWN_HOST_POLICY` is the escape hatch: set it to `serve` to fall back to
the old behaviour. It exists because a misconfigured reverse proxy can otherwise
lock an operator out.

The plugin cannot apply the web-server rule that would stop the request before
PHP. Generated nginx and Apache snippets are provided for that; PHP never runs for
a static file, so the plugin cannot answer for one.

## 16. CORS

`Access-Control-Allow-Origin` must come from **whichever host serves the asset**.
If fonts are served from the primary host and a page renders on a mapped host,
the primary host must send the header — and PHP never runs for a static file, so
the plugin cannot send it. That is why the generated web-server and CDN snippets
exist.

The CORS probe runs **in the operator's browser**, from a mapped-page origin. A
server-side fetch would prove nothing about what a browser will do, and the plugin
performs no server-side diagnostic fetch anywhere.

## 17. Auth consequences

Cookies bind to `COOKIE_DOMAIN`. A mapped host is a different origin, so
mapped-host REST and admin-ajax requests are **anonymous** — that is an invariant
boundary, not a policy choice.

The admin redirect (sending `wp-admin` and `wp-login.php` on a mapped host back to
the primary host) *is* a policy, layered over that boundary, and it is filterable.

## 18. The honest limit on URL interception

Plugins that hardcode a domain, or cache absolute URLs in post meta or an options
row, are **not interceptable**. The plugin filters the URL surfaces WordPress
routes through core functions; it cannot filter a string somebody stored last
year.

Diagnostics **detects** this — it renders a mapped page and reports absolute
primary-host URLs found in the output. It does not promise to fix it.

## 19. Uninstall

Delete mappings **before uninstalling**, so the durable removal workflow runs and
the provider resources are cleaned up. Uninstalling drops the ownership provenance
along with the rows, and a provider resource whose provenance is gone can no
longer be safely removed by this plugin.

`uninstall.php` removes only the plugin's own tables and options. Posts, terms,
and everything else are untouched.

## 20. Choosing a certificate provider

The **Certificate provider** setting is what turns managed SSL on. With nothing
selected the plugin runs correctly and simply never requests a certificate: a
provisioning request answers **`pd_ssl_not_configured`** rather than pretending to
have done something.

Incomplete provider credentials leave that provider **unregistered**, so the
refusal reads as a configuration problem rather than surfacing later as a
transport failure halfway through a provisioning run.

## 21. Why an SSL request can answer 409

`pd_mutation_fenced` means a recovery worker took the mutation over while the
provider call was outstanding. The local result was discarded: nothing was
written, nothing was deleted, and nothing was retried. Re-read the mapping before
trying again.

`pd_finalization_failed` is different — the provider confirmed, but whether that
was recorded locally could not be established from this request. Re-read shortly;
reconciliation settles it.

## 22. Event log fidelity

On InnoDB — the engine is probed at install and stored in **`pd_schema_engine`** —
a state change and its event row are written in **one transaction**. On any other
engine the transaction is skipped and the log is best-effort: it can lag or miss
rows.

That is tolerable precisely because **nothing reads it to make a decision**. It is
a support artifact, and pruning it is always safe.

## 23. Changing provider configuration while work is outstanding

Every mutation is durably bound to the driver and provider **environment** it
began against, before anything is sent. If that configuration changes before an
unresolved mutation is recovered, the plugin **queries nothing** and reports which
driver and environment must be restored. Restore it and recovery resumes.

The identity shown is a zone or account name — never a credential.

## 24. Changing provider configuration after provisioning

A certificate **does not move because the plugin was repointed**. Each bound
mapping records the environment its resource lives in. While the configured driver
points somewhere else, the plugin reads nothing about that certificate, changes
nothing, and freezes the last known state rather than adopting an answer from an
account that has never heard of it.

Diagnostics lists every such certificate, with the environment to restore.

## 25. Releases

Releases are cut by [Release Please](https://github.com/googleapis/release-please)
from [Conventional Commits](https://www.conventionalcommits.org/) on `main`.

1. **A human merges an ordinary pull request.** Every feature, fix and
   maintenance PR is merged by a person. Nothing about the automation below
   applies to them.
2. **Release Please opens the release PR**, updating `CHANGELOG.md` and the
   `Version:` header in `post-domain.php`.
3. **GitHub merges that one PR automatically**, once the required checks pass.
   Auto-merge is queued, not forced: `checks` and `Require conventional PR title`
   are required on `main` and remain the authority on whether it lands.
4. **Release Please tags the commit and publishes the GitHub release.**
5. **`release.yml` attaches the installable ZIP**, `post-domain-VERSION.zip`,
   to that release.

Step 3 is the only automatic merge in the repository. The workflow that queues
it, `.github/workflows/auto-merge-release-please.yml`, acts only when the
pull request's author, head repository, head branch, base branch, label and
draft state *all* match the Release Please release PR exactly. A branch merely
named like the release branch, an account merely named like the bot, a PR
without the `autorelease: pending` label, a draft, or anything from a fork is
left for a person to merge.

`tools/verify-auto-merge-guard.mjs` proves this by lifting the guard out of the
workflow file and running it against payloads for each of those cases, so the
proof cannot drift from the code it is proving:

```bash
node tools/verify-auto-merge-guard.mjs
```

### Required repository configuration

| Setting | Value |
|---|---|
| Repository | Allow auto-merge: **on** |
| `main` protection | Required checks: `checks`, `Require conventional PR title` |
| Secrets | `RELEASE_APP_ID`, `RELEASE_APP_PRIVATE_KEY` |
