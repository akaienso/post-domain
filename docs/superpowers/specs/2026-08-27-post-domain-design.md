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
      DbRepository.php  AliasResolver.php
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
      DeletionAuthorizer.php  DeletionAuthorization.php  DeletionRefusal.php
      AdoptionAuthorizer.php  AdoptionAuthorization.php
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
plugin-owned guard outside the driver answers authorization. Collapsing them is how a
stale local flag becomes a deletion.

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
7. **Provider deletion requires fresh, installation-bound authorization** (§14.5). No
   filter grants it.

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
| `pd_is_apex` | `(?bool, string $host)` → PSL-derived |

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
| `pd_deletion_authorization_ttl` | `(int seconds)` → `120` |
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
`pd_ssl_resource_adopted`, `pd_ssl_deletion_refused` (with the failing precondition),
`pd_request_resolved`, `pd_request_unmatched`, `pd_environment_mismatch_detected`.

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
| `pd_ssl_validation_method` | Must be one of `{http, txt, email}`; else the configured default. `http` is rejected for wildcard hostnames (§14.10). |
| `pd_deletion_authorization_ttl` | Clamped `30..300` seconds. |
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
ssl_ref                  VARCHAR(191)      NULL
ssl_owned                TINYINT(1)        NOT NULL DEFAULT 0
ssl_adopted_at           DATETIME          NULL
ssl_adopted_by           BIGINT UNSIGNED   NULL
ssl_method               VARCHAR(10)       NULL          -- persisted DCV method
ssl_method_requested_at  DATETIME          NULL
ssl_marker_support       VARCHAR(20)       NULL          -- supported|unavailable|unknown
ssl_checked_at           DATETIME          NULL
ssl_next_attempt_at      DATETIME          NULL
ssl_transient_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0
ssl_provider_state       TEXT              NULL          -- JSON, raw provider axes + errors
ssl_error                TEXT              NULL          -- JSON {code,message,at}

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

**Row shape:**

```
alias_of IS NULL      =>  post_id IS NOT NULL        (canonical row)
alias_of IS NOT NULL  =>  post_id IS NULL            (alias row)
alias target must itself have alias_of IS NULL       (no chaining)
```

`alias_of` is a self-reference with no FK (`dbDelta` cannot express one portably);
orphan cleanup runs in the repository on delete, and a scheduled integrity check
reports strays.

### 12.2 `{$wpdb->prefix}pd_domain_events`

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

Every state transition, every adoption, and every refused deletion writes one row. This
is the support artifact — "it stopped working on Tuesday" becomes answerable, and
`resolver_class` makes it visible which code performed each ownership proof. Retention
90 days, pruned daily.

**Atomicity:** when `pd_schema_engine` is InnoDB, the state change and its event row are
written in one transaction. On any other engine the transaction is skipped and the
event log is **best-effort** — it can lag or miss rows. Nothing reads it to make a
decision; it is never an input.

### 12.3 Timestamps and storage hygiene

All `DATETIME` columns are **UTC**, written with `gmdate('Y-m-d H:i:s')`.
`current_time()` is never called — a site-local timestamp in a scheduling column
produces silent off-by-hours behaviour across DST. REST emits RFC 3339 with `Z`.

`ssl_error` is JSON `{code, message, at}`; the message is truncated to 500 bytes and
passed through a redactor stripping bearer-token, API-key, and `Authorization:` shapes.
Raw provider bodies are never stored. Credentials never appear in a row, a response, an
event, or a log line. Deletion authorizations are in-process values only: never
persisted, never serialized into an event, never logged.

### 12.4 Compare-and-swap

```sql
UPDATE … SET …, revision = revision + 1, updated_at = ?
WHERE id = ? AND revision = ?
```

Zero affected rows ⇒ `pd_conflict` (409) for REST; bounded re-read-and-retry (3
attempts) for cron and CLI. REST exposes `revision` and `ETag: "<id>-<revision>"`.
`If-Match` is **required** on `PATCH`, `DELETE`, `POST /challenge`, and any `…/ssl`
mutation: missing ⇒ `428`, stale ⇒ `412`. `POST /verify` is exempt (idempotent probe).

### 12.5 State machines

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

**Activation** — written only by admin and REST; never by verification or SSL.
`inactive ⇄ active`. A mapping may be `active` while `pending`, letting an operator
pre-stage a domain. Serving requires `verified && active && pd_mapping_is_active`.

**SSL** — owned by `Ssl\*`; **never gates serving** (TLS terminates before PHP runs; if
the request arrived, the certificate question is already settled).

```php
enum SslState { case NONE; case REQUESTED; case PENDING_VALIDATION; case ACTIVE;
                case FAILED; case PENDING_REMOVAL; case REVOKED; }
```

| Situation | From | To |
|---|---|---|
| `ensure()`, provider accepts | `none`/`failed`/`revoked` | `requested` |
| `ensure()`, provider reports already issued **and identity matches** | `none`/`requested` | `active` (immediate) |
| `ensure()`, resource exists but identity does not match | unchanged | `failed` / `unowned_resource` |
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
record must remain in DNS permanently** — re-checks read it, and provider-deletion
authorization re-proves against it. Aliases verify at their own name.

`pd_txt_record_label` runs **only** at create/rotate; its validated result is persisted
in `challenge_label`. Ordinary verification composes the name from persisted data and
never re-runs the filter. If persisted values fail validation at read time the row is
**corrupt**: `integrity_error` is set, the disposition becomes `BROKEN_503`, and
verification halts. It does not keep serving on a soft warning.

Full TXT-name validation: composed name ≤ 253 bytes, each label 1–63, ≤ 127 labels,
label charset enforced.

This record is the **plugin's** ownership proof and is entirely separate from any
provider's own hostname-ownership or certificate-validation records (§14.11). None of
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
read whatever the domain's own authoritative provider publishes (§14.12).

**Transport hardening:** HTTPS enforced on the endpoint; `redirection => 0`; response
capped at 64 KB; JSON `Content-Type` required; shape validated (`Status` an integer,
`Answer` an array of objects, `type === 16` for TXT) before any field is read. Non-200,
malformed JSON, wrong shape, oversize, or redirect ⇒ **`TRANSIENT`**. Endpoints come
only from the default list or the filter; nothing derives an endpoint from request data.

`NativeDnsResolver` remains for environments that cannot make outbound HTTPS calls,
with a hard restriction: **it may emit only `MATCH`, `MISMATCH`, or `TRANSIENT`.** Every
empty or failed lookup is `TRANSIENT`, so it can never deactivate a verified mapping —
correct behaviour for a resolver that cannot distinguish "record gone" from "resolver
unwell." Selecting it raises a persistent admin notice, and it can never satisfy the
fresh-proof requirement for provider deletion when it returns `TRANSIENT`.

Multi-string TXT values are concatenated per RFC before matching; comparison is
`hash_equals`; **all** returned TXT values are examined, since a domain legitimately
carries many.

### 13.3 `pd_dns_resolver` is trusted code

A custom resolver **substitutes the domain-ownership proof mechanism**; it does not
integrate with it. A resolver returning `MATCH` unconditionally disables verification
entirely and would also satisfy the deletion fresh-proof requirement. Installing a
non-default resolver raises a persistent admin notice naming the class, and the class is
recorded on every verification event and every deletion authorization.

### 13.4 Fresh proof

```php
final class FreshProof {
    public function prove( Mapping $m ): DnsOutcome;   // no cache, no stored state
}
```

`FreshProof` performs a live resolution of the mapping's **current persisted** challenge
under the normal two-resolver agreement rules and returns the outcome. It never reads
`verification_state`, `verified_at`, or `last_outcome`. It exists because
**cached verification state is not sufficient authorization for a provider deletion**
(§14.5), and because a rotated challenge must invalidate any authority a copy of the
database might otherwise claim.

### 13.5 Queue, budget, and leases

**Due-work queries** select directly on persisted next-attempt columns — no table
scans, no heuristics:

```sql
SELECT … FROM {prefix}pd_domains
WHERE verification_state IN (…) AND integrity_error IS NULL
  AND verify_next_attempt_at IS NOT NULL AND verify_next_attempt_at <= :utc_now
ORDER BY verify_next_attempt_at ASC
LIMIT :batch
```

Every transition sets the next attempt explicitly: `pending` +15 min; `verified` +24 h;
transient backoff +30 min growing exponentially to a 6 h cap; SSL transient honours
`Retry-After`; deletion retry exponential 1 min → 6 h. `NULL` means not due, which is
how terminal, corrupt, and `pending_removal` rows stay out of the queue without special
cases.

**Run-time budget:** `pd_sweep_budget_seconds` (default 20) and a batch cap. If rows
remain due, the run schedules **one** continuation 60 s out rather than looping, so a
5,000-domain backlog drains across ticks instead of timing out the same first batch
forever. Diagnostics reports backlog depth and the oldest due timestamp.

**Token-owned locks:** the sweep lock holds `{token, expires_at}`; acquisition is a
conditional insert, release is compare-and-delete on the token, so a slow run whose
lock has expired cannot release a newer run's lock.

**Per-mapping lease:** acquired by CAS before any DNS query, and the result applied
under CAS on `(id, revision, verify_lease_token)`. **If that CAS fails the DNS result is
discarded — never replayed.** The row changed underneath the attempt (rotated challenge,
retarget, deletion) and the result answers a question no longer being asked. The next
sweep resolves again. `verify_lease_token` doubles as the attempt id on the event.

### 13.6 Cron topology

| Hook | Schedule | Work |
|---|---|---|
| `pd_verify_pending` | 15 min | due `pending` rows, batched |
| `pd_verify_established` | hourly sweep, ~daily per row | due `verified` and transient-backoff rows |
| `pd_ssl_sweep` | 15 min | `requested`, `pending_validation`, `pending_removal`, stale `active` |
| `pd_maintenance` | daily | event pruning, orphan-alias check, full `Reconciler` pass, dangling-target scan |

WP-Cron is unreliable on low-traffic sites. The README documents the system-cron
alternative (`DISABLE_WP_CRON` + `wp cron event run --due-now`), and Diagnostics reports
the age of the oldest overdue check, so "verification silently stopped" is visible
rather than inferred.

---

## 14. SSL subsystem

The whole subsystem rests on one separation: **identity** is what the provider says a
resource is; **authorization** is whether this installation may change it. The driver
answers the first. A plugin-owned guard, outside every driver, answers the second.

### 14.1 Resource context

Provider operations never receive a bare host. A host alone cannot express the
mapping-specific rules this design requires, so every call carries a typed context:

```php
final class SslResourceContext {
    public readonly int     $mapping_id;
    public readonly string  $host;              // normalized ASCII
    public readonly string  $installation_id;
    public readonly string  $provider_id;       // stored on the mapping
    public readonly ?string $provider_ref;      // stored resource reference, when present
    public readonly string  $challenge_name;    // persisted composed TXT name
    public readonly string  $challenge_value;   // persisted expected value
    public readonly int     $revision;          // mapping revision at read time
    public readonly ?string $requested_method;  // persisted DCV method
    public readonly bool    $is_wildcard;
}
```

### 14.2 Identity

```php
enum IdentityVerdict { case MATCH; case MISMATCH; case ABSENT; case AMBIGUOUS; case UNKNOWN; }
enum MarkerSupport   { case SUPPORTED; case UNAVAILABLE; case UNKNOWN; }

final class ProviderMarker {
    public readonly ?string $installation_id;
    public readonly ?int    $mapping_id;
    public readonly array   $raw;
}

final class IdentityResult {
    public readonly IdentityVerdict $verdict;
    public readonly ?string $expected_ref;        // what we stored
    public readonly ?string $observed_ref;        // what the provider returned
    public readonly ?string $observed_hostname;   // the exact hostname on the resource
    public readonly ?ProviderMarker $marker;      // parsed marker, when the provider has one
    public readonly MarkerSupport   $marker_support;
    public readonly bool    $read_complete;       // complete and authoritative
    public readonly bool    $transient;
    public readonly ?string $code;
    public readonly ?string $message;
}
```

`MATCH` requires **all** of: `read_complete`; `observed_ref === expected_ref`;
`observed_hostname === $ctx->host` byte-for-byte after normalization; and no conflicting
marker. Anything less is `MISMATCH`, `AMBIGUOUS`, `ABSENT`, or `UNKNOWN`.

**Markers are defence in depth, never the basis of ownership.** A marker naming this
installation is *additional* evidence. A marker naming a different installation, or a
different mapping, **blocks mutation**. An *absent* marker establishes nothing either
way — it neither proves nor disproves ownership, and it must not block when the provider
does not support markers at all. `marker_support = UNAVAILABLE` (see §14.9 on Cloudflare
error 1413) means the account cannot store markers; the driver must keep working, using
the reference-plus-hostname binding and the plugin's fresh DNS proof instead.

### 14.3 Driver contract

```php
final class DriverCapabilities {
    public readonly bool $supports_markers;
    public readonly bool $supports_http_dcv;
    public readonly bool $supports_apex_targets;
    public readonly array $validation_methods;   // e.g. ['http','txt','email']
}

interface SslDriver {
    public function id(): string;
    public function capabilities(): DriverCapabilities;

    public function ensure(   SslResourceContext $ctx ): SslStatus;      // idempotent
    public function status(   SslResourceContext $ctx ): SslStatus;
    public function identify( SslResourceContext $ctx ): IdentityResult;

    public function adopt( SslResourceContext $ctx, AdoptionAuthorization $auth ): SslStatus;
    public function remove( SslResourceContext $ctx, DeletionAuthorization $auth ): RemovalResult;

    /** @param SslResourceContext[] $contexts */
    public function reconcile( array $contexts ): ReconcileReport;

    public function validation_plan( SslResourceContext $ctx, bool $is_apex ): ValidationPlan;
}

enum RemovalOutcome { case REMOVED; case PENDING; case TRANSIENT; case FAILED; }

final class SslStatus {
    public readonly SslState $state;
    public readonly ?string  $ref;
    public readonly ?string  $code;       // sanitized
    public readonly ?string  $message;    // sanitized, ≤ 500 bytes
    public readonly bool     $transient;  // true => caller must not change state
    public readonly ?array   $provider_state;   // raw axes + error arrays, for storage
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

`ensure()` returns immediately with whatever the provider says now, never blocks on
issuance, and is safe to call repeatedly — a double-clicked button or a retried cron
cannot create two resources. `$transient === true` means "no information": the caller
advances neither state nor `ssl_checked_at`.

**Duplicate handling never adopts.** When `ensure()` finds an existing resource for the
host, it calls `identify()`. `MATCH` ⇒ return the current state, which is what makes the
call idempotent. Anything else ⇒ `SslStatus` with `state = FAILED`,
`code = unowned_resource`; the reference is **not** stored, `ssl_owned` is **not** set,
and nothing at the provider is touched.

`remove()` returning an enum rather than `void` separates "gone" from "we asked".
`REMOVED` ⇒ `revoked`, row proceeds to hard delete. `PENDING` ⇒ stays `pending_removal`.
`TRANSIENT` ⇒ no state change, `deletion_attempts` **not** incremented, next attempt
honours `retry_after`. `FAILED` ⇒ stays `pending_removal`, attempts incremented toward
the force-delete ceiling.

**`snapshot_complete` is load-bearing.** When false, absence from the snapshot means
nothing and the reconciler must not infer `provider_resource_missing`; only observed
rows are updated and the reason is logged.

### 14.4 Registry

```php
final class SslDriverRegistry {
    public function register( SslDriver $d ): void;   // via pd_ssl_drivers
    public function get( string $id ): ?SslDriver;
    public function default(): SslDriver;             // site setting; NullDriver fallback
}
```

A mapping **always** uses the driver id stored in `ssl_provider`, set once when SSL is
first requested. Changing the site default never migrates existing mappings. An
unregistered stored id ⇒ no operations attempted, serving unaffected, reported as
`ssl_driver_missing`. Every mutation additionally requires the stored `ssl_provider` to
equal the driver actually selected for the call; a mismatch is refused before any
network activity.

### 14.5 Deletion authorization

`Ssl\DeletionAuthorizer` lives **outside** every driver and is the only producer of a
`DeletionAuthorization`. No driver may delete without one, and no driver may construct
one.

```php
final class DeletionAuthorization {   // in-process only: never persisted, serialized, or logged
    public readonly string $token;            // random, single-use
    public readonly int    $mapping_id;
    public readonly int    $revision;
    public readonly string $host;
    public readonly string $provider_id;
    public readonly string $provider_ref;
    public readonly string $challenge_hash;   // hash of the persisted challenge name + value
    public readonly DateTimeImmutable $expires_at;   // pd_deletion_authorization_ttl, default 120 s
}
final class DeletionRefusal {
    public readonly string $precondition;   // which one failed
    public readonly bool   $transient;
    public readonly ?string $detail;
}
```

**All of the following must hold**, evaluated in order; the first failure returns a
`DeletionRefusal` and nothing at the provider is touched:

1. The environment / clone check is **resolved** — no outstanding
   `pd_environment_mismatch`.
2. The stored `ssl_provider` matches the selected driver, and that driver is registered.
3. A **fresh** `identify()` returns `MATCH` with `read_complete = true`, confirming that
   the stored `provider_ref` belongs to **exactly** the mapped host, and carrying no
   conflicting marker.
4. The resource was created by this installation (`ssl_owned = 1` with a matching
   `installation_id`) **or** was explicitly adopted (§14.6), evidenced by
   `ssl_adopted_at` and a recorded adoption event.
5. `FreshProof::prove()` returns `MATCH` for the mapping's **current persisted**
   challenge, under the normal two-resolver agreement rules. Cached verification state
   is never sufficient.
6. An immediate re-read under CAS confirms that `revision`, `challenge`, `ssl_provider`,
   `host`, and `ssl_ref` are unchanged since the checks above.

The token is single-use and bound to every value in the struct. The driver must verify
those bindings against the `SslResourceContext` it was handed and refuse otherwise. Any
concurrent mapping change invalidates it, as does expiry.

**Blocking, non-destructive conditions:** a transient DNS result, resolver disagreement,
provider ambiguity, an incomplete provider read, or a concurrent mapping change all
block deletion **without changing provider state**. The mapping stays in
`pending_removal`, `deletion_attempts` is not incremented for transient refusals, and
the next attempt is scheduled.

**`ssl_owned` alone is never sufficient.** It is local state that a database restore or
a manual provider edit can falsify; it is one of six preconditions, not the decision.

**Consequences that fall out of this design, by construction:**

- A **clone** rotates every challenge and clears `ssl_owned`, so preconditions 4 and 5
  can never pass against the original installation's resources. A clone cannot delete
  them, and no additional rule is needed to prevent it.
- A **restore or move** that retains the installation identity and the challenges is the
  same installation; all six preconditions pass normally.
- `FOREIGN`, conflicting, or mismatched resources fail precondition 3 and are never
  deleted.
- `UNKNOWN` or incomplete identity is transient and non-destructive.

**Forced local deletion** (`DELETE …?force=true`) removes the **local row only**, writes
an event recording that a provider resource may have been left behind, and **never**
bypasses this guard or issues an unauthorized provider deletion.

### 14.6 Adoption

Adoption is a provider mutation workflow with its own driver operation, not merely a
REST route.

```php
final class AdoptionAuthorization {
    public readonly string $token;            // single-use, same binding rules as deletion
    public readonly int    $mapping_id;
    public readonly int    $revision;
    public readonly string $host;
    public readonly string $provider_id;
    public readonly string $observed_ref;     // the resource being adopted
    public readonly string $challenge_hash;
    public readonly bool   $override_foreign_marker;
    public readonly DateTimeImmutable $expires_at;
}
final class AdoptionAuthorizer { public function authorize( Mapping $m, array $request ): AdoptionAuthorization|DeletionRefusal; }
```

Preconditions: environment resolved; driver matches; a fresh `identify()` with
`read_complete = true` returning a resource whose `observed_hostname === host`; an
explicit `confirm: true`; and a fresh `FreshProof::prove()` returning `MATCH`. When the
observed marker names a **different** installation, adoption additionally requires
`override_foreign_marker: true` — a deliberate second key for taking over another
installation's resource.

`adopt()` writes the marker when the provider supports markers, and the plugin sets
`ssl_owned = 1`, `ssl_adopted_at`, `ssl_adopted_by`, and `ssl_ref = observed_ref`,
recording an event that names any prior marker. **Adoption is never automatic and never
implicit**: finding a duplicate resource does not adopt it, reconciliation does not adopt
anything, and `ensure()` cannot adopt.

### 14.7 Environment and clone detection

Options `pd_installation_id` (UUID, generated at install) and
`pd_installation_primary_host` (the `home` host at install). Checked on `admin_init` and
at the top of every sweep — never on the front-end path. A mismatch sets
`pd_environment_mismatch` and, while set:

- **every provider mutation is blocked** — `ensure`, `adopt`, `remove`, `reconcile`
  refuse with `environment_unresolved`, and `DeletionAuthorizer` fails at precondition 1.
  Provider reads for diagnostics are allowed.
- DNS verification continues (read-only, harmless); serving is unaffected.
- A blocking admin notice appears on every screen.

| Operator choice | Effect |
|---|---|
| **Restore / Move** | keep the installation id, challenges, and `ssl_owned`; update the stored primary host; clear the flag. This remains the same installation. |
| **Clone** | generate a **new** installation id; `ssl_owned = 0`, `ssl_ref = NULL`, `ssl_state = none`, `ssl_provider_state = NULL`, `ssl_adopted_at = NULL` on every row; reset all rows to `unverified` with **rotated challenges**; never auto-adopt any remote resource |

A database copy is not evidence of domain control, so a clone re-proves from scratch and
— by §14.5 — cannot touch the original's provider resources.
**Limitation:** a clone restored onto the *same* primary host is undetectable by this
mechanism.

### 14.8 Provider cooldowns and ambiguous mutations

Option `pd_provider_cooldowns`, keyed by **driver id**:
`{ "cloudflare-saas": { "until": "…Z", "reason": "429", "source": "retry_after" } }`.
Checked before every sweep, before every provider call, and before scheduling a
continuation. A 429 halts that provider **across all rows** and suppresses continuations
until expiry. Other drivers and DNS verification are unaffected.

No non-idempotent call is retried blind. On timeout, connection reset, or 5xx from a
`POST`, `PATCH`, or `DELETE`, the driver first issues a read (`identify()` / `status()`)
to learn the provider's actual state — create-then-timeout is otherwise the classic way
to produce a duplicate or a phantom resource. `Retry-After` is parsed (delta-seconds or
HTTP-date), converted to UTC, and written to the relevant next-attempt column; the
outcome is `TRANSIENT`, with no state change, no attempt increment, and no further calls
to that provider in the same sweep.

### 14.9 Cloudflare for SaaS driver

The API exposes two independent axes:

- `ownership_verification` / `ownership_verification_http` validate **hostname
  ownership** and affect `status`.
- `ssl.validation_records` validate **certificate issuance** and affect `ssl.status`.

Production-ready is exactly `status: active` **and** `ssl.status: active` with DNS
pointing at the SaaS target. **Local `ACTIVE` requires precisely that combination.**

Operations:

- `ensure()` → `POST /zones/{zone}/custom_hostnames` with `hostname`, `ssl.method`
  (§14.10), `ssl.type: dv`, and — **only when available** — the ownership marker in
  `custom_metadata`. A `duplicate_record` error routes to `identify()` per §14.3.
- `identify()` → `GET /zones/{zone}/custom_hostnames?hostname=` plus, when a stored ref
  exists, `GET …/custom_hostnames/{id}`. It reports the observed id, the exact
  `hostname` on the resource, any parsed `custom_metadata` marker, and whether the read
  was complete (pagination completed, no partial result).
- `adopt()` → `PATCH …/custom_hostnames/{id}` writing `custom_metadata` when supported;
  when markers are unavailable, adoption records the binding locally only, and the
  event says so.
- `remove()` → verifies the `DeletionAuthorization` bindings, then
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
On seeing it the driver sets `marker_support = UNAVAILABLE`, persists that in
`ssl_marker_support`, retries the same call once **without** `custom_metadata`, and never
treats 1413 as retryable thereafter. A single admin notice explains that marker-based
defence in depth is off for this account and that identity rests on the
reference-plus-hostname binding and the plugin's fresh DNS proof.

Credentials come from `Ssl\Credentials` — constants (`PD_CLOUDFLARE_API_TOKEN`,
`PD_CLOUDFLARE_ZONE_ID`, `PD_CLOUDFLARE_CNAME_TARGET`, optional
`PD_CLOUDFLARE_APEX_TARGETS`) or the `pd_ssl_credentials` option. Never on a mapping
row, never in a REST response, never in an event or log.

### 14.10 Certificate validation method

The API accepts exactly one `ssl.method` value: `http`, `txt`, or `email`.

**The default is `txt`.**

| Question | Answer |
|---|---|
| Configuration source | `PD_CLOUDFLARE_SSL_METHOD` constant, else `pd_ssl_credentials['ssl_method']`, else the default |
| Filter | `pd_ssl_validation_method( string $method, Mapping $m )` |
| Allowed values | `http`, `txt`, `email` — validated against `DriverCapabilities::$validation_methods`; anything else falls back to the default with an admin notice |
| Default | `txt` |
| Persisted per mapping | yes — `ssl_method`, written when SSL is first requested |
| Site-setting change | affects **new** requests only; existing provider resources are never mutated as a side effect |
| Method change for one mapping | explicit: `PATCH /domains/{id}/ssl {"method": "…"}`, which issues a provider `PATCH` under the same identity-plus-fresh-proof preconditions as any other mutation, then re-reads. Never a blind mutation. |
| Reconciliation | compares stored `ssl_method` with the provider's `ssl.method` and **reports** a divergence; it never auto-patches |
| Unsupportable method | a `DnsBlocker`; `ssl_state` unchanged; never a silent substitution |

**Why `email` is not an automated default:** it requires a human to receive mail at a
WHOIS or role address and click a link. It cannot be completed by publishing a record,
cannot be observed by the plugin, and cannot be automated. It remains selectable, and
when selected the plan surfaces a `ManualRequirement` stating that the operator or
domain owner must complete it out of band.

**Wildcards:** HTTP DCV is not permitted for wildcard hostnames. The driver rejects
`http` for a wildcard host and emits a `DnsBlocker` naming `txt` as the required method.

**Non-wildcard nuance:** for non-wildcard hostnames Cloudflare attempts automatic HTTP
DCV once the hostname points at the SaaS target, *even when `txt` was selected*. The
plugin therefore treats the `ssl_validation` TXT requirement as satisfiable by either
route: reaching `ssl.status: active` without the TXT record ever appearing is a success,
not an anomaly, and no blocker is raised for the unfulfilled record.

### 14.11 Validation plan

```php
final class DnsRecordSpec     { string $type; string $name; string $value; int $ttl; }

final class DnsRequirementSet {          // ALL records in a chosen set are required
    string $purpose;      // 'ownership' | 'provider_ownership' | 'ssl_validation' | 'routing'
    string $id; string $label;
    DnsRecordSpec[] $records;
    bool $apex_compatible; string $source;   // 'core' | driver id
    bool $removable_once_active;             // provider-ownership records may be
}
final class HttpRequirementSet {         // HTTP DCV tokens are NOT DNS records
    string $purpose; string $id; string $label;
    string $url; string $body; string $source;
}
final class ManualRequirement {          // e.g. email DCV
    string $purpose; string $id; string $label;
    string $instruction; array $contacts; string $source;
}
final class ValidationPending {
    string $purpose; string $reason;      // 'provider_records_not_yet_issued'
    ?int $retry_after;
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
| `provider_ownership` | driver | Cloudflare `ownership_verification` / `_http` | may be removed by the customer once `status: active`; `removable_once_active = true` |
| `ssl_validation` | driver | from `ssl.validation_records` | appears when the CA issues tokens, disappears when `ssl.status` reaches `active` |
| `routing` | driver | where the customer points the hostname | permanent while the mapping serves |

**Translation from Cloudflare `ssl.validation_records[]`**, whose entries carry
`txt_name`, `txt_value`, `http_url`, `http_body`, and `emails[]`:

| Provider data | Becomes |
|---|---|
| `txt_name` + `txt_value` | `DnsRequirementSet{purpose:'ssl_validation', id:'cf-dcv-txt', records:[TXT], apex_compatible:true}` |
| `http_url` + `http_body` | `HttpRequirementSet` — **never** a DNS record |
| Delegated DCV CNAME | `DnsRequirementSet{id:'cf-dcv-delegated', records:[CNAME _acme-challenge…]}` |
| `emails[]` | `ManualRequirement` with the contact list; not automatable |
| Several records in one entry that must all be satisfied | one set containing all of them (ALL semantics) |
| Genuinely alternative record groups from the provider | multiple sets under the same purpose (OR semantics) |
| `validation_records` empty shortly after create | `ValidationPending{reason:'provider_records_not_yet_issued'}` — **not** a blocker |
| Malformed, incomplete, or unrecognised entry | `DnsBlocker{code:'provider_record_malformed'}`, with the raw shape stored in `ssl_provider_state` |

**The plugin never invents a DNS record, and never renders a literal such as
`unsupported` as a record type.** An unsatisfiable configuration is a `DnsBlocker`; an
unissued record is `ValidationPending`.

**Plan lifetime.** The plan is recomputed from the latest provider read every time it is
rendered; it is never cached. Requirements therefore appear when the provider issues
them, change when the provider replaces them, and disappear when the provider no longer
reports them. Changes are recorded as events so an operator can see that a token was
replaced rather than silently swapped under them.

**Routing.** No CNAME is assumed. Routing values come from the driver's configured
customer-facing **CNAME target** (`PD_CLOUDFLARE_CNAME_TARGET`) — a distinct value, not
the fallback origin and not derivable from it. For Cloudflare for SaaS:

- non-apex with a CNAME target configured ⇒ one routing set, `CNAME`,
  `apex_compatible: false`
- apex with A/AAAA targets configured (`PD_CLOUDFLARE_APEX_TARGETS`) ⇒ one routing set
  containing **all** required A/AAAA records, `apex_compatible: true`
- apex with no apex-capable target ⇒ **no routing set**, plus a `DnsBlocker` naming what
  must be configured

Apex determination uses a maintained Public Suffix List
(`jeremykendall/php-domain-parser`) comparing against the registrable domain — never a
label count, which is wrong for `example.co.uk`. `pd_is_apex` allows a driver or
integrator to override with its own policy.

The admin renders alternatives as "create any one of these", which is what an OR group
means to the person editing a zone file.

### 14.12 Authoritative DNS deployment posture

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
  ALIAS, or ANAME behaviour. Where that is absent and no apex-capable A/AAAA target is
  configured, the plan reports a routing `DnsBlocker` rather than printing a record the
  domain owner cannot create.
- **The current scope generates and verifies DNS instructions. It does not mutate
  customer DNS through any API.** There is no DNS-write credential anywhere in this
  design.
- Recommending Cloudflare authoritative DNS implies **no** access to paid Custom
  Metadata (§14.9) and **no** access to Enterprise Apex Proxying.
- Documentation recommends **client-owned Cloudflare accounts**, strong authentication,
  DNSSEC, and delegated least-privilege access — not one shared account owning every
  client zone.
- A future DNS-management adapter would be a **separate capability** and is not part of
  this implementation.

### 14.13 Durable deletion

The local row is **never** deleted before external cleanup succeeds.

1. `DELETE /domains/{id}` (with `If-Match`) sets `deletion_requested_at`, forces
   `activation_state = inactive` — **serving stops immediately** — and, when a driver
   holds a resource, sets `ssl_state = pending_removal`. Returns **`202 Accepted`**.
   Refused `409` while aliases point at it.
2. `pd_ssl_sweep` asks `DeletionAuthorizer` for an authorization (§14.5). A refusal is
   recorded with its failing precondition; transient refusals do not increment
   `deletion_attempts`.
3. With an authorization, `remove()` is called. `REMOVED` ⇒ `revoked` ⇒ hard delete, with
   a final event carrying the `host` snapshot.
4. `NullDriver`, or `ssl_state = none`, means nothing external exists ⇒ hard delete
   immediately, `200 OK`. No authorization is required because no provider mutation
   occurs.
5. After 12 attempts / 24 h the row remains, an admin notice names the probable orphan,
   and an operator may `DELETE …?force=true` — which removes the local row, records that
   a provider resource may remain, and issues **no** provider deletion.

A row awaiting removal never serves and never re-verifies.

### 14.14 Reconciliation

The daily `Reconciler` calls `reconcile()` with `SslResourceContext` objects, in chunks,
and adopts provider truth for **state**, with three hard rules: a local/provider mismatch
never triggers a delete at the provider; a transient response changes nothing; and
reconciliation **never adopts ownership** of anything. Divergences — including a stored
`ssl_method` that differs from the provider's — are written as events and surfaced in
Diagnostics, so an operator sees "we think active, Cloudflare says pending" rather than
discovering it from a browser warning.

### 14.15 Status map provenance and generation

Both provider status axes are enumerated by the current Cloudflare API schema. The map
is **generated from a pinned schema input**, not transcribed, and generation is
reproducible offline.

The implementation must:

- commit a **pinned schema snapshot** at `references/cloudflare-api-schema.<date>.json`
- commit `references/cloudflare-schema-provenance.json` recording the **source URL**,
  the **retrieval date** (UTC), and the **SHA-256 digest** of the snapshot
- maintain `references/cloudflare-status-policy.php` — the human-authored
  classification policy mapping each enum value to an internal state
- generate `references/cloudflare-status-map.php` from **schema × policy** via
  `bin/generate-cloudflare-status-map.php`
- generate or validate the enum fixtures from the **same** pinned input
- treat the **pinned schema input as the source of truth**; the map and the fixtures are
  derived artifacts
- **fail generation and CI** when: any schema value lacks an explicit classification in
  the policy; a duplicate value appears; an expected value is missing; the structure is
  unexpected; or the cardinality differs from the recorded expectation of **16
  hostname-status values** and **21 SSL-status values**
- keep normal builds free of live network access

An **optional CI drift check** may compare the pinned snapshot against the live schema
and report that an intentional update is required. It never auto-updates, and it never
fails the normal build.

Relationships, stated once:

```
pinned upstream schema   →  which enum values exist          (source of truth)
classification policy    →  what each value means            (human-authored)
generated PHP map        →  schema × policy                  (derived)
generated fixtures       →  one test case per schema value   (derived)
runtime unknown-value rule →  non-destructive and alerting   (independent safety net)
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
they can never produce `FAILED` or `REVOKED`, so a schema addition cannot cause the
plugin to tear down a working certificate.

**`caa_error` is not a status-axis enum value** and is not obtained from the status-map
generator. CAA problems surface in the **error arrays** — hostname `verification_errors[]`
and `ssl.validation_errors[]` — as messages such as
`SERVFAIL looking up CAA for app.example.com`. A separate error classifier reads those
arrays and may **annotate** a state with `code: caa_error` and a remediation hint. It
never sources a state from them and never feeds them into the generator. Raw axes and
raw error arrays are both persisted in `ssl_provider_state` for diagnostics.

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
    "owned": true, "adopted_at": null,
    "method": "txt", "marker_support": "unavailable",
    "checked_at": "…Z", "next_attempt_at": null, "error": null,
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
credential. SSL credentials, markers' raw account data, and deletion authorizations
appear in no response, ever.

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
| `POST` | `/domains` | `{host, post_id?, alias_of?, title?, favicon_attachment_id?}` |
| `GET` | `/domains/{id}` | |
| `PATCH` | `/domains/{id}` | `post_id`, `activation_state`, `title`, `favicon_attachment_id` only |
| `DELETE` | `/domains/{id}` | 202 + durable removal; `?force=true` removes locally only |
| `POST` | `/domains/{id}/verify` | on-demand check; rate-limited 1/min per mapping |
| `POST` | `/domains/{id}/challenge` | rotates; resets verification; response says so |
| `GET` | `/domains/{id}/plan` | returns a `ValidationPlan` |
| `POST` | `/domains/{id}/ssl` | `ensure()`, idempotent |
| `PATCH` | `/domains/{id}/ssl` | `{method}` — explicit DCV method change (§14.10) |
| `DELETE` | `/domains/{id}/ssl` | authorized removal (§14.5) |
| `POST` | `/domains/{id}/ssl/adopt` | `{confirm:true, override_foreign_marker?:bool}` |
| `GET` | `/environment` | installation id, stored vs current primary host, mismatch flag |
| `POST` | `/environment/resolve` | `{choice:"restore"\|"clone"}` |

`PATCH /domains/{id}` excluding verification and SSL state is the API expression of
invariant 1: there is no request that makes a mapping verified. `verification`, `ssl`
state, `revision`, and `deletion` are read-only there. `post_id` on an alias row returns
`pd_alias_no_target`.

### 15.3 Errors

`pd_host_invalid` (400), `pd_host_malformed_authority` (400),
`pd_host_exists` (409), `pd_host_too_long` (400, naming the composed TXT length),
`pd_label_invalid` (400), `pd_alias_chain` (400), `pd_alias_no_target` (400),
`pd_post_invalid` (400), `pd_alias_in_use` (409), `pd_conflict` (409),
`pd_precondition_required` (428), `pd_precondition_failed` (412),
`pd_rate_limited` (429), `pd_environment_unresolved` (409),
`pd_unowned_resource` (409), `pd_deletion_unauthorized` (409, naming the failing
precondition), `pd_method_unsupported` (400), `pd_forbidden` (403).

Any of these requested on a non-primary host produces a plain 404 from WordPress,
because the route does not exist there.

---

## 16. Admin

### 16.1 Screens

**Domains** — host (Unicode display, ASCII on hover), target, and three state chips.
The computed `serving` chip appears only on expanded rows, matching the collection
split. Row actions: verify now, rotate challenge, provision SSL, deactivate, delete.
Bulk: activate, deactivate, verify.

**Add domain** — one screen, three steps, no server-side wizard state: host → target
post (post-type-agnostic search restricted to the configured types) → the validation
plan. The row is created `pending`/`inactive` at step one so the challenge exists before
instructions are displayed.

**Domain detail** — the validation plan, rendered so an operator can tell the four
purposes apart at a glance:

| Section | What it says |
|---|---|
| Ownership (post-domain) | permanent; **must never be removed** |
| Hostname ownership (provider) | may be removed once the provider reports the hostname active |
| Certificate validation (provider) | appears when the CA issues tokens; may be TXT, a delegated CNAME, an HTTP token, or a manual email step |
| Routing | where the customer points the hostname |
| Awaiting provider | records not yet issued — a wait, not a failure |
| Blockers | what must be fixed before this can work |

Alternatives inside a purpose are rendered as "create any one of these"; every record
inside a chosen set is marked required. HTTP tokens are shown as a URL and body to serve,
never as a DNS record. Also on this screen: a live "last checked / next attempt / last
outcome" block; the event log; raw `ssl_provider_state`; SSL actions.

**Delete** — shows the authorization checklist (§14.5) with the outcome of each
precondition, so a refused deletion says *which* check failed rather than "try again".
Force-delete is presented separately and states plainly that a provider resource may be
left behind.

**Settings** — target post types, SSL driver, DCV method, DoH endpoints,
admin-redirect toggle, asset-proxy toggle, and generated web-server/CDN snippets for the
421 rule and CORS.

**Diagnostics** — sweep backlog depth and oldest due timestamp; WP-Cron health;
round-trip failures; path collisions; absolute primary-host URLs found in a rendered
mapped page; the browser-side CORS probe; unowned, missing, or divergent SSL resources;
marker support; the environment-mismatch banner.

### 16.2 Operator flows

*Add a domain*: create → publish the ownership TXT → wait → verified → activate →
optionally provision SSL, which adds provider-ownership, certificate-validation, and
routing requirements to the same plan. Nothing serves until verified **and** active.

*Domain stops working*: the event log distinguishes "customer removed the TXT record"
(three hard failures, `failed`) from "our resolver was unreachable" (transient, still
verified) without anyone reading a log file.

*Move / restore / clone*: the blocking banner forces the choice before any provider
mutation runs.

*Remove a domain*: `202`, serving stops immediately, the authorization guard runs, and
cleanup retries durably; hard delete only after confirmation.

---

## 17. Testing

**Unit (no WordPress):** `AuthorityParser` over a malformed-input table — `host:`,
`host:0`, `host:99999`, `host:abc`, `[::1`, `::1:80`, `a b`, `a\tb`, `user@host`,
`host/path`, embedded NUL and control characters — each asserted to reach
`MALFORMED_400`, plus the assertion that **no malformed authority can reach the
allowlist comparison**; `IdnaNormalizer` against the fixed UTS-46 vectors, including
Unicode ⇄ punycode deduplication; `PathDecomposer`; `PathNormalizer`; `Subtree`
round-trip as a **property** over a generated fixture tree; collision ambiguity; grace
arithmetic and counter resets; `CanonicalPolicy`; `UrlPolicy`; `Classifier`; the
Cloudflare status map, one case per schema value, generated from the pinned input;
generator failure when a schema value lacks a classification; DoH response-shape
rejection; `HostValue` / `AbsoluteUrl` validators including scheme-downgrade rejection;
validation-record translation for TXT, delegated CNAME, HTTP token, email, empty,
malformed, multiple-required, and genuine-alternative cases, asserting that all four
purposes stay distinct and that no DNS record is ever invented.

**Integration (wp-env):** the §7.2 compatibility matrix asserted on **rendered output**,
not on filter registration; query-string preservation through the unmatched redirect;
the full disposition matrix (400 / 421 / 404 / 503 / serve) across host states, with an
allowlisted host and a malformed near-match of it asserted to diverge; admin redirect
method-awareness (302 vs 307) and the admin-ajax exemption; REST management absent from
discovery on a mapped host; CORS header presence and exact value; feed and sitemap
membership enforcement with an injected non-member; empty `post__in` short-circuit; CAS
conflict returning 409; lease conflict discarding a DNS result; uninstall leaving posts
untouched.

**SSL authorization (integration, against a fake driver and a recorded-fixture
Cloudflare client):** each of the six deletion preconditions failing individually and
producing a refusal with no provider mutation; a token rejected after a concurrent
revision bump; a token rejected when host, provider, ref, or challenge differ; expiry;
single use; a clone unable to delete the original installation's resource; a restore
retaining authority; adoption refused without `confirm`, without a fresh proof, and
without `override_foreign_marker` against a foreign marker; `ensure()` on a duplicate
returning `unowned_resource` without adopting; error 1413 setting
`marker_support = UNAVAILABLE`, retrying once without `custom_metadata`, and never being
retried as transient; the driver operating end to end with markers unavailable;
reconciliation with `snapshot_complete = false` never inferring a missing resource.

---

## 18. Build, release, uninstall

Composer with `symfony/polyfill-intl-idn:1.38.1` and `jeremykendall/php-domain-parser`
pinned exactly, `composer.lock` committed, `composer audit` in CI, and PHP-Scoper
prefixing all vendor code into `PostDomain\Vendor\` so the shipped plugin cannot collide
with or be hijacked by another plugin's autoloader. The release artifact is the prefixed
build, never the raw vendor tree. PHPCS against WordPress-Extra; PHPStan level 8.

The Cloudflare status map is generated at build time from the pinned snapshot (§14.15);
CI fails if the committed map differs from a fresh generation, or if any schema value
lacks a classification.

Minimum: **WordPress 6.4, PHP 8.1.** No PHP extension is a hard requirement.

`uninstall.php` drops `pd_domains` and `pd_domain_events` and deletes
`pd_schema_version`, `pd_schema_engine`, `pd_settings`, `pd_ssl_credentials`,
`pd_installation_id`, `pd_installation_primary_host`, `pd_environment_mismatch`,
`pd_provider_cooldowns`, and all cron events. **No post, meta, or option belonging to
anything else is touched.**

---

## 19. Documentation deliverables

A README covering:

- installation, and the WordPress/PHP minimums
- the DNS records a domain owner must create, which of the four purposes each belongs
  to, and why the post-domain ownership TXT record must **stay** while a provider
  ownership record may be removed once active
- the complete filter reference with defaults, postconditions, and examples
- the `init : 99` registration requirement and the early-URL limitation
- the `SslDriver` interface, `SslResourceContext`, and how to add a driver, including how
  a driver expresses its own ownership-proof mechanism
- the deletion-authorization model: what a provider deletion requires, why cached
  verification is not enough, and what a refusal means
- adoption: when it is needed, what it requires, and what it records
- clone detection and the restore/move/clone choice
- the `pd_dns_resolver` trust boundary
- the DCV method choice, the `txt` default, why `email` is not automated, and the
  wildcard restriction
- the **authoritative-DNS deployment posture** (§14.12): provider neutrality, Cloudflare
  DNS as a recommendation rather than a requirement, the separation of DoH resolvers /
  Cloudflare for SaaS / authoritative DNS, apex requirements on other providers, that no
  DNS is mutated by API, that no paid Custom Metadata or Enterprise Apex Proxying is
  assumed, and the client-owned-account recommendation
- the multisite exclusion and its reasoning
- the 421 default, the exact infrastructure allowlist, and the web-server rule the plugin
  cannot apply
- the CORS hosting boundary
- the auth consequences of mapped-host REST and admin-ajax

---

## 20. Open items

One item is deliberately deferred to implementation:

**`CteSubtreeAdapter` capability matrix.** The concrete MySQL and MariaDB
minimum-version matrix (nominally MySQL 8.0, MariaDB 10.2.2) must be confirmed against
the actual target environments before the adapter is enabled there. Deferring this is
safe by construction: the adapter is explicitly capability-gated, has its own
integration tests, returns post IDs rather than injecting raw JOIN or WHERE fragments,
falls back to enumeration, and an unbounded scope is never executed.
