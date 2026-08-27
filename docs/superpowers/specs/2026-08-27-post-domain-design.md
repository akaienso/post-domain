# post-domain — design specification

**Date:** 2026-08-27
**Status:** proposed design, not yet implemented
**Repository:** `github.com/akaienso/post-domain`

---

## 1. Purpose and scope

`post-domain` maps a domain name to a single WordPress post. A visitor typing that
domain sees that post as the site's homepage, **resolved rather than redirected**: the
address bar keeps the mapped domain, and the post's normal permalink path never
appears.

Descendants of the mapped post resolve underneath it. Children — pages, attachments,
or any post type an integrator declares as belonging to the mapped post — are served
as paths beneath the mapped domain's root, computed by walking the hierarchy. **There
is no path-to-post mapping table.** The one mapped post is the subtree root and
everything else derives from it.

Requests arriving on the site's own primary domain are untouched. Normal permalinks
continue to work exactly as they did.

Mapped hosts are **exact hosts**. Wildcard host mappings are invalid, and wildcard
certificate provisioning is out of scope for this design (§14.16).

### 1.1 Single site only

The plugin must not require a multisite network and must not be installed in one.
Activation on multisite is refused with a clear message; at every other bootstrap on
a multisite install the plugin registers no hooks at all and is inert. Domain mapping
in a network is a different problem with a different solution, and supporting both
makes both worse.

### 1.2 Portability is the primary constraint

The plugin is an engine. It ships with no knowledge of any particular post type,
taxonomy, field system, theme, content model, or **authoritative DNS provider**. Every
question with a site-specific answer is asked through a filter and answered by the
integrator.

**The test applied to every decision: two unrelated sites, with different content
models and different authoritative DNS providers, must both run this plugin
unmodified.**

The plugin does not:

- assume a post type beyond what the admin selects in its own settings
- require ACF or any other field plugin
- hardcode a path segment, slug pattern, or URL shape
- assume a theme, template hierarchy, or markup structure
- require any particular authoritative DNS provider, or any paid entitlement of one
- make an editorial decision an integrator might reasonably make differently

Anything in that list is a filter with a documented default.

---

## 2. Architecture

### 2.1 Module tree

```
post-domain/
  post-domain.php              autoload, activation hook, multisite inertness, Plugin::boot()
  uninstall.php                drops both tables + own options; posts untouched
  src/
    Plugin.php                 composition root: container, hook registration
    Contracts/
      MappingRepository.php  RoutingContract.php  DnsResolver.php
      SslDriver.php  QueryScopeProvider.php
      Clock.php  Scheduler.php  HttpClient.php
    Support/
      AuthorityParser.php  Authority.php
      IdnaNormalizer.php       sole caller of the vendored UTS-46 implementation
      HostNormalizer.php  TrustedProxy.php  Schema.php  PublicSuffix.php
    Mapping/
      Mapping.php  VerificationState.php  ActivationState.php  SslState.php
      OwnershipOrigin.php  DbRepository.php  AliasResolver.php
    Routing/
      EndpointClass.php  Representation.php  Classifier.php
      PathNormalizer.php  PathDecomposer.php  PathDecomposition.php
      HostKind.php  HostContext.php  HostContextFactory.php
      ServingEligibility.php  ServingContext.php  ContentPolicy.php  ContextHolder.php
      Disposition.php  MappedHostGuard.php  UnknownHostGuard.php
      Resolver.php  Subtree.php  Resolution.php  UnmatchedPolicy.php  QueryScope.php
    Url/
      UrlPolicy.php  UrlKind.php  Compatibility.php
      HostValue.php  AbsoluteUrl.php
      Adapters/{CoreLinks,RestLinks,AjaxUrl,FeedLinks,CommentLinks,
                EmbedLinks,SitemapLinks,MailLinks,OptionHome}.php
      Canonical/CanonicalPolicy.php  CanonicalUrl.php
      Canonical/Adapters/{RelCanonical,RedirectCanonicalGuard,EmbedCanonical}.php
    Http/
      Cors.php  ServerConfig.php  AssetProxy.php  AdminRedirect.php
    Verification/
      Challenge.php  DohResolver.php  NativeDnsResolver.php
      DnsOutcome.php  DnsResult.php  Verifier.php  GracePolicy.php
      Lease.php  Schedule.php  FreshProof.php
    Ssl/
      SslDriverRegistry.php  DriverCapabilities.php  SslResourceContext.php
      SslStatus.php  IdentityResult.php  IdentityVerdict.php  ProviderMarker.php
      MarkerSupport.php  RemovalResult.php  RemovalOutcome.php  ReconcileReport.php
      MutationLease.php  MutationKind.php  MutationPhase.php
      MutationGate.php  MutationAuthorization.php  ExecutionPermit.php
      MutationRefusal.php  LeaseRecovery.php
      DeletionAuthorizer.php  AdoptionAuthorizer.php  MethodChangeAuthorizer.php
      CreateRecovery.php
      ApexCapability.php  ApexRouting.php
      ValidationPlan.php  DnsRequirementSet.php  DnsRecordSpec.php
      HttpRequirementSet.php  ManualRequirement.php  ValidationPending.php  DnsBlocker.php
      NullDriver.php  CloudflareSaasDriver.php  CloudflareStatusMap.php
      Reconciler.php  Credentials.php  Cooldown.php  Environment.php
    Rest/
      ManagementController.php  Guard.php
    Admin/
      SettingsPage.php  MappingListTable.php  Diagnostics.php  EnvironmentNotice.php
    Branding.php
  references/
    cloudflare-api-schema.<date>.json     pinned upstream schema snapshot
    cloudflare-schema-provenance.json     source URL, retrieval date, SHA-256 digest
    cloudflare-status-policy.php          human-maintained classification policy
    cloudflare-status-map.php             GENERATED from schema × policy
  bin/
    generate-cloudflare-status-map.php
  tests/
    unit/  integration/  fixtures/uts46.txt
  docs/
```

### 2.2 Boundaries that carry the weight

**Context** — `HostContext` and `ServingContext` are the single source of truth for
"what host is this and is it serving?" No other class reads `$_SERVER['HTTP_HOST']`.
This prevents the classic failure where one code path believes it is on the mapped
domain and another does not.

**`RoutingContract`** — owns path logic in **both directions**: path → post for
resolution and post → path for URL generation. Because both live behind one contract
against one filter set, a URL the plugin emits and a URL it resolves cannot disagree.

**Identity is separate from authorization.** In the SSL subsystem, "is this the
resource we expect?" and "is this installation allowed to mutate it?" are two different
questions answered by two different components: the driver answers identity, and a
plugin-owned gate outside the driver answers authorization. Collapsing them is how a
stale local flag becomes a deletion.

**Authorization never depends on prunable data.** Ownership provenance lives in
first-class columns on the mapping row. The event log is a support artifact and is
never read to make a decision.

**Interfaces** — every dependency that touches the outside world (database, DNS,
clock, scheduler, HTTP, certificate provider) is behind an interface and injected at
the composition root. Everything else crosses boundaries as readonly value objects.
WordPress globals (`$wp`, `$wp_query`) are touched in exactly two places:
`Routing\Resolver` and the URL adapters.

---

## 3. Host model

### 3.1 Authority parsing

The raw `Host` header is an *authority*, not a hostname. It is parsed **before**
anything else — before the infrastructure allowlist and before IDN normalization — so
that a malformed authority can never be reshaped into something that matches an
allowlist entry.

```php
final class Authority {
    public readonly string $host;              // extracted; identity unchanged
    public readonly ?int   $port;
    public readonly bool   $is_ipv6_literal;
    public readonly string $bracketed_form;    // '[::1]' for IPv6, else identical to $host
}
final class AuthorityParser {
    /** @return Authority|null  null => MALFORMED_400, always */
    public function parse( string $raw ): ?Authority;
}
```

Steps, in order. Any failure returns `null`, and `null` routes to `MALFORMED_400`
without exception:

1. Trim **only** leading and trailing spaces and horizontal tabs. No other trimming.
2. Reject any remaining internal whitespace of any kind.
3. Reject control characters (`0x00`–`0x1F`, `0x7F`) and NUL.
4. Reject path separators and delimiters: `/`, `\`, `?`, `#`.
5. Reject `@` outright — userinfo has no place in a `Host` header.
6. Branch on form:
   - **Bracketed IPv6** — the value begins with `[`. It must match
     `^\[[0-9A-Fa-f:.]+\](:[0-9]+)?$`, and the bracketed content must be a
     syntactically valid IPv6 literal, **validated before the brackets are removed**.
     An unbalanced, missing, or misplaced bracket is malformed.
   - **Hostname with optional port** — the value contains at most one `:`. More than
     one `:` without brackets is an ambiguous bare IPv6 literal and is malformed; it is
     never partially consumed.
7. Port, when a `:` is present: the remainder must be non-empty, decimal digits only
   (no sign, no whitespace, no hexadecimal), and in `1..65535`. `host:`, `host:0`,
   `host:abc`, and `host:99999` are all malformed.
8. Extract the host **without altering its identity** — no lowercasing, no trailing-dot
   removal, no IDN conversion at this stage.
9. Only now is the lowercased exact infrastructure-allowlist comparison performed.

A malformed port or a malformed bracket form is **never discarded in a way that turns a
malformed authority into an allowlisted host**. `evil host:` and `[::1` do not become
`evil host` and `::1`; they become `400`.

### 3.2 Infrastructure allowlist comparison

The allowlist is compared against the parsed, lowercased host — after §3.1 succeeds and
**before** the IDN pipeline, because the hosts that belong on it are exactly the ones
the normalizer rejects.

Supported entry forms, exact match only:

- hostnames (`origin.example.com`)
- `localhost`
- IPv4 literals (`10.0.0.4`)
- bracketed IPv6 literals (`[2001:db8::1]`), compared in bracketed form

**No wildcard or suffix matching**, and no port component: an allowlist entry is a host,
not an authority. Sources: `PD_ALLOWED_HOSTS`, then
`pd_allowed_infrastructure_hosts`. Entries are themselves run through
`AuthorityParser`; entries that fail to parse, carry a port, or contain `*` are dropped
with a logged warning.

### 3.3 Normalization

`IdnaNormalizer` is the only caller of the vendored UTS-46 implementation.
`HostNormalizer::normalize()` receives an already-parsed `Authority`, is total, and
never throws:

```
→ reject IP literals (an IP is never a mappable host, though it may be allowlisted)
→ strip one trailing dot → lowercase ASCII portion
→ $u = Idn::idn_to_utf8( $h, UTS46, NONTRANSITIONAL )
→ $a = Idn::idn_to_ascii( $u, UTS46, NONTRANSITIONAL )
→ reject if either step fails, or if round-tripping $a is not stable
→ validate: ≤ 253 bytes, each label 1–63, LDH, no leading/trailing hyphen
→ reject any label consisting of or beginning with '*'   (no wildcard hosts)
→ return $a
```

Storage is always the ASCII/punycode form; it is the unique key, the lookup key, and
what goes into DNS. Unicode exists only in the admin UI, via `to_display()`. A user
pasting `münchen.example` and a user pasting `xn--mnchen-3ya.example` create the same
row; the second attempt is a duplicate-key error.

### 3.4 IDN implementation

**A single bundled implementation is used on every host, exclusively.**
`symfony/polyfill-intl-idn`, pinned to `1.38.1`, is called through
`Symfony\Polyfill\Intl\Idn\Idn::*` directly — never through the global `idn_to_*`
functions, whose availability depends on a PHP extension and would reintroduce a second
implementation. Two UTS-46 implementations disagreeing on one input is a
verification-bypass shape, so only one is ever in play, and no PHP extension is a hard
requirement.

Requirements: `composer.lock` committed; the prefixed build reproducible from it;
`composer audit` in CI; UTS-46 conformance vectors fixed at
`tests/unit/fixtures/uts46.txt`; version bumps are deliberate PRs that re-run the
vectors.

### 3.5 Trusted proxies

The served authority is `$_SERVER['HTTP_HOST']`. `X-Forwarded-Host` and `Forwarded` are
honoured **only** when `PD_TRUSTED_PROXIES` (CIDRs) is defined *and* `REMOTE_ADDR`
falls inside it. No filter enables forwarded headers without an IP allowlist. A
forwarded value is parsed by `AuthorityParser` on exactly the same terms as a direct
one.

### 3.6 Host kinds

```php
enum HostKind { case PRIMARY; case MAPPED; case ALLOWED_INFRASTRUCTURE;
                case UNKNOWN; case MALFORMED; }
```

`HostContextFactory` assigns them in this order: parse authority (§3.1) — failure ⇒
`MALFORMED`; allowlist comparison (§3.2) — hit ⇒ `ALLOWED_INFRASTRUCTURE`; normalize
(§3.3) — failure ⇒ `MALFORMED`; then `PRIMARY`, `MAPPED` (a row exists, whatever its
state), or `UNKNOWN`.

### 3.7 Aliases

An alias is an ordinary row with `alias_of` set. It:

- has **no** `post_id`; target and content policy derive from the canonical row
- has its **own** DNS challenge, verification state, SSL state, and activation state
- may set its own title/favicon, falling back to the canonical row's
- may not chain: its target row must have `alias_of IS NULL`
- serves only when **both** alias and canonical eligibility pass
- canonicalizes to the canonical row's host

`www.example.com` is never created implicitly.

---

## 4. Request classification

Two orthogonal axes, decided at two different times.

### 4.1 `EndpointClass` — raw path only, at `plugins_loaded : 0`

```php
enum EndpointClass {
    case CLI; case CRON;
    case ADMIN; case LOGIN; case AJAX;
    case REST_MANAGEMENT; case REST_CONTENT;
    case COMMENT_POST; case TRACKBACK; case XMLRPC; case CRON_HTTP; case INFRASTRUCTURE;
    case ASSET; case WELL_KNOWN; case SITEMAP;
    case ROUTED;
}
```

No conditional tag is called. REST is detected in **both** forms — the path prefix from
`rest_get_url_prefix()` and `?rest_route=` — because a `rest_route` request has path
`/` and would otherwise be handed to the subtree resolver as a root request.

Only `ROUTED` reaches `Routing\Resolver`.

Behaviour on a mapped host: `COMMENT_POST` and `TRACKBACK` are served (the comment
form action stays on the mapped host); `XMLRPC` returns 404
(`pd_xmlrpc_on_mapped_hosts`, default false); `CRON_HTTP` and `INFRASTRUCTURE` are
served with primary context; `ADMIN`/`LOGIN` redirect; `AJAX` is served and exempt from
that redirect.

### 4.2 `Representation` — query-dependent, at `parse_request`

```php
enum Representation { case HTML; case FEED; case EMBED; case TRACKBACK; case JSON; }
```

### 4.3 Path decomposition is the bridge

Representation and pagination suffixes are split off **before** the subtree walk, so
descendant feeds and embeds resolve their base content path while the walk stays a
pure path → post function.

```php
final class PathDecomposition {
    public readonly string $base;          // '/events/gala'
    public readonly Representation $rep;
    public readonly ?string $feed_type;
    public readonly ?int    $paged;
    public readonly ?int    $comment_page;
    public readonly string  $raw_query;    // verbatim; never parsed for resolution
}
```

`/events/gala/feed/atom/?utm_source=x` → base `/events/gala`, rep `FEED`, type `atom`,
query preserved verbatim.

### 4.4 Query preservation

The raw query string is preserved verbatim on every redirect and left untouched in
`$_GET` and `$_SERVER['QUERY_STRING']`, so analytics parameters always survive.

Separately, an explicit **allowlist** governs what is copied into `$wp->query_vars`:

```
paged, page, cpage, replytocom, feed, embed          // pd_preserved_query_vars
```

`preview*` are excluded because there is no authenticated user on a mapped host and a
preview var can only mislead. `attachment` is excluded because it can address a post
outside the subtree.

---

## 5. Context and timing

### 5.1 Context types

```php
final class HostContext {                 // immutable, always present
    public readonly string        $raw_authority;
    public readonly ?Authority    $authority;
    public readonly ?string       $ascii_host;
    public readonly HostKind      $kind;
    public readonly ?Mapping      $mapping;      // the row, whatever its state
    public readonly EndpointClass $endpoint;
    public readonly bool          $is_https;
    public readonly string        $method;
    public function has_row(): bool;
    public function may_serve(): bool;
}

final class ServingEligibility {          // frozen at plugins_loaded : 11
    public readonly Mapping $mapping;
    public readonly string  $requested_host;
    public readonly string  $canonical_host;
    public readonly bool    $is_active;
}

final class ServingContext {              // frozen at init : 99
    public readonly Mapping        $mapping;
    public readonly string         $requested_host;   // the host the visitor typed
    public readonly string         $canonical_host;   // alias → its parent; else identical
    public readonly bool           $is_active;
    public readonly int            $effective_post_id;
    public readonly array          $subtree_post_types;
    public readonly array          $post_statuses;
    public readonly int            $max_depth;
    public readonly array          $preserved_query_vars;
    public readonly ?Resolution    $resolution;
    public readonly Representation $representation;
}

final class ContextHolder {
    public function host(): HostContext;
    public function serving(): ?ServingContext;
    public function resolve( Resolution $r ): void;             // one-time upgrade
    public function with( Mapping $m, callable $fn ): mixed;    // scoped push/pop
}
```

Generated links use `requested_host`, so an alias visitor stays on the alias for the
whole session. Canonical metadata may use `canonical_host`.

Who reads which:

| Consumer | Reads |
|---|---|
| `AdminRedirect`, `Rest\Guard` | `HostContext` — a pending mapping is still not the primary host |
| `Http\Cors` | `HostContext` + request `Origin` |
| `Url\Adapters\*`, `Branding`, `QueryScope` | `ServingContext` |
| `CanonicalPolicy` | both |

### 5.2 Three policy phases

Policy is frozen in three phases. The dividing line is what must already exist for the
answer to be validatable.

**Phase A — `plugins_loaded : 0` · `HostPolicy`**
`pd_trusted_proxies`, `pd_allowed_infrastructure_hosts`, `pd_unknown_host_policy`,
`pd_endpoint_class` → frozen into `HostContext`.

**Phase B — `plugins_loaded : 11` · `ServingEligibility`**
`pd_mapping_is_active` → frozen, with `requested_host` / `canonical_host`. No
content-model question is asked here.

**Phase C — `init : 99` · `ContentPolicy`**
Runs after post types, statuses, and taxonomies are registered — the earliest point
any of these answers can be validated. Fixed internal order:

1. `pd_subtree_post_types` → each must be `post_type_exists()`
2. `pd_post_statuses` → each must be `get_post_status_object()`
3. `pd_target_post_for_host` → `effective_post_id`, validated against 1 and 2
4. `pd_max_subtree_depth`, `pd_preserved_query_vars`

**Integrator requirement:** post types, statuses, taxonomies, and all `pd_subtree_*` /
`pd_target_post_for_host` callbacks must be registered by default `init` priority (10).

**Compatibility limitation, documented:** URLs generated **before `init : 99` are not
rebased.** Adapters are registered but no-op, so anything emitting a link during
`plugins_loaded` or early `init` gets a primary-host URL. The content model does not
exist yet at that point, so no correct answer is available.

### 5.3 Dispositions

Computed once at `init : 99`, enforced at `parse_request : 0` by `MappedHostGuard`.
**A missing `ServingContext` never means "fall through as primary."**

```php
enum Disposition { case PRIMARY; case INFRASTRUCTURE; case SERVE;
                   case MALFORMED_400; case UNKNOWN_421;
                   case NOT_SERVING_404; case BROKEN_503; }
```

| Host state | Disposition |
|---|---|
| Authority fails to parse, or normalization fails | `400` |
| Unknown host, not allowlisted | `421 Misdirected Request` |
| Allowlisted infrastructure host | primary routing |
| Row exists but unverified / failed / inactive / vetoed | `404` |
| Verified + active, `ContentPolicy` invalid or `integrity_error` set | `503` |
| Verified + active, policy valid | mapped routing |

`404` rather than `421` for a known-but-not-serving host: the host is ours, it simply
is not serving. `PD_UNKNOWN_HOST_POLICY = 'passthrough'` is the documented lock-out
escape, settable in `wp-config.php` without database access. The guard never fires for
`CLI`/`CRON`; **`wp-cron.php` over HTTP is fully host-validated**, since the bypass
keys on genuine hostlessness (`PHP_SAPI === 'cli'` or no `HTTP_HOST`) and on `WP_CLI`,
never on `DOING_CRON`.

**The web server or CDN must enforce the equivalent rule for static files.** PHP never
runs for `/wp-content/uploads/x.jpg`, so an unknown host still fetches assets
regardless of this guard. `Http\ServerConfig` generates the matching nginx / Apache /
Cloudflare rule; the plugin cannot apply it.

### 5.4 Request lifecycle

```
post-domain.php
  ├─ require vendor/autoload.php                      (1) autoloader first
  ├─ register_activation_hook( … Activation::guard )  (2) wp_die() on multisite
  ├─ if ( is_multisite() ) { admin notice; return; }   (3) inert thereafter
  ├─ PHP < 8.1 | WP < 6.4 -> admin notice, no hooks
  └─ Plugin::boot()

plugins_loaded : 0     HostContextFactory
                         AuthorityParser → allowlist → IdnaNormalizer → HostKind
                         HostPolicy frozen
plugins_loaded : 1     UnknownHostGuard    →  400 / 421 / pass
plugins_loaded : 2     AdminRedirect       →  302 (GET/HEAD) | 307 (other), AJAX exempt
plugins_loaded : 10    Url adapters, Cors, Branding registered UNCONDITIONALLY
                       (each no-ops when serving() is null — this is what makes
                        pd_with_mapping() work in cron and CLI)
plugins_loaded : 11    ServingEligibility frozen

init : 10              integrator registers post types, statuses, filters
init : 99              ContentPolicy frozen → ServingContext published
                       Disposition computed
init (rest_api_init)   ManagementController registered ONLY when kind === PRIMARY

parse_request : 0      MappedHostGuard enforces the Disposition
parse_request : 1      Resolver (serving && endpoint === ROUTED)
                         PathDecomposer → RoutingContract::resolve_path
                         HIT       → query vars + allowlisted preserved vars
                         AMBIGUOUS → treated as MISS
                         MISS      → UnmatchedPolicy
parse_request : 5      QueryScope bounds feeds and sitemaps

template_redirect : 0  RedirectCanonicalGuard filters core's proposal
wp_head                RelCanonical (computed fresh), Branding
```

---

## 6. Routing contract

```php
interface RoutingContract {
    public function resolve_path( Mapping $m, string $path ): ?Resolution;
    public function path_for_post( Mapping $m, WP_Post $post ): ?string;
    public function belongs_to_mapping( Mapping $m, WP_Post $post ): bool;
}

final class Resolution {
    public readonly int    $post_id;
    public readonly string $post_type;
    public readonly int    $depth;
    public readonly string $canonical_path;
}
```

**Invariant:** for every `$p` where `belongs_to_mapping()` is true and
`path_for_post()` is non-null,
`resolve_path( $m, path_for_post( $m, $p ) )->post_id === $p->ID`.
A post that cannot round-trip **must** return `null` from `path_for_post()` — never a
path the resolver would not accept. Asserted as a property over a generated fixture
tree.

### 6.1 Path normalization

Strip query and fragment → reject `%2F` and `%5C` → `rawurldecode` each segment
individually → reject any segment that is `.` or `..` → collapse repeated slashes →
strip leading and trailing slash → NFC → compare against `rawurldecode( post_name )`.
Depth capped by `max_depth` (clamped 1–25) so a parent cycle cannot hang a request.
Resolution accepts both trailing-slash forms; generation emits whatever
`user_trailingslashit()` dictates.

### 6.2 Collisions are ambiguous, not arbitrated

Two siblings can share a `post_name` once a second post type joins the subtree. When
more than one candidate matches a segment:

- `resolve_path()` returns `null` (handled as unmatched)
- `path_for_post()` returns `null` for **every** colliding candidate, so all of them
  fall back to primary-host permalinks
- `Admin\Diagnostics` lists the collision
- `pd_resolve_ambiguity` lets an integrator arbitrate; the shipped default arbitrates
  nothing

### 6.3 Round-trip enforcement at generation time

`Url\Adapters\CoreLinks` does not emit a rebased permalink on trust. Before emitting,
it computes `path_for_post()`, feeds it back through `resolve_path()`, and requires the
same post ID. On mismatch it emits the **primary-host permalink** — a correct URL on
the wrong domain beats a wrong URL on the right one.

Verification is **mandatory**; there is no filter to disable it. The result is memoized
per request keyed on `mapping_id : effective_root_id : post_id` — a memo of a pure
function within one request, not a cache: there is no window in which inputs change
and a stored answer persists.

---

## 7. URL generation

### 7.1 Policy and adapters

```php
final class UrlPolicy {
    public function rebase( string $url, ServingContext $ctx, UrlKind $kind ): string;
    public function is_rebasable_path( string $path, Mapping $m ): bool;
}
enum UrlKind { case HOME; case PERMALINK; case TERM; case REST; case AJAX;
               case FEED; case COMMENT; case EMBED; case SITEMAP; case ASSET; case MAIL; }
```

A URL is rebased only when it is currently on the primary host **and** its path is
either inside the mapping's subtree or an infrastructure path. Everything else is
returned untouched, including admin URLs.

### 7.2 Core compatibility matrix

Shipped as a machine-readable list in `Url\Compatibility` that the integration suite
iterates, so the matrix and the tests cannot drift.

| Surface | Hook | Asserted on rendered output |
|---|---|---|
| `home_url()` | `home_url` | yes |
| `site_url()` | `site_url` | yes — asserted **not** rebased |
| `get_permalink()` | `post_link`, `page_link`, `post_type_link` | yes |
| Attachments | `attachment_link` | yes |
| Terms | `term_link` | yes |
| REST root | `rest_url` | yes |
| admin-ajax | `admin_url` (that one file only) | yes |
| Comments | `comment_form_defaults`, `comment_post_redirect` | yes |
| Feeds | `feed_link`, `post_comments_feed_link` | yes |
| Embeds | `oembed_response_data`, `embed_html` | yes |
| Sitemaps | `wp_sitemaps_*` | yes |
| Canonical | §7.4 | yes |
| Shortlink | `get_shortlink` | yes |
| `get_option('home')` | `pre_option_home` | **opt-in only**, default off |

**The honest limit:** the plugin intercepts the surfaces above. A plugin that hardcodes
a domain, caches an absolute URL into its own table, or reads `WP_HOME` directly is not
interceptable, and the README says so rather than promising completeness.
`Admin\Diagnostics` renders a mapped page and reports any absolute primary-host URL
found in the output — detection instead of a false guarantee.

`pre_option_home` is **default off** (`pd_filter_home_option`). It fires for everything
that reads the option, including code paths that must stay on the primary host, and it
is the classic way to corrupt cron and email. When enabled it applies only to mapped,
non-admin, non-CLI, non-cron front-end requests.

### 7.3 Comments

The form action is set via `comment_form_defaults['action']` and kept on the **mapped
host**, so the visitor never leaves the domain to comment; `comment_post_redirect`
returns a mapped-host URL. Comment-author cookies are therefore per-host — a visitor
known on the primary site is anonymous on the mapped one. Documented, not worked
around.

### 7.4 Canonical

```php
final class CanonicalPolicy {
    public function for_request( HostContext $h, ?ServingContext $s, WP_Query $q ): ?CanonicalUrl;
}
```

Pure, computed per request, **never cached** — the policy has no persistence layer by
construction, so there is nowhere for a stale answer to live.

Adapters: `RelCanonical` (the `wp_head` tag), `EmbedCanonical`, and
`RedirectCanonicalGuard`, which **filters** core's `redirect_canonical` proposal rather
than removing the action: if the proposal is the primary-host permalink of a post inside
the current subtree it is rewritten to the mapped equivalent; if the result equals the
current URL it returns `false`; otherwise core's proposal stands, so trailing-slash,
pagination, and case corrections keep working.

### 7.5 Background context

Cron, CLI, and `wp_mail()` have no host. There is no guessing.

```php
function pd_with_mapping( int $mapping_id, callable $fn ): mixed;
```

Cron events carry the mapping id in their args. WP-CLI accepts `--pd-host=`. Mail
generated inside a mapped request inherits that request's context; mail generated
outside one gets primary-host URLs unless wrapped. Defaulting to primary is the only
safe answer — a wrong host in an email is unrecallable.

---

## 8. CORS, assets, and the hosting boundary

PHP can set headers on responses **WordPress produces**. It cannot set headers on a
`.woff2` that nginx, Apache, or a CDN serves directly.

1. `Http\Cors` hooks `send_headers` on whichever host serves the request, reads
   `Origin`, and emits `Access-Control-Allow-Origin: <that exact origin>` +
   `Vary: Origin` **only** when the origin is a verified, active mapped host. Never
   `*`, never echoed unvalidated. The header must come from the host serving the
   **asset** (normally the primary host) and must authorize the **requesting** origin.
2. `Http\ServerConfig` **generates** the nginx `location`, Apache `<FilesMatch>`, and
   Cloudflare Transform Rule for statically-served assets. It does not apply them.
3. `Http\AssetProxy` is opt-in, off by default: extension allowlist, `realpath()`
   containment against an allowlisted root
   (`str_starts_with( $real, $root . DIRECTORY_SEPARATOR )`), deny when `realpath()`
   fails, no symlink escape, no user-supplied root.
4. **No server-side diagnostic fetches exist anywhere in the plugin.** The CORS probe
   is a hidden iframe pointing at `https://<mapped-host>/.well-known/post-domain-probe`,
   a plugin-served `WELL_KNOWN` route. It executes on the **mapped origin**, fetches the
   sample asset, and `postMessage`s the result back; both ends check `event.origin`.

### 8.1 Strict `Origin` parsing

Matched against an anchored grammar —
`^(https?)://([a-z0-9._~%-]+|\[[0-9a-fA-F:.]+\])(:\d{1,5})?$` — with no path, query,
fragment, userinfo, or trailing slash permitted, and the literal `null` rejected. The
authority portion is then handed to `AuthorityParser` on the same terms as a `Host`
header, and scheme and port must match what the mapping is served on.

---

## 9. Authentication consequences

WordPress auth cookies bind to `COOKIE_DOMAIN`, the primary host. On a mapped host
there is **no logged-in cookie and no valid nonce**. This is a property of the design;
the plugin does not attempt cross-domain sessions.

- `REST_CONTENT` on a mapped host is **anonymous**. Public reads work; authenticated
  writes do not.
- `AJAX` reaches `wp_ajax_nopriv_*` handlers only, and is exempt from the admin
  redirect precisely so public ajax keeps working. Privileged ajax must be issued from
  the primary host.
- `ADMIN` and `LOGIN` redirect to the primary host before any cookie or session work.
- `REST_MANAGEMENT` is **registered only when `HostContext::kind === PRIMARY`**, so on
  every other host the routes do not exist: dispatch 404s natively and the namespace is
  absent from `/wp-json/` discovery.

**The admin redirect is a default policy, not an invariant.** `pd_admin_redirect` can
disable it and `pd_admin_redirect_target` can retarget it. What is invariant is the
cookie boundary underneath. Disabling the redirect yields a login form on the mapped
host that renders and can never authenticate; the README states that at the filter and
Diagnostics flags the configuration. A site that fronts `wp-admin` differently is
entitled to make that call.

---

## 10. Query scope

`QueryScope` is **optimization only**. Membership is enforced over every returned feed
and sitemap post, `post__in` scopes included.

```php
final class QueryScope {
    public readonly bool   $is_bounded;
    public readonly ?array $post__in;
    public readonly ?array $post_parent__in;
    public readonly array  $query_args;
}
interface QueryScopeProvider { public function scope( Mapping $m, ServingContext $ctx ): QueryScope; }
```

- Every scope sets `ignore_sticky_posts => true` — a sticky post is injected from
  outside the subtree by definition.
- An empty `post__in` or `post_parent__in` **short-circuits to an empty result and the
  query never runs**. An empty inclusion array is silently ignored by `WP_Query`, which
  turns "nothing matches" into "everything matches."
- **Every post is validated with `belongs_to_mapping()` before output** — `the_posts`
  for feeds, per-entry for sitemaps. Non-members are removed after the query.
- Provider chain: `pd_query_scope` filter → `CteSubtreeAdapter` (its own class, its own
  integration tests, gated behind an explicit DB capability probe, returning post IDs) →
  enumeration under `pd_scope_enumeration_limit` → `is_bounded = false` (empty).
- Raw JOIN/WHERE is **not** part of the public surface. An integrator with a cheap
  constraint expresses it as `query_args`.

Result-level validation can make a page return fewer items than `found_posts` reports;
no over-fetch is attempted. Accepted and documented: feeds and sitemaps are bounded
lists, and under-reporting a count is preferable to emitting a post from outside the
subtree.

---

## 11. Filter surface

### 11.1 Invariants no filter can override

1. **Verification is plugin-owned and stored.** No filter supplies a mapping and no
   filter can assert `VERIFIED`. Host → mapping is always the stored row.
2. **Auth cookies never span hosts.**
3. **Management REST is registered only on the primary host.**
4. **Canonical is computed per request.** No filter installs a cache.
5. **A URL is emitted only if it round-trips.** Enforced at generation.
6. **`AuthorityParser` and `UnknownHostGuard` positions are not filterable.** Only the
   allowlist is data.
7. **Provider mutations require a held lease and a fresh, installation-bound
   authorization** (§14.4–14.5). No filter grants either.
8. **Ownership provenance is column state, never derived from events.** No filter
   writes it.

### 11.2 Mapping

| Filter | Signature → default |
|---|---|
| `pd_mapping_is_active` | `(bool, Mapping, HostContext)` → `activation_state === ACTIVE`. Once per request (Phase B). Strictly `$stored && (bool) $filtered` — veto only. |
| `pd_target_post_for_host` | `(int, Mapping)` → `$m->post_id`. Once per request (Phase C). Invalid ⇒ 503, never a silent fallback. |

### 11.3 Subtree — the reversible pair

| Filter | Signature → default |
|---|---|
| `pd_subtree_post_types` | `(array, Mapping)` → `[ get_post_type( $m->post_id ) ]` |
| `pd_post_statuses` | `(array, Mapping)` → `['publish']` |
| `pd_subtree_children` | `(?WP_Post[], WP_Post $parent, Mapping)` → `null` |
| `pd_path_segment_for_post` | `(string, WP_Post, Mapping)` → `$post->post_name` |
| `pd_resolve_path` | `(?Resolution, string $base, Mapping)` → `null` |
| `pd_path_for_post` | `(?string, WP_Post, Mapping)` → `null` |
| `pd_belongs_to_mapping` | `(?bool, WP_Post, Mapping)` → `null` |
| `pd_resolve_ambiguity` | `(?WP_Post, Mapping, WP_Post[], string)` → `null` |
| `pd_max_subtree_depth` | `(int)` → `10` |

`pd_path_segment_for_post` is the cheap answer for most integrations — override one
function and both directions follow. `pd_resolve_path` + `pd_path_for_post` are the
escape hatch for content models that are not a `post_parent` tree; overriding one
without the other is the documented mistake.

### 11.4 Host and request

| Filter | Signature → default |
|---|---|
| `pd_allowed_infrastructure_hosts` | `(string[])` → `PD_ALLOWED_HOSTS` or `[]` |
| `pd_unknown_host_policy` | `(string)` → `'421'` |
| `pd_trusted_proxies` | `(string[])` → `PD_TRUSTED_PROXIES` or `[]` |
| `pd_endpoint_class` | `(EndpointClass, string, array)` → derived |
| `pd_preserved_query_vars` | `(string[])` → `['paged','page','cpage','replytocom','feed','embed']` |
| `pd_unmatched_policy` | `(string)` → `'redirect'` |
| `pd_admin_redirect` | `(bool)` → `true` |
| `pd_admin_redirect_target` | `(string, HostContext)` → primary host, same path+query |
| `pd_xmlrpc_on_mapped_hosts` | `(bool)` → `false` |

### 11.5 URL and canonical

| Filter | Signature → default |
|---|---|
| `pd_rebase_url` | `(?string, string, ServingContext, UrlKind)` → `null` |
| `pd_is_rebasable_path` | `(bool, string, Mapping)` → derived |
| `pd_link_host` | `(string, UrlKind, ServingContext)` → `requested_host`; canonical kinds → `canonical_host` |
| `pd_filter_home_option` | `(bool)` → `false` |
| `pd_canonical_url` | `(?CanonicalUrl, HostContext, ?ServingContext, WP_Query)` → computed |
| `pd_canonical_host` | `(string, Mapping)` → `canonical_host` |

### 11.6 Scope, verification, SSL, CORS, REST, branding

| Filter | Signature → default |
|---|---|
| `pd_query_scope` | `(QueryScope, Mapping, ServingContext)` → provider chain |
| `pd_scope_enumeration_limit` | `(int)` → `500` |
| `pd_txt_record_label` | `(string, Mapping)` → `'_post-domain-challenge'` — **one label**; the host suffix is appended internally and is invariant |
| `pd_verification_grace` | `(int)` → `3` |
| `pd_verification_schedules` | `(array)` → pending 15 min / verified daily / transient 30 min |
| `pd_doh_endpoints` | `(string[])` → Cloudflare + Google |
| `pd_dns_resolver` | `(DnsResolver)` → `DohResolver` — **trusted code**, see §13.3 |
| `pd_ssl_drivers` | `(SslDriver[])` → `[NullDriver, CloudflareSaasDriver]` |
| `pd_ssl_validation_method` | `(string, Mapping)` → configured method, default `'txt'` |
| `pd_apex_capability` | `(ApexCapability, string $host, Mapping)` → driver-derived, typed (§14.12) |
| `pd_mutation_lease_ttl` | `(int seconds)` → `120` |
| `pd_authorization_ttl` | `(int seconds)` → `120`, never beyond the held lease |
| `pd_cors_allowed_origin` | `(?string, string, HostContext)` → the origin if verified+active |
| `pd_asset_proxy_enabled` | `(bool)` → `false` |
| `pd_asset_proxy_extensions` | `(string[])` → `['woff2','woff','ttf','otf','eot']` |
| `pd_rest_capability` | `(string, string $route)` → `'manage_options'` |
| `pd_site_title` | `(string, Mapping)` → mapped post title |
| `pd_favicon_url` | `(?string, Mapping)` → per-mapping setting, else site icon |
| `pd_event_retention_days` | `(int)` → `90` |
| `pd_sweep_budget_seconds` | `(int)` → `20` |

### 11.7 Actions

`pd_mapping_created`, `pd_mapping_verified`, `pd_mapping_verification_failed`
(with `DnsOutcome`), `pd_mapping_deleted`, `pd_ssl_state_changed`,
`pd_ssl_resource_adopted`, `pd_ssl_create_recovered`, `pd_ssl_mutation_refused`
(with the failing precondition), `pd_ssl_method_changed`, `pd_request_resolved`,
`pd_request_unmatched`, `pd_environment_mismatch_detected`.

Actions are notifications. Nothing in the plugin reads an action's effects, and no
authorization consults one.

### 11.8 Hard postconditions

Every security-sensitive filter's return value is re-validated and clamped. Violations
are ignored and logged, never honoured.

| Filter | Postcondition |
|---|---|
| `pd_endpoint_class` | `PROTECTED = {ADMIN, LOGIN, AJAX, REST_MANAGEMENT, REST_CONTENT, COMMENT_POST, TRACKBACK, XMLRPC, CRON_HTTP, INFRASTRUCTURE, ASSET}`. If the pre-filter class is protected the result **must equal** it; if the post-filter class is protected the result is **rejected**. Reclassification only among `{ROUTED, WELL_KNOWN, SITEMAP}`. |
| `pd_preserved_query_vars` | Intersected with `/^[a-z0-9_]{1,32}$/`, then `RESERVED` subtracted unconditionally: `p, page_id, name, pagename, post_type, attachment, attachment_id, static, error, preview, preview_id, preview_nonce, post_status, rest_route` plus every var the resolver sets. |
| `pd_is_rebasable_path`, `pd_rebase_url` | `PROTECTED_PATHS = {wp-admin/, wp-login.php, wp-signup.php, wp-activate.php, xmlrpc.php, wp-cron.php, <rest-prefix>/post-domain/v1/}` forced not rebasable, with `EXEMPT = {wp-admin/admin-ajax.php}` as an **exact** match checked first. A rebased URL must be absolute, `http`/`https`, and its host permitted by `pd_link_host`; otherwise the original is returned. |
| `pd_link_host`, `pd_canonical_host` | `HostValue` validation: bare host, no scheme, no path, port only if it matches the request's; parsed by `AuthorityParser`, normalized, and must be `requested_host` or `canonical_host`. |
| `pd_admin_redirect_target`, `pd_canonical_url` | `AbsoluteUrl` validation: absolute, scheme in `{http,https}`, no userinfo, no control characters, authority parses and normalizes, host in the permitted set (`pd_admin_redirect_target`: primary or allowed-infrastructure; `pd_canonical_url`: primary, requested, or canonical). **No scheme downgrade** — an HTTPS request may not yield an HTTP result. |
| `pd_cors_allowed_origin` | `null`, or byte-identical to the validated request `Origin` whose host is a `VERIFIED` + active mapping with matching scheme and port. `*`, a different origin, or a list ⇒ no header. |
| `pd_asset_proxy_extensions` | Intersected with the hardcoded maximum `{woff2, woff, ttf, otf, eot}` — narrowing only. `svg` is absent by construction. |
| `pd_trusted_proxies` | Each entry must parse as a valid IP or CIDR; invalid dropped; empty ⇒ forwarded headers ignored. |
| `pd_allowed_infrastructure_hosts` | Each entry parsed by `AuthorityParser`; entries that fail, carry a port, or contain `*` are dropped. Comparison is lowercased and exact; IPv6 entries compared in bracketed form. |
| `pd_max_subtree_depth` | Clamped `1..25`. |
| `pd_scope_enumeration_limit` | Clamped `0..5000`. |
| `pd_query_scope` | Must return a `QueryScope`. A non-`QueryScope`, or `is_bounded = true` with no constraint, is replaced with `is_bounded = false`. Unbounded is never reachable. |
| `pd_txt_record_label` | Matches `/^_?[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i`, 1–63 bytes, no dot, lowercased; else the default. Validated at create/rotate only. |
| `pd_ssl_validation_method` | Must be one of `{http, txt, email}` **and** present in the driver's `DriverCapabilities::$validation_methods`; else the configured default. |
| `pd_apex_capability` | Must return an `ApexCapability`. `APEX_PROXY` additionally requires a non-empty `targets` array of valid IP literals, a `target_provenance` in `{static_ip_prefix, byoip}`, and `operator_attested === true`; anything short of that is downgraded to `UNSUPPORTED` and a `DnsBlocker` is emitted. Entitlement is never inferred from the mere presence of address strings. |
| `pd_mutation_lease_ttl` | Clamped `30..600` seconds, and additionally floored at the driver's provider HTTP timeout plus the documented safety margin, so recovery can never begin while the original request is still legitimately in flight. |
| `pd_authorization_ttl` | Clamped `30..300` seconds, and further clamped to the remaining lease lifetime. |
| `pd_unknown_host_policy`, `pd_unmatched_policy` | Must be a declared enum member; else the default. |
| `pd_rest_capability` | Non-empty string; else `manage_options`. |
| `pd_mapping_is_active` | Cast to bool and ANDed with stored state. |

---

## 12. Data model

### 12.1 `{$wpdb->prefix}pd_domains`

```
id                       BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT
host                     VARCHAR(230)      NOT NULL      -- ascii / ascii_bin
alias_of                 BIGINT UNSIGNED   NULL
post_id                  BIGINT UNSIGNED   NULL
revision                 INT UNSIGNED      NOT NULL DEFAULT 1

verification_state       VARCHAR(20)       NOT NULL DEFAULT 'unverified'
activation_state         VARCHAR(20)       NOT NULL DEFAULT 'inactive'
ssl_state                VARCHAR(20)       NOT NULL DEFAULT 'none'
integrity_error          VARCHAR(60)       NULL

challenge                CHAR(32)          NOT NULL      -- ascii / ascii_bin
challenge_label          VARCHAR(63)       NOT NULL
challenge_rotated_at     DATETIME          NULL
verified_at              DATETIME          NULL
last_checked_at          DATETIME          NULL
last_outcome             VARCHAR(20)       NULL
hard_failure_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0
transient_failure_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0
verification_deadline    DATETIME          NULL
verify_next_attempt_at   DATETIME          NULL
verify_lease_token       CHAR(32)          NULL          -- also the attempt id
verify_lease_expires_at  DATETIME          NULL
resolver_class           VARCHAR(191)      NULL

ssl_provider             VARCHAR(60)       NULL
ssl_provider_environment VARCHAR(190)      NULL          -- environment the bound resource lives in
ssl_ref                  VARCHAR(191)      NULL
ssl_ownership_origin     VARCHAR(10)       NULL          -- 'created' | 'adopted' | NULL
ssl_owner_installation_id CHAR(36)         NULL
ssl_adopted_at           DATETIME          NULL
ssl_adopted_by           BIGINT UNSIGNED   NULL
ssl_method               VARCHAR(10)       NULL          -- persisted DCV method
ssl_method_requested_at  DATETIME          NULL
ssl_marker_support       VARCHAR(20)       NULL          -- supported|unavailable|unknown
ssl_checked_at           DATETIME          NULL
ssl_next_attempt_at      DATETIME          NULL
ssl_transient_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0
ssl_provider_state       TEXT              NULL          -- JSON, raw axes + error arrays
ssl_error                TEXT              NULL          -- JSON {code,message,at}

ssl_mutation_token       CHAR(32)          NULL          -- provider-mutation lease
ssl_mutation_kind        VARCHAR(20)       NULL          -- create|adopt|method|remove
ssl_mutation_phase       VARCHAR(20)       NULL          -- reserved|in_flight|recovering
ssl_mutation_expires_at  DATETIME          NULL
ssl_mutation_driver      VARCHAR(60)       NULL          -- driver id this mutation began against
ssl_mutation_environment VARCHAR(190)      NULL          -- non-secret provider environment identity

deletion_requested_at    DATETIME          NULL
deletion_attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0
deletion_next_attempt_at DATETIME          NULL

title                    VARCHAR(255)      NULL
favicon_attachment_id    BIGINT UNSIGNED   NULL
created_at               DATETIME          NOT NULL
updated_at               DATETIME          NOT NULL
created_by               BIGINT UNSIGNED   NULL

PRIMARY KEY  (id)
UNIQUE KEY host (host)
UNIQUE KEY challenge (challenge)
KEY post_id (post_id)
KEY alias_of (alias_of)
KEY verify_due   (verification_state, verify_next_attempt_at)
KEY ssl_due      (ssl_state, ssl_next_attempt_at)
KEY deletion_due (deletion_next_attempt_at)
KEY ssl_lease    (ssl_mutation_expires_at)
```

`ENGINE=InnoDB`; the actual engine is probed at install and stored in
`pd_schema_engine`.

**`host` is 230, not 253** — the TXT-length constraint made structural. The record name
is `{challenge_label}.{host}` and must fit 253 bytes; the default label
`_post-domain-challenge` is 22 bytes, so `253 − 22 − 1 = 230`. Insert-time validation
additionally checks the *composed* name against the *actual* label, so a longer custom
label rejects a host that would overflow it.

`host` and `challenge` are `CHARACTER SET ascii COLLATE ascii_bin`. Binary collation
because lookup must be exact-match and case normalization already happened in
`IdnaNormalizer`; a case-insensitive collation would let `EXAMPLE.COM` match a row it
was never normalized into.

No `CHECK` constraints (unreliable across MySQL 5.7 / 8 / MariaDB). Validity is
enforced in PHP at a single write path, `DbRepository::save()`, the only code that
touches the table.

**Row invariants:**

```
alias_of IS NULL      =>  post_id IS NOT NULL        (canonical row)
alias_of IS NOT NULL  =>  post_id IS NULL            (alias row)
alias target must itself have alias_of IS NULL       (no chaining)

ssl_ownership_origin IS NOT NULL
   <=> ssl_owner_installation_id IS NOT NULL
   <=> ssl_ref IS NOT NULL
ssl_ownership_origin = 'adopted'  =>  ssl_adopted_at IS NOT NULL
ssl_ownership_origin = 'created'  =>  ssl_adopted_at IS NULL
ssl_mutation_token IS NULL
   =>  ssl_mutation_kind IS NULL
   AND ssl_mutation_phase IS NULL
   AND ssl_mutation_expires_at IS NULL
ssl_mutation_token IS NOT NULL
   =>  ssl_mutation_kind IS NOT NULL
   AND ssl_mutation_phase IS NOT NULL
   AND ssl_mutation_expires_at IS NOT NULL
ssl_mutation_kind  IN ('create','adopt','method','remove')
ssl_mutation_phase IN ('reserved','in_flight','recovering')
```

`alias_of` is a self-reference with no FK (`dbDelta` cannot express one portably);
orphan cleanup runs in the repository on delete, and a scheduled integrity check
reports strays.

### 12.2 Ownership provenance

There is exactly one source of truth for whether this installation may mutate a
provider resource, and it is column state on the row:

```php
enum OwnershipOrigin { case CREATED; case ADOPTED; }
```

| Column | Meaning |
|---|---|
| `ssl_ownership_origin` | `created` — this installation created the resource; `adopted` — this installation explicitly adopted it; `NULL` — **no ownership authority** |
| `ssl_owner_installation_id` | the `pd_installation_id` that created or adopted the binding |
| `ssl_adopted_at` / `ssl_adopted_by` | when and by whom an adoption happened |

**Authority** is `ssl_ownership_origin IS NOT NULL` **and**
`ssl_owner_installation_id === pd_installation_id`. Nothing else establishes it. There
is no boolean flag duplicating this — a second source of truth is precisely what makes
ownership drift — and there is **no query against the event table in any authorization
decision**. Pruning an event can never change what a mapping is allowed to do.

Successful creation persists `origin = created` with the current installation id.
Successful explicit adoption persists `origin = adopted` with the current installation
id. Clone resolution clears all four columns; restore/move retains them (§14.8).

### 12.3 `{$wpdb->prefix}pd_domain_events`

```
id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
domain_id   BIGINT UNSIGNED NOT NULL
host        VARCHAR(230)    NOT NULL     -- snapshot; rows are eventually hard-deleted
type        VARCHAR(40)     NOT NULL     -- verification | ssl | activation | admin | environment
from_state  VARCHAR(20)     NULL
to_state    VARCHAR(20)     NULL
actor       VARCHAR(60)     NULL         -- 'rest:12' | 'cron' | 'cli' | 'admin:3'
detail      LONGTEXT        NULL         -- JSON: outcome, resolver_class, attempt id,
                                         --       provider ref, identity verdict,
                                         --       failing authorization precondition
created_at  DATETIME        NOT NULL
PRIMARY KEY (id)
KEY domain_created (domain_id, created_at)
```

Every state transition, every adoption, every recovered create, and every refused
mutation writes one row. This is the **support artifact** — "it stopped working on
Tuesday" becomes answerable, and `resolver_class` makes it visible which code performed
each ownership proof.

Retention is 90 days, pruned daily. **Events are never decision inputs.** Nothing in
authorization, routing, or state transition reads this table, so pruning is always safe.

**Atomicity:** when `pd_schema_engine` is InnoDB, the state change and its event row are
written in one transaction. On any other engine the transaction is skipped and the
event log is **best-effort** — it can lag or miss rows, which is tolerable precisely
because nothing reads it.

**Ambient transactions.** WordPress core opens no transactions, but another plugin or an
embedding application may have one open on the same connection, and on MySQL and MariaDB
a `START TRANSACTION` issued inside one **implicitly commits** it. This plugin therefore
never commits or rolls back a transaction it does not own. Before starting one it probes
the session — `SAVEPOINT` followed by `RELEASE SAVEPOINT`, which succeeds inside a
transaction and fails with "savepoint does not exist" outside one, needs no privilege, and
neither commits nor rolls anything back in either state. Three outcomes:

| Session | Behaviour |
|---|---|
| no transaction open | own the whole lifecycle: start, transition, event, commit or roll back |
| a transaction already open | **refuse before running the transition**; report it and leave the ambient transaction untouched |
| the probe itself fails | **refuse before running the transition** — an undetectable session is not a safe one |

An unconfirmed `COMMIT` is a third case and a different one: the write may or may
not have landed, and the connection that issued it cannot find out, because while
its transaction is unresolved it is shown its own uncommitted work. Nothing is
reported as success, no provider call is repeated, and the outcome is settled by a
later request, cron pass, or reconciliation — each on a connection with a
committed view.

Refusing rather than nesting via savepoints is deliberate. A savepoint released inside
somebody else's transaction leaves the plugin's write undurable until that owner commits,
so reporting it as committed would be a lie; and rolling back to a savepoint on failure
would still leave the caller's transaction in a state the plugin did not choose. A refusal
costs one deferred attempt, which the lease and the recovery pass already handle.

A refusal is not a lost CAS: nothing was attempted, the lease is untouched, and the next
pass retries. Nothing else in the plugin opens a transaction, so this condition is
expected to be vanishingly rare in practice; it is handled because the failure mode if it
were ignored is another plugin's half-written transaction being committed.

### 12.4 Timestamps and storage hygiene

All `DATETIME` columns are **UTC**, written with `gmdate('Y-m-d H:i:s')`.
`current_time()` is never called — a site-local timestamp in a scheduling column
produces silent off-by-hours behaviour across DST. REST emits RFC 3339 with `Z`.

`ssl_error` is JSON `{code, message, at}`; the message is truncated to 500 bytes and
passed through a redactor stripping bearer-token, API-key, and `Authorization:` shapes.
Raw provider bodies are never stored. Credentials never appear in a row, a response, an
event, or a log line. Mutation authorizations are in-process values only: never
persisted, never serialized into an event, never logged. The lease token **is**
persisted, because the database is where single-use enforcement lives (§12.6).

### 12.5 Compare-and-swap

```sql
UPDATE … SET …, revision = revision + 1, updated_at = ?
WHERE id = ? AND revision = ?
```

Zero affected rows ⇒ `pd_conflict` (409) for REST; bounded re-read-and-retry (3
attempts) for cron and CLI. REST exposes `revision` and `ETag: "<id>-<revision>"`.
`If-Match` is **required** on `PATCH`, `DELETE`, `POST /challenge`, and any `…/ssl`
mutation: missing ⇒ `428`, stale ⇒ `412`. `POST /verify` is exempt (idempotent probe).

CAS alone is sufficient for local writes. It is **not** sufficient for a provider
mutation, because between the last CAS read and the outbound HTTP call another worker
can change the row, and the driver — holding only a value object — cannot see it. That
gap is closed by the lease.

### 12.6 The provider-mutation lease

A persisted, token-owned, per-mapping lease with **explicit phases**. The phase
transition into `IN_FLIGHT` happens in the database **before** any provider mutation is
sent, and that transition — not the later result write — is the point at which an
authorization is consumed.

```php
enum MutationKind  { case CREATE; case ADOPT; case METHOD_CHANGE; case REMOVE; }
enum MutationPhase { case RESERVED; case IN_FLIGHT; case RECOVERING; }

final class MutationLease {
    public readonly int    $mapping_id;
    public readonly int    $revision;      // the revision AFTER the transition that produced it
    public readonly string $token;         // 32 random hex
    public readonly MutationKind  $kind;
    public readonly MutationPhase $phase;
    public readonly DateTimeImmutable $expires_at;
}
```

Every lease column moves together. When `ssl_mutation_token` is `NULL`, `kind`, `phase`,
`expires_at`, `driver`, and `environment` are all `NULL`; when it is set, all five are
present, and only declared `MutationKind` and `MutationPhase` values are accepted by the
repository.

#### The provider environment a resource lives in

`ssl_provider` says which **driver** owns a bound resource. It does not say which
**account, zone, or endpoint** that resource lives in, and a driver id is not an
environment: one Cloudflare driver can be pointed at a different zone tomorrow.
Reading provider state through current configuration is therefore unsafe for a
bound resource, not only for a mutation in flight:

1. A custom hostname is created in zone A.
2. An operator repoints the plugin at zone B.
3. `Reconciler` resolves `cloudflare-saas` from current configuration.
4. It asks zone B about a resource that lives in zone A.
5. Zone B says "absent", and that is recorded as truth about zone A's resource.

Being unleased does not make that read safe. It only means no mutation is in
flight. The row therefore carries its own answer, for the whole life of the
binding:

**`ssl_provider_environment`** — the non-secret `SslDriver::environment_id()` of
the environment the bound resource actually lives in.

Its lifecycle is exact:

| Moment | Effect |
|---|---|
| no provider resource bound | `NULL`, with `ssl_provider` and `ssl_ref` |
| create finalized successfully | written in the **same CAS** as `ssl_provider` and `ssl_ref`, promoted from `ssl_mutation_environment` |
| adoption finalized successfully | likewise |
| create or adoption recovered | likewise, promoted from the lease's binding, never from current configuration |
| status, identify, reconcile, method change, deletion attempts | **retained unchanged** |
| provider binding deliberately cleared | cleared with `ssl_provider`, `ssl_ref`, and ownership provenance |
| clone resolution (§14.9) | cleared with them |
| mapping deleted | gone with the row |

It is **never** derived from the event log and **never** silently replaced by
current configuration.

**The rule for every access to a bound resource.** Resolve the driver named by
`ssl_provider`, compare its current `environment_id()` with
`ssl_provider_environment`, and if they differ — or the driver is not registered —
then:

- perform **no provider read and no provider mutation**;
- change no local provider state;
- report a named configuration-drift condition identifying the driver and the
  environment that must be restored;
- treat an absence, a difference, or any other answer from the currently
  configured environment as **evidence about nothing**.

This governs `status()`, `identify()`, `reconcile()`, validation planning wherever
it consults provider state, adoption of an already-identified resource, method
changes, deletion authorization, deletion execution, recovery, Admin, REST, CLI,
and Diagnostics.

**How the two bindings relate.** The mutation lease binds the environment a
mutation *began against*; the resource binding records the environment a resource
*lives in*. A first create or an unbound adoption has no resource binding yet, so
the lease binds the currently selected environment, and successful finalization
**promotes** it. Every later mutation against an existing resource works the other
way round: `ssl_mutation_driver` comes from the stored `ssl_provider`,
`ssl_mutation_environment` comes from the stored `ssl_provider_environment`, and
the currently configured driver must match **both** before the lease is acquired
or the provider is touched. No current default setting may reinterpret a bound
resource.

#### The provider environment a mutation began against

`ssl_provider` records which driver owns a *finished* resource. It cannot answer the
question recovery actually has to ask, because during a first create or an adoption it is
still `NULL` while the request is already in flight. Between the request and the
recovery pass an operator may change the selected driver, or rotate an API token to one
that reaches a different account, or point the plugin at a different zone. Resolving the
driver again from current configuration would then let recovery interrogate a *different*
provider environment and treat its answer as truth about the original mutation — and a
conclusive "absent" from the wrong environment is exactly the answer that clears the lease
and permits a duplicate mutation while the original resource still exists.

The lease therefore carries its own durable answer:

- **`ssl_mutation_driver`** — the exact driver id the mutation began against.
- **`ssl_mutation_environment`** — a **non-secret, stable** identity for the provider
  environment that driver was configured for, supplied by `SslDriver::environment_id()`.

Both are written during `RESERVED` acquisition, **before** any provider call, are pinned
in the `RESERVED → IN_FLIGHT` consumption CAS alongside every other bound value, survive
`IN_FLIGHT → RECOVERING` and every recovery takeover unchanged, and are cleared only by
the same transitions that clear the rest of the lease: successful reservation cleanup,
finalization, or row deletion.

`environment_id()` must distinguish configurations that hold different resources —
different Cloudflare accounts or zones, different API endpoints — and must **never**
encode a credential. It is an identifier an operator can read in Diagnostics and compare
against their provider console, so it is exposed there and in the management REST
resource; API tokens, keys, and secrets are not, anywhere, ever (§12.4).

**Configuration drift during recovery.** A recovery worker resolves `ssl_mutation_driver`
and compares that driver's current `environment_id()` with `ssl_mutation_environment`. If
the driver is not registered, or the environment does not match, recovery:

- performs **no provider read and no provider mutation** — not even against the driver
  that is currently configured;
- leaves the row in `RECOVERING` under its own token with the bounded re-read schedule;
- reports, by driver id and environment identity, exactly which configuration must be
  restored before the outcome can be learned.

The row stays fenced until the original environment returns. That is the correct
behaviour: an unresolved mutation against an unreachable account is a configuration
problem an operator must fix, and guessing is strictly worse than waiting.

This applies to all four kinds — create, adopt, method change, and remove — including
mappings that already carry an `ssl_provider`, because a stored provider id still says
nothing about *which account or zone* that driver was pointed at when the request left.

#### RESERVED — the window is owned, nothing has been sent

A newly acquired lease begins in `RESERVED`. It means exactly: one worker owns this
mapping's mutation window; authorization checks may run; provider **reads** and the fresh
DNS proof may run; **no provider mutation has begun and no mutating driver method has been
invoked.**

**Acquire** — atomic CAS against the expected revision, permitted **only when the row
carries no lease at all**:

```sql
UPDATE {prefix}pd_domains
   SET ssl_mutation_token = :tok, ssl_mutation_kind = :kind,
       ssl_mutation_phase = 'reserved', ssl_mutation_expires_at = :exp,
       ssl_mutation_driver = :driver_id, ssl_mutation_environment = :environment_id,
       revision = revision + 1, updated_at = :now
 WHERE id = :id AND revision = :rev
   AND ssl_mutation_token IS NULL      -- and therefore kind, phase, expiry are NULL too
```

**Expiry never makes a row available to a normal worker.** A non-null
`ssl_mutation_token` blocks ordinary acquisition regardless of phase and regardless of
whether the lease has expired. Expiry transfers responsibility to `LeaseRecovery`
(below) and nothing else; an expired lease is a row awaiting recovery, not a free row.
The acquisition CAS therefore never replaces an existing token — an ordinary worker that
could overwrite an expired `IN_FLIGHT` token would be starting a second external
operation whose predecessor may already have reached the provider.

The same no-lease requirement governs the `RESERVED` lease taken for force-local-delete
and for a local delete where no provider resource exists (§14.15).

`Ssl\MutationGate` is the only component that performs this transition, and the
authorizers produce a `MutationAuthorization` only while the matching lease is still
`RESERVED` and unexpired.

#### RESERVED → IN_FLIGHT — the consumption point

Immediately before invoking `create()`, `adopt()`, `change_validation_method()`, or
`remove()`, `MutationGate` performs one atomic transition:

```sql
UPDATE {prefix}pd_domains
   SET ssl_mutation_phase = 'in_flight', revision = revision + 1, updated_at = :now
 WHERE id = :id AND revision = :rev
   AND ssl_mutation_token = :tok AND ssl_mutation_kind = :kind
   AND ssl_mutation_phase = 'reserved' AND ssl_mutation_expires_at > :now
   AND ssl_mutation_driver = :driver_id
   AND ssl_mutation_environment = :environment_id
   AND host = :host AND ( ssl_provider <=> :provider )
   AND ( ssl_ref <=> :ref ) AND challenge = :challenge
   AND ( ssl_method <=> :method )
   AND ( ssl_ownership_origin <=> :origin )
   AND ( ssl_owner_installation_id <=> :owner )
```

Every value bound into the authorization appears in the `WHERE` clause, so any concurrent
change to the mapping makes the transition fail. On success the gate returns a typed
permit:

```php
final class ExecutionPermit {
    public readonly MutationKind $kind;
    public readonly int    $mapping_id;
    public readonly int    $in_flight_revision;   // the revision AFTER consumption
    public readonly string $lease_token;
    public readonly DateTimeImmutable $expires_at;
}
```

**If the CAS affects zero rows: the provider is not called, a `MutationRefusal` or
`pd_conflict` is returned, and the authorization is not reused.** Once the transition
succeeds, that authorization can never begin another mutation execution.

**All mutating driver methods are invoked only through `MutationGate`, only after this
transition, and they receive the `ExecutionPermit` rather than the unconsumed
authorization.** No service, REST controller, cron worker, Admin action, or reconciler
calls a mutating driver method directly.

What this guarantees, stated precisely: **one local mutation execution may be initiated
per authorization.** Inherently ambiguous external outcomes are still resolved by reading
provider state. Exactly-once behaviour from an external API is not promised, because no
client can promise it.

One bounded exception lives inside a single execution: a definitive, non-mutating
rejection may be retried once within that execution. Cloudflare error 1413 (§14.11) is
such a case — it reports that the request was *rejected*, so retrying once without
`custom_metadata` is still one mutation execution. This never extends to a timeout,
connection reset, 5xx, or any other ambiguous result.

#### Finalize — result and release in one transition

When the provider returns a known result, the local result is applied and the lease
cleared in **one** atomic CAS, not two independent steps:

```sql
UPDATE {prefix}pd_domains
   SET <confirmed state, ssl_ref, ownership provenance, ssl_method, ssl_error,
        removal outcome, ssl_provider_state, ssl_checked_at …>,
       ssl_provider_environment = :promoted_environment,   -- on create/adopt only
       ssl_mutation_token = NULL, ssl_mutation_kind = NULL,
       ssl_mutation_phase = NULL, ssl_mutation_expires_at = NULL,
       ssl_mutation_driver = NULL, ssl_mutation_environment = NULL,
       revision = revision + 1, updated_at = :now
 WHERE id = :id AND revision = :in_flight_rev
   AND ssl_mutation_token = :tok AND ssl_mutation_kind = :kind
   AND ssl_mutation_phase = 'in_flight'
```

If this CAS fails because a recovery worker has fenced the original worker (below), the
original worker **discards its local result, does not retry the provider mutation**, and
leaves reconciliation to the recovery owner.

#### What the lease blocks

While a lease exists on the row — held by another token, in any phase, **expired or
not** — every path (REST, Admin, cron, reconciliation, CLI) refuses or defers any write
that changes a field bound into an
authorization: `host`, `challenge` (rotation), `ssl_provider`, `ssl_ref`, `ssl_method`,
the ownership columns, adoption, removal, force-local-delete, and any further provider
mutation. The refusal is `pd_mutation_in_progress` (409) and it **never touches the
provider**.

Writes that are not bound into an authorization — `activation_state`, `title`,
`favicon_attachment_id` — and all read-only diagnostics remain permitted.

**Ordinary queue selection excludes every leased row**, in any phase, expired or not.
Verification, SSL mutation, reconciliation, deletion, Admin, REST, and CLI work all use
the same no-lease condition:

```sql
… AND ssl_mutation_token IS NULL
```

This is deliberately not an expiry test. Ordinary work skips all leased rows. Expiry
selects work in exactly one place — the dedicated `LeaseRecovery` selector below — and
only `LeaseRecovery` may transition an expired lease.

#### LeaseRecovery — the only claimant of expired leases

`Ssl\LeaseRecovery` runs as its own pass in `pd_ssl_sweep` with its own selector. It is
the **only work selector in the plugin that finds rows by lease expiry**; every
ordinary-work selector instead requires `ssl_mutation_token IS NULL`. The phase-specific
recovery CAS statements below also test expiry, but they are not selectors — they pin the
expected expired state as a precondition of changing it, which is exactly what makes each
transition safe:

```sql
SELECT … FROM {prefix}pd_domains
 WHERE ssl_mutation_token IS NOT NULL
   AND ssl_mutation_expires_at <= :utc_now
 ORDER BY ssl_mutation_expires_at ASC
 LIMIT :batch
```

It then dispatches on the **persisted phase**, and no other component may perform any of
these transitions:

| Expired phase | Recovery action |
|---|---|
| `RESERVED` | clear it with the phase-pinned CAS below, **without a provider read**. Only after that cleanup succeeds does the row become eligible for ordinary acquisition. |
| `IN_FLIGHT` | replace the token and enter `RECOVERING` **before** reading provider state. Never start a new mutation. |
| `RECOVERING` | replace the recovery token before continuing bounded provider reads. Never start a new mutation. |

A normal acquisition performs none of these. Expired `IN_FLIGHT` and `RECOVERING` rows
stay fenced from any new mutation until their external outcome is resolved, and the only
route back to a lease-free row is a successful recovery transition.

#### Recovering an expired RESERVED lease — nothing was sent

An expired `RESERVED` lease is proof that the provider mutation **never began**. It may
therefore be cleared **without any provider read**, but only through a CAS that pins the
phase:

```sql
UPDATE {prefix}pd_domains
   SET ssl_mutation_token = NULL, ssl_mutation_kind = NULL,
       ssl_mutation_phase = NULL, ssl_mutation_expires_at = NULL,
       ssl_mutation_driver = NULL, ssl_mutation_environment = NULL,
       revision = revision + 1, updated_at = :now
 WHERE id = :id AND ssl_mutation_token = :tok
   AND ssl_mutation_phase = 'reserved' AND ssl_mutation_expires_at <= :now
```

This races safely with the begin-mutation transition: if the owning worker reaches
`IN_FLIGHT` first, the cleanup CAS matches nothing; if cleanup clears the `RESERVED`
lease first, the begin-mutation CAS matches nothing and **no provider call occurs**.
Exactly one side wins. An expired `RESERVED` lease is never treated as evidence that a
provider mutation may have happened — and, equally, it is never treated as an absent
lease: until this cleanup succeeds the row is still leased, and ordinary acquisition
against it affects zero rows.

#### Recovering an expired IN_FLIGHT lease — fence first, then read

An expired `IN_FLIGHT` lease means a provider mutation may have been sent and its outcome
is unknown. **Before reading provider state**, the recovery worker atomically claims
recovery, replacing the token so the original worker is fenced:

```sql
UPDATE {prefix}pd_domains
   SET ssl_mutation_token = :new_recovery_tok, ssl_mutation_phase = 'recovering',
       ssl_mutation_expires_at = :recovery_exp,
       revision = revision + 1, updated_at = :now
 WHERE id = :id AND ssl_mutation_token = :old_tok
   AND ssl_mutation_kind = :kind          -- preserved unchanged
   AND ssl_mutation_phase = 'in_flight' AND ssl_mutation_expires_at <= :now
```

`ssl_mutation_driver` and `ssl_mutation_environment` are deliberately absent from the
`SET` clause: the recovery owner inherits the original binding rather than rebinding to
whatever is configured now. That inheritance is what makes the drift rule above
enforceable.

After the claim succeeds: the original worker's finalize CAS (which names the old token
and `in_flight`) fails; it discards its local result; it does not retry the provider
mutation; and **only the recovery-token owner may apply reconciliation results.**

The recovery owner then performs the read-first reconciliation appropriate to the
preserved `ssl_mutation_kind`:

| Kind | Recovery reads and decides |
|---|---|
| `CREATE` | the §14.6 ambiguous-create cases — marker-matched recovery, conclusive absence, marker-free ambiguity, foreign marker |
| `ADOPT` | identity and marker state, before deciding whether adoption succeeded |
| `METHOD_CHANGE` | the provider's confirmed method (§14.10) |
| `REMOVE` | whether the provider resource still exists |

**Recovery never repeats an ambiguous mutation blindly.** If provider state is still
incomplete, transient, or eventually inconsistent, the row **stays** in `RECOVERING`
under the recovery token, another bounded read is scheduled with backoff against a
recovery TTL, **no further provider mutation is issued**, and the condition is surfaced
in Diagnostics.

A later worker may take over an expired `RECOVERING` lease only through another
token-replacing CAS of the same shape (`phase = 'recovering'`, expiry past), which fences
the previous recovery worker identically.

#### Timing

`pd_mutation_lease_ttl` (default 120 s, clamped 30–600) and the recovery grace must both
exceed the driver's configured provider HTTP timeout plus a documented safety margin, so
ordinary recovery can never begin while the original request could still legitimately be
within its timeout. The default 120 s lease against the 10 s Cloudflare timeout (§14.11)
leaves that margin comfortably; the relationship is asserted in the test suite rather
than left to configuration discipline.

#### The complete provider-mutation sequence

Used identically by create, adopt, method change, and remove:

1. Read the mapping and its expected revision.
2. Acquire a **`RESERVED`** lease by CAS.
3. Build the `SslResourceContext` from the reserved row.
4. Perform identity, ownership, method, and fresh DNS checks.
5. Produce the bound `MutationAuthorization`.
6. **Consume it** by CAS from `RESERVED` to `IN_FLIGHT`, obtaining an `ExecutionPermit`.
7. Invoke exactly one local mutation execution through `MutationGate`.
8. Resolve the provider result, using read-first handling for any ambiguity.
9. Apply the confirmed result and clear the lease **atomically** under the in-flight
   token and revision.
10. If the worker dies or the lease expires, a recovery worker fences it by claiming
    `RECOVERING` **before** reading provider state.

### 12.7 State machines

The three states are independent. Only verification and activation gate serving, and
only by AND.

**Verification** — owned exclusively by `Verification\*`.

| From | To | Trigger | Effects |
|---|---|---|---|
| `unverified` | `pending` | saved with a target; explicit re-check | deadline = now + 72 h; next attempt +15 min |
| `pending` | `verified` | `MATCH` | `verified_at`; counters reset; deadline cleared; `resolver_class` recorded |
| `pending` | `pending` | hard failure | `hard_failure_count++`; stays pending until deadline |
| `pending` | `failed` | deadline passed | fires `pd_mapping_verification_failed` |
| `pending` | `pending` | `TRANSIENT` | `transient_failure_count++`; hard count untouched; backoff |
| `verified` | `verified` | `MATCH` | `last_checked_at`; counters reset; next attempt +24 h |
| `verified` | `verified` | hard failure below the limit | `hard_failure_count++` |
| `verified` | `failed` | hard failure reaching the limit | stops serving at the next request |
| `verified` | `verified` | `TRANSIENT` | `transient_failure_count++`; ≥ 10 raises a notice; **never deactivates** |
| `failed` | `pending` | explicit re-check | counters reset; new deadline |
| *any* | `unverified` | challenge rotated | old challenge invalid immediately; `verified_at` cleared |

**Arithmetic:** increment first, then compare —
`hard_failure_count += 1; if (hard_failure_count >= pd_verification_grace) → failed`.
With the default 3: failures 1 and 2 keep the mapping verified; failure 3 fails it.

**Counter resets:** `hard_failure_count` resets on `MATCH` only.
`transient_failure_count` resets on any **non-transient** outcome — `MATCH` or a hard
failure both prove the resolver is reachable. Rotation resets both. A manual
`POST /verify` runs a real check and applies the same arithmetic; it is not a free pass.

Challenge rotation is refused while a provider-mutation lease is held, because a bound
authorization names the challenge.

**Activation** — written only by admin and REST; never by verification or SSL.
`inactive ⇄ active`. A mapping may be `active` while `pending`, letting an operator
pre-stage a domain. Serving requires `verified && active && pd_mapping_is_active`.
Activation is not bound into any authorization, so it is permitted during a lease.

**SSL** — owned by `Ssl\*`; **never gates serving** (TLS terminates before PHP runs; if
the request arrived, the certificate question is already settled).

```php
enum SslState { case NONE; case REQUESTED; case PENDING_VALIDATION; case ACTIVE;
                case FAILED; case PENDING_REMOVAL; case REVOKED; }
```

| Situation | From | To |
|---|---|---|
| `create()` accepted, validation needed | `none`/`failed`/`revoked` | `requested` |
| `create()` accepted, provider reports already issued | `none`/`requested` | `active` |
| Ambiguous create, resource conclusively absent | unchanged | retryable under a new lease |
| Ambiguous create recovered by matching marker | `requested` | provider-reported state, reference bound |
| Ambiguous create, markers unavailable, nothing previously bound | `requested` | `failed` / `provider_create_ambiguous` |
| Existing resource, identity does not match | unchanged | `failed` / `unowned_resource` |
| Validation outstanding | `requested` | `pending_validation` |
| Provider reports issued | `pending_validation` | `active` |
| Still validating | `pending_validation` | unchanged, `ssl_checked_at` advanced |
| **Resource missing** | `requested`/`pending_validation`/`active` | `failed` / `provider_resource_missing` |
| Hard error | any | `failed` |
| **Transient** | any | **unchanged**; `ssl_transient_count++`; `ssl_checked_at` **not** advanced |
| Removal authorized and requested | `requested`/`pending_validation`/`active`/`failed` | `pending_removal` |
| Removal confirmed / resource absent | `pending_removal` | `revoked` |
| Retry | `failed`/`revoked` | `requested` |

Illegal transitions throw in the repository rather than silently no-op.

---

## 13. Verification subsystem

### 13.1 Challenge and record

```
name:  {challenge_label}.{ascii-host}     e.g. _post-domain-challenge.example.com
type:  TXT
value: post-domain-verify=<32 lowercase hex>
```

Token: 32 hex characters from `random_bytes()`, `UNIQUE` indexed, never reused,
rotatable (which resets verification to `unverified`), destroyed with the row. **The
record must remain in DNS permanently** — re-checks read it, and every provider
mutation re-proves against it. Aliases verify at their own name.

`pd_txt_record_label` runs **only** at create/rotate; its validated result is persisted
in `challenge_label`. Ordinary verification composes the name from persisted data and
never re-runs the filter. If persisted values fail validation at read time the row is
**corrupt**: `integrity_error` is set, the disposition becomes `BROKEN_503`, and
verification halts. It does not keep serving on a soft warning.

Full TXT-name validation: composed name ≤ 253 bytes, each label 1–63, ≤ 127 labels,
label charset enforced.

This record is the **plugin's** ownership proof and is entirely separate from any
provider's own hostname-ownership or certificate-validation records (§14.13). None of
them may be substituted for another.

### 13.2 Resolver

```php
enum DnsOutcome { case MATCH; case MISMATCH; case NO_RECORD; case NXDOMAIN; case TRANSIENT; }
interface DnsResolver { public function txt( string $name ): DnsResult; }
```

**The authoritative default is `DohResolver` (DNS-over-HTTPS).** The grace policy
depends on RCODE, and `dns_get_record()` cannot supply one — it returns an empty array
for NXDOMAIN, NOERROR-with-no-TXT, and SERVFAIL alike. Treating that ambiguity as a
hard failure is how live domains get deactivated by a resolver hiccup.

- Two independent endpoints by default (Cloudflare, Google), via `pd_doh_endpoints`.
- **A hard outcome requires agreement.** `NXDOMAIN`, `NO_RECORD`, and `MISMATCH` are
  returned only when both endpoints agree. Disagreement, or any single-endpoint
  failure, yields `TRANSIENT`.
- RCODE mapping: `0` with TXT answers → `MATCH`/`MISMATCH`; `0` with none →
  `NO_RECORD`; `3` → `NXDOMAIN`; `2`, timeout, transport error, `429` → `TRANSIENT`.

**These are public verification resolvers, not an authoritative-DNS requirement.** They
read whatever the domain's own authoritative provider publishes (§14.14).

**Transport hardening:** HTTPS enforced on the endpoint; `redirection => 0`; response
capped at 64 KB; JSON `Content-Type` required; shape validated (`Status` an integer,
`Answer` an array of objects, `type === 16` for TXT) before any field is read. Non-200,
malformed JSON, wrong shape, oversize, or redirect ⇒ **`TRANSIENT`**. Endpoints come
only from the default list or the filter; nothing derives an endpoint from request data.

`NativeDnsResolver` remains for environments that cannot make outbound HTTPS calls,
with a hard restriction: **it may emit only `MATCH`, `MISMATCH`, or `TRANSIENT`.** Every
empty or failed lookup is `TRANSIENT`, so it can never deactivate a verified mapping —
correct behaviour for a resolver that cannot distinguish "record gone" from "resolver
unwell." Selecting it raises a persistent admin notice, and a `TRANSIENT` from it can
never satisfy the fresh-proof requirement for a provider mutation.

Multi-string TXT values are concatenated per RFC before matching; comparison is
`hash_equals`; **all** returned TXT values are examined, since a domain legitimately
carries many.

### 13.3 `pd_dns_resolver` is trusted code

A custom resolver **substitutes the domain-ownership proof mechanism**; it does not
integrate with it. A resolver returning `MATCH` unconditionally disables verification
entirely and would also satisfy the fresh-proof requirement for provider mutations.
Installing a non-default resolver raises a persistent admin notice naming the class, and
the class is recorded on every verification event and every mutation authorization.

### 13.4 Fresh proof

```php
final class FreshProof {
    public function prove( Mapping $m ): DnsOutcome;   // no cache, no stored state
}
```

`FreshProof` performs a live resolution of the mapping's **current persisted** challenge
under the normal two-resolver agreement rules and returns the outcome. It never reads
`verification_state`, `verified_at`, or `last_outcome`. It exists because
**cached verification state is not sufficient authorization for a provider mutation**,
and because a rotated challenge must invalidate any authority a copy of the database
might otherwise claim.

### 13.5 Queue, budget, and leases

**Due-work queries** select directly on persisted next-attempt columns — no table
scans, no heuristics — and exclude **every** row carrying a provider-mutation lease, in
any phase, expired or not:

```sql
SELECT … FROM {prefix}pd_domains
WHERE verification_state IN (…) AND integrity_error IS NULL
  AND verify_next_attempt_at IS NOT NULL AND verify_next_attempt_at <= :utc_now
  AND ssl_mutation_token IS NULL
ORDER BY verify_next_attempt_at ASC
LIMIT :batch
```

The lease condition is a no-lease test, not an expiry test. An expired lease still blocks
ordinary work; the `LeaseRecovery` selector (§12.6) is the only one that finds work by
expiry.

Every transition sets the next attempt explicitly: `pending` +15 min; `verified` +24 h;
transient backoff +30 min growing exponentially to a 6 h cap; SSL transient honours
`Retry-After`; deletion retry exponential 1 min → 6 h. `NULL` means not due, which is
how terminal, corrupt, and `pending_removal` rows stay out of the queue without special
cases.

**Run-time budget:** `pd_sweep_budget_seconds` (default 20) and a batch cap. If rows
remain due, the run schedules **one** continuation 60 s out rather than looping, so a
5,000-domain backlog drains across ticks instead of timing out the same first batch
forever. Diagnostics reports backlog depth and the oldest due timestamp.

**Token-owned sweep locks:** the sweep lock holds `{token, expires_at}`; acquisition is
a conditional insert, release is compare-and-delete on the token, so a slow run whose
lock has expired cannot release a newer run's lock.

**Per-mapping verification lease:** acquired by CAS before any DNS query, and the result
applied under CAS on `(id, revision, verify_lease_token)`. **If that CAS fails the DNS
result is discarded — never replayed.** The row changed underneath the attempt (rotated
challenge, retarget, deletion) and the result answers a question no longer being asked.
The next sweep resolves again. `verify_lease_token` doubles as the attempt id on the
event. This lease is distinct from, and independent of, the provider-mutation lease.

### 13.6 Cron topology

| Hook | Schedule | Work |
|---|---|---|
| `pd_verify_pending` | 15 min | due `pending` rows, batched |
| `pd_verify_established` | hourly sweep, ~daily per row | due `verified` and transient-backoff rows |
| `pd_ssl_sweep` | 15 min | two selectors: ordinary due work on lease-free rows, and `LeaseRecovery` on rows whose lease has expired |
| `pd_maintenance` | daily | event pruning, orphan-alias check, full `Reconciler` pass, dangling-target scan |

WP-Cron is unreliable on low-traffic sites. The README documents the system-cron
alternative (`DISABLE_WP_CRON` + `wp cron event run --due-now`), and Diagnostics reports
the age of the oldest overdue check, so "verification silently stopped" is visible
rather than inferred.

---

## 14. SSL subsystem

The subsystem rests on one separation: **identity** is what the provider says a resource
is; **authorization** is whether this installation may change it. The driver answers the
first. A plugin-owned gate outside every driver answers the second, using column state
and live proofs only.

### 14.1 Resource context

Provider operations never receive a bare host. A host alone cannot express the
mapping-specific rules this design requires, so every call carries a typed context built
from the **leased** row:

```php
final class SslResourceContext {
    public readonly int     $mapping_id;
    public readonly string  $host;                    // normalized ASCII, exact host
    public readonly string  $installation_id;         // current pd_installation_id
    public readonly string  $provider_id;             // stored on the mapping
    public readonly ?string $provider_ref;            // stored resource reference, if bound
    public readonly ?OwnershipOrigin $ownership_origin;
    public readonly ?string $owner_installation_id;
    public readonly string  $challenge_name;          // persisted composed TXT name
    public readonly string  $challenge_value;         // persisted expected value
    public readonly int     $revision;                // leased revision
    public readonly ?string $lease_token;             // held lease, for mutations
    public readonly ?string $requested_method;        // persisted DCV method
}
```

### 14.2 Identity

```php
enum IdentityVerdict {
    case MATCH;               // bound resource, confirmed
    case RECOVERABLE_CREATE;  // unbound, but the provider marker names this install+mapping
    case MISMATCH; case ABSENT; case AMBIGUOUS; case UNKNOWN;
}
enum MarkerSupport { case SUPPORTED; case UNAVAILABLE; case UNKNOWN; }

final class ProviderMarker {
    public readonly ?string $installation_id;
    public readonly ?int    $mapping_id;
    public readonly array   $raw;
}

final class IdentityResult {
    public readonly IdentityVerdict $verdict;
    public readonly ?string $expected_ref;        // what we stored (null before binding)
    public readonly ?string $observed_ref;        // what the provider returned
    public readonly ?string $observed_hostname;   // the exact hostname on the resource
    public readonly ?ProviderMarker $marker;
    public readonly MarkerSupport   $marker_support;
    public readonly bool    $read_complete;       // complete and authoritative
    public readonly bool    $transient;
    public readonly ?string $code;
    public readonly ?string $message;
}
```

`MATCH` requires **all** of: `read_complete`; `expected_ref !== null`;
`observed_ref === expected_ref`; `observed_hostname === $ctx->host` byte-for-byte after
normalization; and no conflicting marker. This rule is not relaxed for already-bound
resources under any circumstance.

`RECOVERABLE_CREATE` is reachable **only** when `expected_ref === null` — that is,
during recovery of an ambiguous first create. It requires `read_complete`, a resource
whose `observed_hostname === $ctx->host`, and a marker naming **both** this installation
and this mapping. It is not adoption and never substitutes for it.

**Markers are defence in depth, never the basis of ownership.** A marker naming this
installation is *additional* evidence. A marker naming a different installation, or a
different mapping, **blocks mutation**. An *absent* marker establishes nothing either
way — it neither proves nor disproves ownership, and must not block when the provider
does not support markers at all. `marker_support = UNAVAILABLE` (§14.11, Cloudflare
error 1413) means the account cannot store markers; the driver keeps working, using the
reference-plus-hostname binding, the persisted ownership provenance, and the plugin's
fresh DNS proof instead.

### 14.3 Driver contract

```php
final class DriverCapabilities {
    public readonly bool  $supports_markers;
    public readonly array $validation_methods;   // subset of ['http','txt','email']
    public readonly bool  $supports_apex_proxy_targets;
}

interface SslDriver {
    public function id(): string;

    /**
     * A non-secret, stable identity for the provider environment this driver instance
     * is configured against — the account, zone, or endpoint that actually holds the
     * resources. It is written into the lease before any mutation and compared on
     * recovery (§12.6), is shown to operators, and must never encode a credential.
     */
    public function environment_id(): string;

    public function capabilities(): DriverCapabilities;

    public function status(   SslResourceContext $ctx ): SslStatus;
    public function identify( SslResourceContext $ctx ): IdentityResult;

    // Mutating methods receive an ExecutionPermit — proof that the authorization was
    // already consumed by the RESERVED -> IN_FLIGHT transition (§12.6). They are
    // invoked only by MutationGate, never directly.
    public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus;
    public function adopt(  SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus;
    public function change_validation_method(
        SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus;
    public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult;

    /** @param SslResourceContext[] $contexts */
    public function reconcile( array $contexts ): ReconcileReport;

    public function validation_plan( SslResourceContext $ctx, ApexCapability $apex ): ValidationPlan;
}

enum RemovalOutcome { case REMOVED; case PENDING; case TRANSIENT; case FAILED; }

final class SslStatus {
    public readonly SslState $state;
    public readonly ?string  $ref;
    public readonly ?string  $code;              // sanitized
    public readonly ?string  $message;           // sanitized, ≤ 500 bytes
    public readonly ?string  $confirmed_method;  // provider-reported ssl.method after a read
    public readonly bool     $transient;         // true => caller must not change state
    public readonly ?array   $provider_state;    // raw axes + error arrays, for storage
}
final class RemovalResult {
    public readonly RemovalOutcome $outcome;
    public readonly ?string $code; public readonly ?string $message;
    public readonly ?int    $retry_after;
}
final class ReconcileReport {
    /** @var iterable<string,SslStatus> host => status */
    public readonly iterable $statuses;
    public readonly bool     $snapshot_complete;
    public readonly ?string  $incomplete_reason;
}
```

Every mutating method asserts that the supplied permit's `kind` matches the operation and
that its bindings match the context it was handed; a mismatch is refused before any
network activity. A driver that is handed anything other than a permit issued by
`MutationGate` for this exact call refuses outright — the permit is the driver's proof
that consumption already happened.

`status()` and `identify()` are read-only and need no lease.

`remove()` returning an enum rather than `void` separates "gone" from "we asked".
`REMOVED` ⇒ `revoked`, row proceeds to hard delete. `PENDING` ⇒ stays `pending_removal`.
`TRANSIENT` ⇒ no state change, `deletion_attempts` **not** incremented, next attempt
honours `retry_after`. `FAILED` ⇒ stays `pending_removal`, attempts incremented toward
the force-delete ceiling.

**`snapshot_complete` is load-bearing.** When false, absence from the snapshot means
nothing and the reconciler must not infer `provider_resource_missing`; only observed
rows are updated and the reason is logged.

### 14.4 Authorization

```php
final class MutationAuthorization {   // in-process only: never persisted, serialized, or logged
    public readonly MutationKind $kind;
    public readonly string $lease_token;       // the held lease; its DB row is the enforcement
    public readonly int    $mapping_id;
    public readonly int    $revision;          // the leased revision
    public readonly string $host;
    public readonly string $provider_id;
    public readonly ?string $provider_ref;     // null only for CREATE
    public readonly string $challenge_hash;    // hash of persisted challenge name + value
    public readonly ?string $requested_method; // METHOD_CHANGE only
    public readonly ?OwnershipOrigin $ownership_origin;      // METHOD_CHANGE, REMOVE
    public readonly ?string $owner_installation_id;         // METHOD_CHANGE, REMOVE
    public readonly bool   $override_foreign_marker;        // ADOPT only
    public readonly DateTimeImmutable $expires_at;          // never beyond the lease
}
final class MutationRefusal {
    public readonly string  $precondition;   // which one failed
    public readonly bool    $transient;
    public readonly ?string $detail;
}
```

**Single-use has a concrete owner, and it acts before the provider does.**
`Ssl\MutationGate` consumes an authorization with the `RESERVED → IN_FLIGHT` CAS of
§12.6 — which names the mapping, the leased revision, the lease token, the kind, the
phase, the expiry, and every mapping value bound into the authorization — and only then
invokes the driver with the resulting `ExecutionPermit`. Because that CAS bumps the
revision and moves the phase, the same authorization can never begin a second mutation
execution, and a zero-row result means the provider is never called at all. Enforcement
lives in the database, before the outbound request, not in a flag on a readonly object
written afterwards.

An authorization is produced only while the matching lease is `RESERVED` and unexpired;
a lease already in `IN_FLIGHT` or `RECOVERING` yields no new authorization.

Three authorizers produce these, sharing a common precondition core:

| Precondition | `CREATE` | `ADOPT` | `METHOD_CHANGE` | `REMOVE` |
|---|:--:|:--:|:--:|:--:|
| Environment / clone check resolved | ✔ | ✔ | ✔ | ✔ |
| Stored `ssl_provider` matches the selected, registered driver | ✔ (on set) | ✔ | ✔ | ✔ |
| Provider-mutation lease held for this `kind`, phase `RESERVED`, unexpired | ✔ | ✔ | ✔ | ✔ |
| Fresh `identify()`, `read_complete = true` | ✔ | ✔ | ✔ | ✔ |
| Verdict `MATCH` against the stored reference and exact hostname | — | — | ✔ | ✔ |
| No conflicting provider marker | ✔ | ✔ (unless overridden) | ✔ | ✔ |
| Fresh `FreshProof::prove()` = `MATCH` on the current permanent challenge | ✔ | ✔ | ✔ | ✔ |
| Ownership authority per §12.2 (`origin` set, installation matches) | — | — | ✔ | ✔ |
| Explicit operator confirmation | — | ✔ | — | — |
| Requested method validated against `DriverCapabilities` | — | — | ✔ | — |
| `RESERVED → IN_FLIGHT` CAS confirming the leased revision and every bound value | ✔ | ✔ | ✔ | ✔ |

Any failure returns a `MutationRefusal` naming the precondition; nothing at the provider
is touched. A transient DNS result, resolver disagreement, provider ambiguity, an
incomplete provider read, or a concurrent mapping change all block **without** changing
provider state; the mapping's next attempt is scheduled and `deletion_attempts` is not
incremented for transient refusals.

**Consequences that fall out of this design, by construction:**

- A **clone** rotates every challenge and clears the ownership columns, so the ownership
  and fresh-proof preconditions can never pass against the original installation's
  resources. A clone cannot delete or change them, and no extra rule is needed.
- A **restore or move** that retains the installation identity, the ownership columns,
  and the challenges is the same installation; every precondition passes normally.
- `MISMATCH`, foreign markers, and conflicting references fail identity and are never
  mutated.
- `UNKNOWN`, `AMBIGUOUS`, and incomplete reads are transient and non-destructive.

### 14.5 Deletion

`Ssl\DeletionAuthorizer` produces a `REMOVE` authorization under the table above. The
ownership precondition reads **columns**, never events: `ssl_ownership_origin IS NOT NULL`
and `ssl_owner_installation_id === pd_installation_id`. Pruning the adoption event has no
effect on deletability.

**Forced local deletion** (`DELETE …?force=true`) removes the **local row only**, writes
an event recording that a provider resource may have been left behind, and **never**
bypasses this gate or issues a provider deletion. It issues no provider mutation, but it
must still atomically prove that **the row carries no provider-mutation lease at all**.
It acquires its own `RESERVED` lease through the ordinary acquisition CAS, which requires
`ssl_mutation_token IS NULL`, and so it can only ever start from a lease-free row; it
then deletes the row under a CAS naming that lease's token, kind, and phase.

**A row carrying any existing provider-mutation lease — `RESERVED`, `IN_FLIGHT`, or
`RECOVERING`, expired or unexpired — cannot be force-locally deleted.** The acquire
matches nothing and the request is refused with `pd_mutation_in_progress`. That is a
stronger and simpler guarantee than "nothing is in flight": it also refuses a row that
another worker is merely preparing to mutate, so a local delete can never orphan a
request that is outstanding at the provider, nor race one that is about to be sent.

### 14.6 Creation and ambiguous-create recovery

`create()` is reached only through the §12.6 sequence, and the `RESERVED → IN_FLIGHT`
transition is what prevents two simultaneous first-create calls for the same mapping:
the second worker cannot acquire a lease at all, or — if it somehow holds a stale
authorization — its consumption CAS matches nothing. Either way it refuses with
`pd_mutation_in_progress` or `pd_conflict` and **never sends a second POST**.

| Case | Behaviour |
|---|---|
| **A. POST succeeds, complete resource returned** | verify the returned hostname equals `$ctx->host`; persist `ssl_ref`, `ssl_provider`, `ssl_ownership_origin = created`, `ssl_owner_installation_id`, `ssl_method`, and the resulting state — applied together with the lease clear in the single finalize CAS on the in-flight revision and token |
| **B. POST outcome ambiguous** (timeout, reset, 5xx) | issue a **complete provider read** before considering any retry; never blindly repeat the POST. If the lease expired meanwhile, a recovery worker fences this one first (§12.6) and owns the resolution. |
| **C. Read finds no resource conclusively** | a later attempt may retry under a **newly acquired** lease and a fresh authorization |
| **D. Read finds a resource whose marker names exactly this installation and mapping** | `RECOVERABLE_CREATE`: verify the exact hostname, bind `observed_ref`, persist `origin = created`, record that an ambiguous create was recovered — under the finalize CAS held by whichever token currently owns the window. **This is not adoption.** |
| **E. Read finds a resource, but markers are unavailable or absent, and no reference was previously persisted** | do **not** auto-bind. Set `ssl_state = failed`, `code = provider_create_ambiguous`, and require the explicit adoption workflow (§14.7) with confirmation and a fresh DNS proof |
| **F. Read finds a foreign or conflicting marker** | `unowned_resource`; nothing mutated, nothing bound |

**Qualified idempotency.** `create()` is idempotent **once the provider identity has been
durably bound**. Initial creation is duplicate-safe and ambiguity-safe. A marker-free
create-then-timeout may require explicit adoption, because the plugin refuses to guess
which unbound resource is its own. That boundary is stated plainly rather than papered
over with an idempotency claim the design cannot honour.

### 14.7 Adoption

Adoption is a provider mutation workflow with its own driver operation and its own
authorization, never an implicit consequence of anything.

`Ssl\AdoptionAuthorizer` requires, in addition to the shared core: an explicit
`confirm: true`; a fresh `identify()` with `read_complete = true` returning a resource
whose `observed_hostname === host`; and a fresh `FreshProof::prove()` of `MATCH`. When
the observed marker names a **different** installation, adoption additionally requires
`override_foreign_marker: true` — a deliberate second key for taking over another
installation's resource.

`adopt()` runs through the §12.6 sequence like every other mutation: authorization under
a `RESERVED` lease, consumption by the `RESERVED → IN_FLIGHT` CAS, then the driver call
with the permit. It writes the marker where the provider supports one. The plugin then
persists `ssl_ref = observed_ref`, `ssl_ownership_origin = adopted`,
`ssl_owner_installation_id = pd_installation_id`, `ssl_adopted_at`, and `ssl_adopted_by`
in the single finalize CAS, and records an event naming any prior marker. Where markers
are unavailable, adoption records the binding locally only and the event says so. An
adoption whose lease expires mid-flight is resolved by recovery reading identity and
marker state — never by re-issuing the adoption.

**Adoption is never automatic and never implicit**: finding a duplicate resource does not
adopt it, ambiguous-create recovery does not adopt, and reconciliation adopts nothing.

### 14.8 Environment and clone detection

Options `pd_installation_id` (UUID, generated at install) and
`pd_installation_primary_host` (the `home` host at install). Checked on `admin_init` and
at the top of every sweep — never on the front-end path. A mismatch sets
`pd_environment_mismatch` and, while set:

- **every provider mutation is blocked** — create, adopt, method change, remove, and
  reconciliation refuse with `environment_unresolved`, and every authorizer fails at the
  first precondition. Provider reads for diagnostics are allowed.
- DNS verification continues (read-only, harmless); serving is unaffected.
- A blocking admin notice appears on every screen.

| Operator choice | Effect |
|---|---|
| **Restore / Move** | keep the installation id, the challenges, and all ownership columns; update the stored primary host; clear the flag. This remains the same installation. |
| **Clone** | generate a **new** installation id; clear `ssl_ownership_origin`, `ssl_owner_installation_id`, `ssl_adopted_at`, `ssl_adopted_by`, `ssl_ref`, `ssl_provider_state`, and any stale mutation lease; set `ssl_state = none`; reset every row to `unverified` with **rotated challenges**; adopt nothing remotely |

A database copy is not evidence of domain control, so a clone re-proves from scratch and
— by §14.4 — cannot touch the original's provider resources.
**Limitation:** a clone restored onto the *same* primary host is undetectable by this
mechanism.

### 14.9 Cooldowns and ambiguous mutations

Option `pd_provider_cooldowns`, keyed by **driver id**:
`{ "cloudflare-saas": { "until": "…Z", "reason": "429", "source": "retry_after" } }`.
Checked before every sweep, before every provider call, and before scheduling a
continuation. A 429 halts that provider **across all rows** and suppresses continuations
until expiry. Other drivers and DNS verification are unaffected.

No non-idempotent call is retried blind. On timeout, connection reset, or 5xx from a
`POST`, `PATCH`, or `DELETE`, the driver first issues a complete read (`identify()` /
`status()`) to learn the provider's actual state. `Retry-After` is parsed (delta-seconds
or HTTP-date), converted to UTC, and written to the relevant next-attempt column; the
outcome is `TRANSIENT`, with no state change, no attempt increment, and no further calls
to that provider in the same sweep.

If a lease expired while a mutation was in flight, expired-lease recovery (§12.6)
**fences the original worker first** — claiming `RECOVERING` with a fresh token — and
only then performs the read-first reconciliation for the preserved `ssl_mutation_kind`.
The fenced worker cannot finalize and must not retry. An expired `RESERVED` lease is a
different case entirely: nothing was sent, so it is cleared without a provider read.

### 14.10 Changing the validation method

`change_validation_method()` is a first-class driver operation, not an implied use of
`create()`. `Ssl\MethodChangeAuthorizer` produces its `METHOD_CHANGE` authorization
under the §14.4 table, which for this operation requires the full set: environment
resolved, driver match, lease held, fresh complete `identify()` returning `MATCH`
against the stored reference and exact hostname, no conflicting marker, a fresh
two-resolver proof of the permanent challenge, a requested method validated against
`DriverCapabilities`, ownership authority, and CAS confirmation of the leased revision.

The flow follows the §12.6 sequence exactly: authorize under a `RESERVED` lease →
consume by the `RESERVED → IN_FLIGHT` CAS → provider `PATCH` → **provider re-read** →
persist locally **only after the provider's resulting method is confirmed**
(`SslStatus::$confirmed_method`) → apply the result and clear the lease in one atomic
CAS under the in-flight token.

On timeout, connection reset, or 5xx, the `PATCH` is **not** retried blindly. The driver
re-reads provider state:

| Re-read says | Outcome |
|---|---|
| the requested method | treat the mutation as successful; finalize atomically |
| the prior method | retryable under a **newly acquired** lease and authorization |
| incomplete or ambiguous | leave local method state unchanged; report a transient ambiguous outcome and remain in `RECOVERING` for a bounded re-read |

Reconciliation compares stored `ssl_method` with the provider's `ssl.method` and
**reports** divergence as an event and in Diagnostics; it never auto-patches.

### 14.11 Cloudflare for SaaS driver

The API exposes two independent axes:

- `ownership_verification` / `ownership_verification_http` validate **hostname
  ownership** and affect `status`.
- `ssl.validation_records` validate **certificate issuance** and affect `ssl.status`.

Production-ready is exactly `status: active` **and** `ssl.status: active` with DNS
pointing at the SaaS target. **Local `ACTIVE` requires precisely that combination.**

Operations:

- `create()` → `POST /zones/{zone}/custom_hostnames` with `hostname`, `ssl.method`
  (§14.12), `ssl.type: dv`, and — **only when available** — the ownership marker in
  `custom_metadata`. Per the pinned API contract the request omits any wildcard field,
  or sends it explicitly false; the plugin never requests a wildcard certificate
  (§14.16). A `duplicate_record` error routes into the §14.6 recovery table.
- `identify()` → `GET /zones/{zone}/custom_hostnames?hostname=` plus, when a reference
  is bound, `GET …/custom_hostnames/{id}`. It reports the observed id, the exact
  `hostname` on the resource, any parsed `custom_metadata` marker, and whether the read
  was complete (pagination finished, no partial result).
- `adopt()` → `PATCH …/custom_hostnames/{id}` writing `custom_metadata` when supported.
- `change_validation_method()` → `PATCH …/custom_hostnames/{id}` with the new
  `ssl.method` and the existing `ssl.type`, followed by a re-read.
- `remove()` → verifies the authorization bindings, then
  `DELETE …/custom_hostnames/{id}`. A 404 counts as `REMOVED`, which is what idempotent
  removal requires for the deletion workflow to terminate.
- All calls go through the injected `HttpClient`, 10 s timeout, one retry on connection
  failure only — never on a 4xx.

**`custom_metadata` is optional.** It is a paid entitlement on some accounts. The driver
must work without it:

- a matching marker is additional evidence
- a conflicting or foreign marker blocks mutation
- an absent marker establishes nothing
- lacking the entitlement does **not** disable the driver
- nothing in the plugin may assume a marker is present

**API error 1413 means the optional feature is unavailable, not a transient failure.**
It is a definitive, non-mutating rejection: the request was refused, so nothing was
created. On seeing it the driver sets `marker_support = UNAVAILABLE`, persists that in
`ssl_marker_support`, and retries the same call once **without** `custom_metadata`
**inside the same mutation execution** — permitted precisely because the first request
is known to have been rejected. This is the only bounded retry inside an execution, and
it never extends to a timeout, connection reset, 5xx, or any other ambiguous result,
which are always resolved by reading provider state. 1413 is never retried as transient
thereafter. A single admin notice explains that marker-based
defence in depth is off for this account and that identity rests on the
reference-plus-hostname binding, the persisted ownership provenance, and the plugin's
fresh DNS proof.

Credentials come from `Ssl\Credentials` — constants or the `pd_ssl_credentials` option:

| Credential | Purpose |
|---|---|
| `PD_CLOUDFLARE_API_TOKEN` | API authentication |
| `PD_CLOUDFLARE_ZONE_ID` | the SaaS zone |
| `PD_CLOUDFLARE_CNAME_TARGET` | the customer-facing CNAME target (§14.13) |
| `PD_CLOUDFLARE_SSL_METHOD` | DCV method (§14.12) |
| `PD_CLOUDFLARE_APEX_PROXY_TARGETS` | Apex Proxying / BYOIP addresses (§14.13) |
| `PD_CLOUDFLARE_APEX_PROXY_PROVENANCE` | `static_ip_prefix` or `byoip` |

None of these appear on a mapping row, in a REST response, in an event, or in a log.

### 14.12 Certificate validation method

The API accepts exactly one `ssl.method` value: `http`, `txt`, or `email`.

**The default is `txt`.**

| Question | Answer |
|---|---|
| Configuration source | `PD_CLOUDFLARE_SSL_METHOD`, else `pd_ssl_credentials['ssl_method']`, else the default |
| Filter | `pd_ssl_validation_method( string $method, Mapping $m )` |
| Allowed values | `http`, `txt`, `email` — validated against `DriverCapabilities::$validation_methods`; anything else falls back to the default with an admin notice |
| Default | `txt` |
| Persisted per mapping | yes — `ssl_method`, written when the provider confirms it |
| Site-setting change | affects **new** requests only; existing provider resources are never mutated as a side effect |
| Per-mapping change | explicit: `PATCH /domains/{id}/ssl {"method": "…"}` → §14.10 |
| Reconciliation | reports divergence; never auto-patches |
| Unsupportable method | a `DnsBlocker`; `ssl_state` unchanged; never a silent substitution |

**Why `email` is not an automated default:** it requires a human to receive mail at a
WHOIS or role address and click a link. It cannot be completed by publishing a record,
cannot be observed by the plugin, and cannot be automated. It remains selectable, and
when selected the plan surfaces a `ManualRequirement` stating that a person must complete
it out of band.

**Automatic HTTP DCV is a valid success path.** For the exact (non-wildcard) hostnames
this plugin maps, Cloudflare attempts automatic HTTP DCV once the hostname points at the
SaaS target, *even when `txt` was selected*. The plugin therefore treats the
`ssl_validation` TXT requirement as satisfiable by either route: reaching
`ssl.status: active` without the TXT record ever appearing is a success, not an anomaly,
and no blocker is raised for the unfulfilled record.

### 14.13 Validation plan

```php
final class DnsRecordSpec     { string $type; string $name; string $value; int $ttl; }

final class DnsRequirementSet {          // ALL records in a chosen set are required
    string $purpose;      // 'ownership' | 'provider_ownership' | 'ssl_validation' | 'routing'
    string $id; string $label;
    DnsRecordSpec[] $records;
    bool $apex_compatible; string $source;   // 'core' | driver id
    bool $removable_once_active;
}
final class HttpRequirementSet {         // HTTP tokens are NOT DNS records
    string $purpose; string $id; string $label;
    string $url; string $body; string $source;
    bool $removable_once_active;
}
final class ManualRequirement {          // e.g. email DCV
    string $purpose; string $id; string $label;
    string $instruction; array $contacts; string $source;
}
final class ValidationPending {
    string $purpose; string $reason; ?int $retry_after;
}
final class DnsBlocker { string $code; string $message; string $remedy; string $source; }

final class ValidationPlan {
    /** @var array<string, DnsRequirementSet[]> purpose => alternatives (OR within a purpose) */
    array $dns;
    /** @var HttpRequirementSet[] */ array $http;
    /** @var ManualRequirement[] */  array $manual;
    /** @var ValidationPending[] */  array $pending;
    /** @var DnsBlocker[] */         array $blockers;
}
```

**Four distinct purposes, never substituted for one another:**

| Purpose | Source | Meaning | Lifetime |
|---|---|---|---|
| `ownership` | `core` | the plugin's permanent TXT challenge (§13.1) | **permanent** — must never be removed |
| `provider_ownership` | driver | Cloudflare `ownership_verification` / `_http` | may be removed once the provider reports `status: active` |
| `ssl_validation` | driver | from `ssl.validation_records` | appears when the CA issues tokens; gone once `ssl.status: active` |
| `routing` | driver | where the customer points the hostname | permanent while the mapping serves |

**Translation — provider hostname ownership** (`ownership_verification`,
`ownership_verification_http`):

| Provider data | Becomes |
|---|---|
| `ownership_verification` with complete `type`, `name`, `value` | `DnsRequirementSet{purpose:'provider_ownership', id:'cf-hostname-txt', records:[TXT name = value], removable_once_active:true}` |
| `ownership_verification_http` with complete `http_url` and `http_body` | `HttpRequirementSet{purpose:'provider_ownership', id:'cf-hostname-http', removable_once_active:true}` |
| **both** present | rendered as **OR alternatives**, because Cloudflare's contract establishes either method as sufficient on its own |
| neither present while `status` is pending | `ValidationPending{purpose:'provider_ownership', reason:'provider_records_not_yet_issued'}` |
| `status` already `active` | completed provider-ownership instructions are suppressed and, where shown historically, clearly marked removable |
| malformed or incomplete entry | `DnsBlocker{code:'provider_record_malformed'}`; raw shape kept in `ssl_provider_state` |
| provider replaced the token | recomputed from the latest read and recorded as an event |

A provider ownership proof is **never** rendered as the permanent plugin challenge and
**never** substituted for `ssl_validation`.

**Translation — certificate validation** (`ssl.validation_records[]`, whose entries carry
`txt_name`, `txt_value`, `http_url`, `http_body`, `emails[]`):

| Provider data | Becomes |
|---|---|
| `txt_name` + `txt_value` | `DnsRequirementSet{purpose:'ssl_validation', id:'cf-dcv-txt', apex_compatible:true}` |
| `http_url` + `http_body` | `HttpRequirementSet{purpose:'ssl_validation'}` — **never** a DNS record |
| Delegated DCV CNAME | `DnsRequirementSet{id:'cf-dcv-delegated', records:[CNAME _acme-challenge…]}` |
| `emails[]` | `ManualRequirement`; not automatable |
| several records that must all be satisfied | one set containing all of them (ALL semantics) |
| genuinely alternative groups | multiple sets under the same purpose (OR semantics) |
| `validation_records` empty shortly after create | `ValidationPending{reason:'provider_records_not_yet_issued'}` — **not** a blocker |
| malformed, incomplete, or unrecognised entry | `DnsBlocker{code:'provider_record_malformed'}` |

**The plugin never invents a DNS record, and never renders a literal such as
`unsupported` as a record type.** An unsatisfiable configuration is a `DnsBlocker`; an
unissued record is `ValidationPending`. Alternatives are rendered as OR **only** where the
provider's semantics establish that either option genuinely suffices; everything else is
ALL.

**Plan lifetime.** The plan is recomputed from the latest provider read every time it is
rendered; it is never cached. Requirements appear when the provider issues them, change
when the provider replaces them, and disappear when the provider no longer reports them.
Changes are recorded as events so an operator sees that a token was replaced rather than
silently swapped. Raw provider values live only in the sanitized `ssl_provider_state`.

**Routing and apex.**

```php
enum ApexRouting { case CNAME_FLATTENING; case ALIAS_OR_ANAME; case APEX_PROXY; case UNSUPPORTED; }

final class ApexCapability {
    public readonly ApexRouting $routing;
    public readonly string      $reason;
    /** @var string[] valid only for APEX_PROXY */ public readonly array $targets;
    public readonly ?string     $target_provenance;   // 'static_ip_prefix' | 'byoip'
    public readonly bool        $operator_attested;
}
```

No CNAME is assumed, and no A/AAAA record is emitted casually. Routing values come from
the driver's configured customer-facing **CNAME target**
(`PD_CLOUDFLARE_CNAME_TARGET`) — a distinct value, not the fallback origin and not
derivable from it.

Rules:

- **Non-apex hosts** ⇒ one routing set, `CNAME` to the configured target,
  `apex_compatible: false`.
- **Apex on a Cloudflare-authoritative customer zone** ⇒ ordinarily an apex `CNAME` to
  the configured target, relying on Cloudflare's CNAME flattening
  (`ApexRouting::CNAME_FLATTENING`).
- **Apex on another DNS provider** ⇒ permitted where that provider has a demonstrated
  compatible flattening, ALIAS, or ANAME capability (`ApexRouting::ALIAS_OR_ANAME`).
- **A/AAAA routing records may be emitted only under `ApexRouting::APEX_PROXY`**, which
  requires Apex Proxying or BYOIP to have been explicitly configured for the SaaS
  account. Cloudflare assigns Static IP prefixes to the account for this purpose, or uses
  the account's own BYOIP addresses; these are **entitlement-gated and distinct from
  ordinary addresses**. Configured values must therefore be identified as
  `static_ip_prefix` or `byoip` and accompanied by an explicit operator attestation.
- **Ordinary fallback-origin addresses, origin-server addresses, and ordinary Cloudflare
  zone addresses must never be emitted as apex proxy targets.** The plugin does not infer
  an Apex Proxying entitlement from the mere presence of address strings, which is why
  `operator_attested` exists and why `pd_apex_capability` returns a typed, validated
  object rather than a boolean.
- **Absence of a supported apex-routing capability** ⇒ no routing set and a `DnsBlocker`
  naming what must be configured.
- The **default driver configuration assumes no paid Apex Proxying entitlement**;
  `supports_apex_proxy_targets` is false until targets and provenance are configured.

Diagnostics and the README explain the required entitlement and the provenance of the
addresses, so nobody pastes an origin IP into a field labelled "apex targets."

Apex determination uses a maintained Public Suffix List
(`jeremykendall/php-domain-parser`) comparing against the registrable domain — never a
label count, which is wrong for `example.co.uk`.

The admin renders alternatives as "create any one of these", which is what an OR group
means to the person editing a zone file.

### 14.14 Authoritative DNS deployment posture

**The core plugin is authoritative-DNS-provider-neutral.** A mapped domain may use any
authoritative DNS provider capable of publishing the required records. Nothing in the
engine, and nothing in `CloudflareSaasDriver`, requires the customer's zone to be hosted
at Cloudflare.

Cloudflare authoritative DNS is the **recommended and fully validated** deployment
profile. It is a recommendation, not a plugin invariant.

Boundaries, stated explicitly because these roles are easy to conflate:

- **Cloudflare and Google DoH are public verification resolvers** (§13.2). They read
  whatever the domain's authoritative provider publishes. They are not an
  authoritative-DNS requirement and imply nothing about where a zone is hosted.
- **Cloudflare for SaaS is a separate role** — an SSL and reverse-proxy provider for the
  *platform's* zone. A customer domain using it does not need to be on Cloudflare DNS.
- **Cloudflare authoritative DNS is recommended** for operational consistency, DNSSEC,
  account controls, and apex CNAME flattening.
- **Apex domains on another provider** require demonstrated compatible CNAME-flattening,
  ALIAS, or ANAME behaviour. Where that is absent and no attested Apex Proxying or BYOIP
  capability is configured, the plan reports a routing `DnsBlocker` rather than printing
  a record the domain owner cannot create.
- **The current scope generates and verifies DNS instructions. It does not mutate
  customer DNS through any API.** There is no DNS-write credential anywhere in this
  design.
- Recommending Cloudflare authoritative DNS implies **no** access to paid Custom
  Metadata (§14.11) and **no** access to Apex Proxying (§14.13).
- Documentation recommends **client-owned Cloudflare accounts**, strong authentication,
  DNSSEC, and delegated least-privilege access — not one shared account owning every
  client zone.
- A future DNS-management adapter would be a **separate capability** and is not part of
  this implementation.

### 14.15 Durable deletion

**The normal deletion path never deletes the local row before external cleanup
succeeds.** The sole exception is an explicit force-local-delete after the documented
ceiling; it issues no provider deletion and records that the provider resource may
remain.

1. `DELETE /domains/{id}` (with `If-Match`) sets `deletion_requested_at`, forces
   `activation_state = inactive` — **serving stops immediately** — and, when a driver
   holds a resource, sets `ssl_state = pending_removal`. Returns **`202 Accepted`**.
   Refused `409` while aliases point at it, and `409 pd_mutation_in_progress` while
   another provider mutation holds the lease.
2. `pd_ssl_sweep` acquires a `RESERVED` `REMOVE` lease (§12.6) and asks
   `DeletionAuthorizer` for an authorization. A refusal is recorded with its failing
   precondition, the lease is released, and transient refusals do not increment
   `deletion_attempts`.
3. `MutationGate` consumes the authorization with the `RESERVED → IN_FLIGHT` CAS and only
   then invokes `remove()` with the resulting permit. `REMOVED` ⇒ `revoked` ⇒ hard
   delete, applied together with the lease clear in the single finalize CAS, with a final
   event carrying the `host` snapshot.
4. `NullDriver`, or `ssl_state = none`, means nothing external exists ⇒ hard delete
   immediately, `200 OK`. No authorization is required because no provider mutation
   occurs, but a `RESERVED` lease is still taken — through the same acquisition CAS,
   which succeeds only when `ssl_mutation_token IS NULL` — so the delete cannot race a
   mutation or step over one that is being prepared.
5. After 12 attempts / 24 h the row remains, an admin notice names the probable orphan,
   and an operator may `DELETE …?force=true` — which takes a `RESERVED` lease from a
   lease-free row, removes the local row under a CAS naming that lease, records that a
   provider resource may remain, and issues **no** provider deletion. It is refused while
   **any** provider-mutation lease exists on the row, whether `RESERVED`, `IN_FLIGHT`, or
   `RECOVERING`, and whether expired or unexpired.

A row awaiting removal never serves and never re-verifies.

### 14.16 Wildcard scope

Mapped hosts are exact hosts. `HostNormalizer` rejects any label containing `*`, so a
wildcard mapping cannot exist, and there is no schema field, REST input, or setting that
could supply one. Cloudflare wildcard certificate provisioning is therefore **out of
scope**, no wildcard flag appears in any contract, and no unreachable wildcard branch is
carried in the code.

A future wildcard-certificate capability would need its own schema, an entitlement probe,
a distinct validation lifecycle (wildcards cannot use automatic HTTP DCV), and its own
design review. It is noted here so the omission is deliberate rather than accidental.

### 14.17 Reconciliation

The daily `Reconciler` calls `reconcile()` with `SslResourceContext` objects, in chunks,
and adopts provider truth for **state**, with four hard rules: a local/provider mismatch
never triggers a delete at the provider; a transient response changes nothing;
reconciliation **never adopts ownership** of anything; and it never auto-patches a
divergent validation method. It skips every row carrying a mutation lease — any phase,
expired or not — and it never claims an expired one; that is `LeaseRecovery`'s job in
`pd_ssl_sweep`, which fences before reading. Divergences —
state, method, marker support, unbound-but-present resources — are written as events and
surfaced in Diagnostics, so an operator sees "we think active, Cloudflare says pending"
rather than discovering it from a browser warning.

### 14.18 Status map provenance and generation

Both provider status axes are enumerated by the current Cloudflare API schema. The map is
**generated from a pinned schema input**, not transcribed, and generation is reproducible
offline.

The implementation must:

- commit a **pinned schema snapshot** at `references/cloudflare-api-schema.<date>.json`
- commit `references/cloudflare-schema-provenance.json` recording the **source URL**,
  the **retrieval date** (UTC), and the **SHA-256 digest** of the snapshot
- maintain `references/cloudflare-status-policy.php` — the human-authored classification
  policy mapping each enum value to an internal state
- generate `references/cloudflare-status-map.php` from **schema × policy** via
  `bin/generate-cloudflare-status-map.php`
- generate or validate the enum fixtures from the **same** pinned input
- treat the **pinned schema input as the source of truth**; the map and the fixtures are
  derived artifacts
- **fail generation and CI** when: any schema value lacks an explicit classification; a
  duplicate value appears; an expected value is missing; the structure is unexpected; or
  the cardinality differs from the recorded expectation of **16 hostname-status values**
  and **21 SSL-status values**
- keep normal builds free of live network access

An **optional CI drift check** may compare the pinned snapshot against the live schema and
report that an intentional update is required. It never auto-updates, and it never fails
the normal build.

```
pinned upstream schema     →  which enum values exist          (source of truth)
classification policy      →  what each value means            (human-authored)
generated PHP map          →  schema × policy                  (derived)
generated fixtures         →  one test case per schema value   (derived)
runtime unknown-value rule →  non-destructive and alerting     (independent safety net)
```

**Combination of the two axes:**

| Axis A (`status`) | Axis B (`ssl.status`) | Result |
|---|---|---|
| `ACTIVE` | `ACTIVE` | `ACTIVE` |
| `ACTIVE` | pending | `PENDING_VALIDATION` |
| pending | anything not failed/revoked | `PENDING_VALIDATION` |
| either | failed | `FAILED` |
| either | revoked | `REVOKED` from `pending_removal`; otherwise `FAILED / provider_resource_missing` |
| **unknown on either axis** | — | `PENDING_VALIDATION` + `unknown_provider_state` event + admin alert |

**Unknown future provider values are non-destructive and alerting by construction** —
they can never produce `FAILED` or `REVOKED`, so a schema addition cannot cause the plugin
to tear down a working certificate.

**`caa_error` is not a status-axis enum value** and is not obtained from the status-map
generator. CAA problems surface in the **error arrays** — hostname `verification_errors[]`
and `ssl.validation_errors[]` — as messages such as
`SERVFAIL looking up CAA for app.example.com`. A separate error classifier reads those
arrays and may **annotate** a state with `code: caa_error` and a remediation hint. It
never sources a state from them and never feeds them into the generator. Raw axes and raw
error arrays are both persisted in `ssl_provider_state` for diagnostics.

---

## 15. REST API

Namespace `post-domain/v1`. Routes are **registered only when
`HostContext::kind === PRIMARY`**, so they are absent from dispatch and from `/wp-json/`
discovery everywhere else. Capability `manage_options` per route via
`pd_rest_capability`.

### 15.1 Resource

```json
{
  "id": 12,
  "revision": 7,
  "host": "xn--mnchen-3ya.example",
  "host_display": "münchen.example",
  "alias_of": null,
  "post_id": 42,
  "target": {
    "id": 42, "post_type": "club", "rest_base": "clubs",
    "rest_link": "https://primary.example/wp-json/wp/v2/clubs/42",
    "edit_link": "https://primary.example/wp-admin/post.php?post=42&action=edit",
    "derived": false
  },
  "verification": {
    "state": "verified", "verified_at": "…Z", "last_checked_at": "…Z",
    "next_attempt_at": "…Z", "last_outcome": "match",
    "hard_failure_count": 0, "transient_failure_count": 0, "deadline": null
  },
  "activation": { "state": "active" },
  "ssl": {
    "state": "active", "provider": "cloudflare-saas",
    "ownership_origin": "created", "owned_by_this_installation": true,
    "adopted_at": null,
    "method": "txt", "marker_support": "unavailable",
    "checked_at": "…Z", "next_attempt_at": null, "error": null,
    "mutation_in_progress": null,
    "provider_state": {
      "hostname_status": "active", "ssl_status": "active",
      "verification_errors": [], "validation_errors": []
    }
  },
  "serving": { "state": "serving", "reason": null, "blocked_by": null },
  "deletion": { "requested_at": null, "attempts": 0, "last_refusal": null },
  "validation_plan": {
    "dns": {
      "ownership": [ { "id": "core-ownership", "label": "Ownership TXT",
        "records": [ { "type": "TXT",
          "name": "_post-domain-challenge.xn--mnchen-3ya.example",
          "value": "post-domain-verify=9f2c…", "ttl": 300 } ],
        "apex_compatible": true, "source": "core", "removable_once_active": false } ],
      "provider_ownership": [ … ],
      "ssl_validation": [ … ],
      "routing": [ … ]
    },
    "http": [], "manual": [], "pending": [], "blockers": []
  },
  "branding": { "title": "Acme Club", "favicon_attachment_id": 91, "inherited": false },
  "created_at": "…Z", "updated_at": "…Z"
}
```

`host` is always the stored ASCII form; `host_display` is decorative. The `challenge`
**is** exposed — it is a value the domain owner must publish in public DNS, not a
credential. SSL credentials, raw marker account data, lease tokens, and mutation
authorizations appear in no response, ever. `mutation_in_progress` reports only the
mutation *kind*, its *phase*, and its expiry — for example
`{"kind":"remove","phase":"in_flight","expires_at":"…Z"}` — never the token.

`owned_by_this_installation` is computed from `ssl_ownership_origin` and
`ssl_owner_installation_id` per §12.2; the raw installation id is not exposed on the
mapping resource.

`target` links come from the post type via `get_post_type_object()`: `rest_link` only
when `show_in_rest` is true, built from that type's real `rest_base` and
`rest_namespace`. Nothing is hardcoded to `wp/v2/pages`; a mapping targeting a private,
non-REST post type simply has no `rest_link`. Alias rows carry `post_id: null` and a
`target` object derived from the canonical row, flagged `"derived": true`.

**`serving`** — computed, distinct from stored activation:

| `state` | Meaning |
|---|---|
| `serving` | resolving now |
| `unverified` | `verification_state !== verified` |
| `inactive` | `activation_state === inactive` |
| `vetoed` | `pd_mapping_is_active` returned false |
| `broken` | `ContentPolicy` invalid or `integrity_error` set — the 503 disposition |

Evaluated in that precedence order; first blocker wins. For an alias, its own blockers
are checked first, then the canonical row's, with `blocked_by` carrying `{id, host}`.

**Collections do not compute `serving` or `validation_plan`** — both require per-row
filter invocations and, for the plan, a provider read. `GET /domains` returns the raw
states; `GET /domains?_compute=serving` opts in, capped at the page size, with the cost
documented at the parameter. Individual resources always compute `serving`; the plan is
computed on the resource and on its own route.

### 15.2 Routes

| Method | Route | Notes |
|---|---|---|
| `GET` | `/domains` | paginated; filters `verification_state`, `activation_state`, `ssl_state`, `post_id`, `search` |
| `POST` | `/domains` | `{host, post_id?, alias_of?, title?, favicon_attachment_id?}`; wildcard hosts rejected |
| `GET` | `/domains/{id}` | |
| `PATCH` | `/domains/{id}` | `post_id`, `activation_state`, `title`, `favicon_attachment_id` only |
| `DELETE` | `/domains/{id}` | 202 + durable removal; `?force=true` removes locally only |
| `POST` | `/domains/{id}/verify` | on-demand check; rate-limited 1/min per mapping |
| `POST` | `/domains/{id}/challenge` | rotates; resets verification; refused while the row carries any lease |
| `GET` | `/domains/{id}/plan` | returns a `ValidationPlan` |
| `POST` | `/domains/{id}/ssl` | provision — `create()` under a `CREATE` lease |
| `PATCH` | `/domains/{id}/ssl` | `{method}` — explicit DCV method change (§14.10) |
| `DELETE` | `/domains/{id}/ssl` | authorized removal (§14.5) |
| `POST` | `/domains/{id}/ssl/adopt` | `{confirm:true, override_foreign_marker?:bool}` |
| `GET` | `/environment` | installation id, stored vs current primary host, mismatch flag |
| `POST` | `/environment/resolve` | `{choice:"restore"\|"clone"}` |

`PATCH /domains/{id}` excluding verification and SSL state is the API expression of
invariant 1: there is no request that makes a mapping verified. `verification`, `ssl`
state, ownership columns, `revision`, and `deletion` are read-only there. `post_id` on an
alias row returns `pd_alias_no_target`. Every `…/ssl` mutation and `…/challenge` requires
`If-Match` and acquires the mutation lease, which requires a lease-free row; any
existing lease — including an expired one awaiting recovery — returns
`pd_mutation_in_progress`.

### 15.3 Errors

`pd_host_invalid` (400), `pd_host_malformed_authority` (400), `pd_host_wildcard` (400),
`pd_host_exists` (409), `pd_host_too_long` (400, naming the composed TXT length),
`pd_label_invalid` (400), `pd_alias_chain` (400), `pd_alias_no_target` (400),
`pd_post_invalid` (400), `pd_alias_in_use` (409), `pd_conflict` (409),
`pd_precondition_required` (428), `pd_precondition_failed` (412),
`pd_rate_limited` (429), `pd_environment_unresolved` (409),
`pd_mutation_in_progress` (409), `pd_mutation_unauthorized` (409, naming the failing
precondition), `pd_unowned_resource` (409), `pd_provider_create_ambiguous` (409),
`pd_method_unsupported` (400), `pd_forbidden` (403).

Any of these requested on a non-primary host produces a plain 404 from WordPress, because
the route does not exist there.

---

## 16. Admin

### 16.1 Screens

**Domains** — host (Unicode display, ASCII on hover), target, and three state chips.
The computed `serving` chip appears only on expanded rows, matching the collection
split. Row actions: verify now, rotate challenge, provision SSL, deactivate, delete.
Bulk: activate, deactivate, verify. Actions that require the mutation lease are disabled,
with an explanation, while one is held.

**Add domain** — one screen, three steps, no server-side wizard state: host → target
post (post-type-agnostic search restricted to the configured types) → the validation
plan. The row is created `pending`/`inactive` at step one so the challenge exists before
instructions are displayed. Wildcard input is rejected at entry.

**Domain detail** — the validation plan, rendered so an operator can tell the four
purposes apart at a glance:

| Section | What it says |
|---|---|
| Ownership (post-domain) | permanent; **must never be removed** |
| Hostname ownership (provider) | may be removed once the provider reports the hostname active |
| Certificate validation (provider) | appears when the CA issues tokens; TXT, a delegated CNAME, an HTTP token, or a manual email step |
| Routing | where the customer points the hostname, including which apex mechanism applies |
| Awaiting provider | records not yet issued — a wait, not a failure |
| Blockers | what must be fixed before this can work |

Alternatives inside a purpose are rendered as "create any one of these"; every record
inside a chosen set is marked required. HTTP tokens are shown as a URL and body to serve,
never as a DNS record. Also on this screen: ownership provenance (created / adopted / no
authority, and whether this installation is the owner); a live "last checked / next
attempt / last outcome" block; any lease currently held, with its kind, **phase**, and
expiry — `RESERVED` reads as "preparing, nothing sent", `IN_FLIGHT` as "sent, awaiting
result", `RECOVERING` as "outcome unknown, reading provider state"; the
event log, labelled as history rather than as the source of any decision; raw
`ssl_provider_state`; SSL actions.

**Delete** — shows the authorization checklist (§14.4) with the outcome of each
precondition, so a refused deletion says *which* check failed rather than "try again".
Force-local-delete is presented separately and states plainly that it removes only the
local row and that a provider resource may be left behind.

**Settings** — target post types, SSL driver, DCV method, DoH endpoints, apex routing
configuration (with the entitlement and provenance explained inline), admin-redirect
toggle, asset-proxy toggle, and generated web-server/CDN snippets for the 421 rule and
CORS.

**Diagnostics** — sweep backlog depth and oldest due timestamp; WP-Cron health;
round-trip failures; path collisions; absolute primary-host URLs found in a rendered
mapped page; the browser-side CORS probe; SSL resources that are unowned, missing,
divergent in method, or ambiguous after a create; marker support; mutation leases by
phase, including expired `RESERVED` leases awaiting a no-read clear and rows stuck in
`RECOVERING` because provider state is still incomplete or transient; **rows fenced in
`RECOVERING` because the driver or provider environment they began against is no longer
configured, naming the `ssl_mutation_driver` and `ssl_mutation_environment` an operator
must restore, and separately the bound resources whose `ssl_provider_environment` no
longer matches the configured driver — which are not fenced, merely unreadable**; apex configuration status; the environment-mismatch banner.

### 16.2 Operator flows

*Add a domain*: create → publish the ownership TXT → wait → verified → activate →
optionally provision SSL, which adds provider-ownership, certificate-validation, and
routing requirements to the same plan. Nothing serves until verified **and** active.

*Domain stops working*: the event log distinguishes "customer removed the TXT record"
(three hard failures, `failed`) from "our resolver was unreachable" (transient, still
verified) without anyone reading a log file.

*Ambiguous provisioning*: when a create times out on an account without markers, the
detail screen says a resource may exist, shows what was observed, and offers the explicit
adoption workflow. Nothing is bound automatically.

*Provider reconfigured mid-flight*: if the selected driver or its account changes while a
mutation is unresolved, the detail screen names the driver and environment identity the
mutation began against and says plainly that the outcome cannot be learned until that
configuration is restored. The plugin queries nothing in the meantime.

*Provider reconfigured after provisioning*: a certificate that already exists in one
account does not move because the plugin was repointed at another. The detail screen names
the environment the resource lives in, reports that its state cannot be refreshed, and
leaves the last known state alone. Nothing is asked of the new account about it, because
the new account has never heard of it.

*Move / restore / clone*: the blocking banner forces the choice before any provider
mutation runs.

*Remove a domain*: `202`, serving stops immediately, the authorization checklist runs
inside a lease, and cleanup retries durably; the local row is removed only after external
cleanup succeeds, or by an explicit force-local-delete that issues no provider deletion.

---

## 17. Testing

**Unit (no WordPress):** `AuthorityParser` over a malformed-input table — `host:`,
`host:0`, `host:99999`, `host:abc`, `[::1`, `::1:80`, `a b`, `a\tb`, `user@host`,
`host/path`, embedded NUL and control characters — each asserted to reach
`MALFORMED_400`, plus the assertion that **no malformed authority can reach the allowlist
comparison**; wildcard host rejection; `IdnaNormalizer` against the fixed UTS-46 vectors,
including Unicode ⇄ punycode deduplication; `PathDecomposer`; `PathNormalizer`; `Subtree`
round-trip as a **property** over a generated fixture tree; collision ambiguity; grace
arithmetic and counter resets; `CanonicalPolicy`; `UrlPolicy`; `Classifier`; the
Cloudflare status map, one case per schema value, generated from the pinned input;
generator failure when a schema value lacks a classification or the cardinality differs;
DoH response-shape rejection; `HostValue` / `AbsoluteUrl` validators including
scheme-downgrade rejection; `ApexCapability` validation, including rejection of
`APEX_PROXY` without attested provenance and the assertion that an ordinary origin
address can never become an emitted A record.

**Translation (unit + recorded fixtures):** every `ssl.validation_records` case — TXT,
delegated CNAME, HTTP token, email, empty, malformed, multiple-required,
genuine-alternative; every `ownership_verification` / `ownership_verification_http` case —
TXT only, HTTP only, both present rendered as OR, neither present while pending rendered
as `ValidationPending`, `status: active` suppressing completed instructions, malformed
data rendered as a blocker, and token replacement recomputed and evented. All four
purposes asserted distinct, with no substitution and no invented record.

**Integration (wp-env):** the §7.2 compatibility matrix asserted on **rendered output**,
not on filter registration; query-string preservation through the unmatched redirect; the
full disposition matrix (400 / 421 / 404 / 503 / serve) across host states, with an
allowlisted host and a malformed near-match of it asserted to diverge; admin redirect
method-awareness (302 vs 307) and the admin-ajax exemption; REST management absent from
discovery on a mapped host; CORS header presence and exact value; feed and sitemap
membership enforcement with an injected non-member; empty `post__in` short-circuit; CAS
conflict returning 409; verification-lease conflict discarding a DNS result; uninstall
leaving posts untouched.

**Lease and authorization (integration, against a fake driver and a recorded-fixture
Cloudflare client):**

- lease acquisition refused while any lease exists, in any phase, with no provider call
- **normal acquisition against an expired `RESERVED` lease affects zero rows**
- **normal acquisition against an expired `IN_FLIGHT` lease affects zero rows and causes
  zero provider mutations**
- **normal acquisition against an expired `RECOVERING` lease affects zero rows and causes
  zero provider mutations**
- ordinary queues — verification, SSL, reconciliation, deletion, Admin, REST, CLI — skip
  leased rows in all three phases, unexpired and expired alike
- only the dedicated `LeaseRecovery` selector finds work by lease expiry; no
  ordinary-work selector does, while the phase-specific recovery CAS statements correctly
  pin the expired state they transition
- an expired `RESERVED` row becomes ordinarily eligible **only after** its no-read
  cleanup CAS succeeds
- expired `IN_FLIGHT` and `RECOVERING` rows cannot become ordinarily eligible by token
  overwrite
- force-local-delete refused against every existing lease — `RESERVED`, `IN_FLIGHT`, and
  `RECOVERING`, each tested both unexpired and expired — and likewise for a local delete
  where no provider resource exists
- **no provider mutation occurs while the lease is only `RESERVED`** — the driver's
  mutating methods are never entered
- the `RESERVED → IN_FLIGHT` CAS consumes the authorization **before** the driver is
  invoked, asserted by ordering, not by inspection after the fact
- the same authorization cannot begin a second mutation execution
- a failed begin-mutation CAS — stale revision, wrong token, wrong kind, wrong phase,
  expired lease, or any changed bound value — results in **zero provider calls**
- challenge rotation, provider change, method change, adoption, removal, and
  force-delete all refused under a foreign lease; activation and branding permitted
- an expired `RESERVED` lease cleared **without any provider read**
- cleanup of an expired `RESERVED` lease racing begin-mutation: exactly one side wins,
  and no provider call occurs when cleanup wins
- expired `IN_FLIGHT` recovery replaces the token and enters `RECOVERING` **before**
  reading provider state
- the fenced original worker cannot apply its result, and does not retry the provider
  mutation
- only the recovery-token owner can finalize
- an expired `RECOVERING` lease can be taken over by another worker without the previous
  recovery worker being able to clear or finalize the new one
- a known provider result and the lease clear applied in **one** atomic transition
- a transaction already open on the session is never committed or rolled back by this
  plugin, and the caller's transaction and its unrelated writes survive intact
- a failed transaction probe stops the transition before it begins
- an uncertain `COMMIT` is never reported as success and never triggers an immediate
  repeat of the provider mutation, and is **never resolved by re-reading the same
  connection** — while the transaction is unresolved that connection sees its own
  uncommitted writes, so a later pass with a committed view settles it
- transient recovery results cause another bounded read, never another provider mutation
- the driver id and provider-environment identity are written into the lease **before**
  any provider call, pinned by the consumption CAS, inherited unchanged through recovery
  takeovers, and cleared only with the rest of the lease
- a create or adoption begun with `ssl_provider = NULL`, followed by a change of selected
  driver or of the provider account/zone, recovers against **neither** environment: zero
  reads and zero mutations reach the newly configured driver, the row stays fenced in
  `RECOVERING`, and the report names the driver and environment to restore
- the same mutation resolves normally once the original configuration is restored
- an already-bound mapping is likewise unaffected by a change to the configured default
- a successful create or adoption promotes the mutation environment into
  `ssl_provider_environment` in the same CAS that writes `ssl_provider` and `ssl_ref`
- with a bound resource and a drifted configuration, `status()`, `identify()`,
  `reconcile()`, validation planning, method change, deletion authorization, and
  deletion execution all perform **zero** provider reads and **zero** provider
  mutations, and change no local provider state
- restoring the original environment lets every one of those resume
- clone resolution clears `ssl_provider_environment` with `ssl_provider`,
  `ssl_ref`, and the ownership columns
- REST and Diagnostics distinguish the durable resource environment from the
  environment of a mutation currently in flight
- the lease TTL and recovery grace exceed the driver's provider HTTP timeout by the
  documented margin
- an expired worker unable to clear a newer worker's lease
- each of the deletion preconditions failing individually, producing a refusal with no
  provider mutation
- authorization rejected after a concurrent revision bump, and when host, provider, ref,
  challenge, or kind differ; expiry; single use enforced by the consuming CAS
- ownership provenance read from columns: deletion still authorized after **all** events
  for the mapping have been pruned
- a clone unable to delete or change the original installation's resource; a restore
  retaining authority
- adoption refused without `confirm`, without a fresh proof, and without
  `override_foreign_marker` against a foreign marker

**Create and recovery (integration):**

- simultaneous first-create requests: exactly one POST is sent
- create succeeds but the response is lost, marker matches ⇒ `RECOVERABLE_CREATE`, bound,
  recorded as recovery and **not** as adoption
- create succeeds but the response is lost, markers unavailable ⇒
  `provider_create_ambiguous`, nothing bound, adoption required
- conclusive absence ⇒ safe retry under a new lease and a fresh authorization
- conflicting marker ⇒ `unowned_resource`, nothing mutated
- finalize CAS failure while persisting the returned reference, with the result discarded
  and the POST not repeated
- mutation lease expiring during ambiguous-create reconciliation, with recovery fencing
  first and the ambiguous `CREATE`, `ADOPT`, `METHOD_CHANGE`, and `REMOVE` outcomes each
  resolved by their documented read-first path

**Method change (integration):** lease conflict; stale authorization; provider ambiguity
leaving local state unchanged; success persisted only after the provider re-read confirms
the method; reconciliation reporting divergence without auto-patching.

**Provider behaviour:** error 1413 setting `marker_support = UNAVAILABLE` and permitting
**only** the documented single retry without `custom_metadata` inside the same execution,
asserted alongside a companion case proving that a timeout, connection reset, or 5xx
grants **no** such retry; the driver operating end to end with markers unavailable;
reconciliation with `snapshot_complete = false` never inferring a missing resource.

---

## 18. Build, release, uninstall

Composer with `symfony/polyfill-intl-idn:1.38.1` and `jeremykendall/php-domain-parser`
pinned exactly, `composer.lock` committed, `composer audit` in CI, and PHP-Scoper
prefixing all vendor code into `PostDomain\Vendor\` so the shipped plugin cannot collide
with or be hijacked by another plugin's autoloader. The release artifact is the prefixed
build, never the raw vendor tree. PHPCS against WordPress-Extra; PHPStan level 8.

The Cloudflare status map is generated at build time from the pinned snapshot (§14.18);
CI fails if the committed map differs from a fresh generation, if any schema value lacks
a classification, or if the enum cardinality differs from the recorded expectation.

Minimum: **WordPress 6.4, PHP 8.1.** No PHP extension is a hard requirement.

`uninstall.php` drops `pd_domains` and `pd_domain_events` and deletes
`pd_schema_version`, `pd_schema_engine`, `pd_settings`, `pd_ssl_credentials`,
`pd_installation_id`, `pd_installation_primary_host`, `pd_environment_mismatch`,
`pd_provider_cooldowns`, and all cron events. **No post, meta, or option belonging to
anything else is touched.**

Uninstalling destroys the ownership provenance along with the rows, so any provider
resources still outstanding become unowned from the plugin's perspective. The README says
so: delete mappings — which runs the durable-deletion workflow — **before** uninstalling,
or clean the provider up by hand afterwards.

---

## 19. Documentation deliverables

A README covering:

- installation, and the WordPress/PHP minimums
- that mapped hosts are exact hosts and wildcards are out of scope (§14.16)
- the DNS records a domain owner must create, which of the four purposes each belongs
  to, and why the post-domain ownership TXT record must **stay** while a provider
  ownership record may be removed once active
- the complete filter reference with defaults, postconditions, and examples
- the `init : 99` registration requirement and the early-URL limitation
- the `SslDriver` interface, `SslResourceContext`, and how to add a driver, including how
  a driver expresses its own ownership-proof mechanism
- ownership provenance: what `created`, `adopted`, and "no authority" mean, that it lives
  in columns rather than in the event log, and that events are history only
- the provider-mutation lease and its phases: what `RESERVED`, `IN_FLIGHT`, and
  `RECOVERING` mean, what the lease blocks, why challenge rotation and method changes are
  refused while one is held, that an expired lease still blocks ordinary work rather than
  freeing the row, why an expired `RESERVED` lease is safe to clear without contacting
  the provider while an expired `IN_FLIGHT` one is not, and what a row stuck in
  `RECOVERING` is telling an operator
- the authorization model: what a provider mutation requires, why cached verification is
  not enough, and what each refusal means
- creation ambiguity: why a marker-free create-then-timeout may require explicit adoption
- adoption: when it is needed, what it requires, and what it records
- clone detection and the restore/move/clone choice
- the `pd_dns_resolver` trust boundary
- the DCV method choice, the `txt` default, why `email` is not automated, and that
  automatic HTTP DCV is a valid success path
- apex routing: CNAME flattening, ALIAS/ANAME, and that A/AAAA targets are permitted only
  with an attested Apex Proxying or BYOIP entitlement — never ordinary origin addresses
- the **authoritative-DNS deployment posture** (§14.14): provider neutrality, Cloudflare
  DNS as a recommendation rather than a requirement, the separation of DoH resolvers /
  Cloudflare for SaaS / authoritative DNS, apex requirements on other providers, that no
  DNS is mutated by API, that no paid Custom Metadata or Apex Proxying is assumed, and
  the client-owned-account recommendation
- the multisite exclusion and its reasoning
- the 421 default, the exact infrastructure allowlist, and the web-server rule the plugin
  cannot apply
- the CORS hosting boundary
- the auth consequences of mapped-host REST and admin-ajax
- deleting mappings before uninstalling, and what uninstall does to ownership provenance

---

## 20. Open items

One item is deliberately deferred to implementation:

**`CteSubtreeAdapter` capability matrix.** The concrete MySQL and MariaDB
minimum-version matrix (nominally MySQL 8.0, MariaDB 10.2.2) must be confirmed against
the actual target environments before the adapter is enabled there. Deferring this is
safe by construction: the adapter is explicitly capability-gated, has its own integration
tests, returns post IDs rather than injecting raw JOIN or WHERE fragments, falls back to
enumeration, and an unbounded scope is never executed.
