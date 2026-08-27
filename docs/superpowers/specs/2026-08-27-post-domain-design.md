# post-domain — design specification

**Date:** 2026-08-27
**Status:** approved design, not yet implemented
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
taxonomy, field system, theme, or content model. Every question with a site-specific
answer is asked through a filter and answered by the integrator.

**The test applied to every decision: two unrelated sites with different content
models must both run this plugin unmodified.**

The plugin does not:

- assume a post type beyond what the admin selects in its own settings
- require ACF or any other field plugin
- hardcode a path segment, slug pattern, or URL shape
- assume a theme, template hierarchy, or markup structure
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
      Lease.php  Schedule.php
    Ssl/
      SslDriverRegistry.php  SslStatus.php  RemovalResult.php  RemovalOutcome.php
      Ownership.php  OwnershipResult.php  ReconcileReport.php
      DnsPlan.php  DnsRequirementSet.php  DnsRecordSpec.php  DnsBlocker.php
      NullDriver.php  CloudflareSaasDriver.php  Reconciler.php
      Credentials.php  Cooldown.php  Environment.php
    Rest/
      ManagementController.php  Guard.php
    Admin/
      SettingsPage.php  MappingListTable.php  Diagnostics.php  EnvironmentNotice.php
    Branding.php
  references/
    cloudflare-status-map.php  generated from the current API schema
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

**Interfaces** — every dependency that touches the outside world (database, DNS,
clock, scheduler, HTTP, certificate provider) is behind an interface and injected at
the composition root. Everything else crosses boundaries as readonly value objects.
WordPress globals (`$wp`, `$wp_query`) are touched in exactly two places:
`Routing\Resolver` and the URL adapters.

---

## 3. Host model

### 3.1 Normalization

`IdnaNormalizer` is the only caller of the vendored UTS-46 implementation.
`HostNormalizer::normalize()` is total and never throws:

```
strip whitespace → reject if it contains / \ @ space or NUL
→ split host:port; reject malformed port (empty, non-numeric, > 65535); discard port
→ reject IP literals (dotted-quad, bracketed or bare IPv6)
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

### 3.2 IDN implementation

**A single bundled implementation is used on every host, exclusively.**
`symfony/polyfill-intl-idn`, pinned to `1.38.1`, is called through
`Symfony\Polyfill\Intl\Idn\Idn::*` directly — never through the global `idn_to_*`
functions, whose availability depends on `ext-intl` and would reintroduce a second
implementation. Two UTS-46 implementations disagreeing on one input is a
verification-bypass shape, so only one is ever in play.

Requirements: `composer.lock` committed; the prefixed build reproducible from it;
`composer audit` in CI; UTS-46 conformance vectors fixed at
`tests/unit/fixtures/uts46.txt`; version bumps are deliberate PRs that re-run the
vectors.

### 3.3 Trusted proxies

The served host is `$_SERVER['HTTP_HOST']`. `X-Forwarded-Host` and `Forwarded` are
honoured **only** when `PD_TRUSTED_PROXIES` (CIDRs) is defined *and* `REMOTE_ADDR`
falls inside it. No filter enables forwarded headers without an IP allowlist.

### 3.4 Host kinds and the infrastructure allowlist

```php
enum HostKind { case PRIMARY; case MAPPED; case ALLOWED_INFRASTRUCTURE;
                case UNKNOWN; case MALFORMED; }
```

The allowlist is checked against the **raw** host (lowercased, port stripped) *before*
the IDN pipeline, because the hosts that belong on it — health-check names, the origin
hostname, bare IPs, `localhost` — are exactly the ones the normalizer rejects. Sources:
`PD_ALLOWED_HOSTS`, then `pd_allowed_infrastructure_hosts`. Exact match only.

### 3.5 Aliases

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
form action stays on the mapped host); `XMLRPC` returns 404 (`pd_xmlrpc_on_mapped_hosts`,
default false); `CRON_HTTP` and `INFRASTRUCTURE` are served with primary context;
`ADMIN`/`LOGIN` redirect; `AJAX` is served and exempt from that redirect.

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
    public readonly string        $raw_host;
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
| Malformed host | `400` |
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

plugins_loaded : 0     HostContextFactory  →  HostPolicy frozen
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
   containment against an allowlisted root (`str_starts_with( $real, $root . DIRECTORY_SEPARATOR )`),
   deny when `realpath()` fails, no symlink escape, no user-supplied root.
4. **No server-side diagnostic fetches exist anywhere in the plugin.** The CORS probe
   is a hidden iframe pointing at `https://<mapped-host>/.well-known/post-domain-probe`,
   a plugin-served `WELL_KNOWN` route. It executes on the **mapped origin**, fetches the
   sample asset, and `postMessage`s the result back; both ends check `event.origin`.

### 8.1 Strict `Origin` parsing

Matched against an anchored grammar —
`^(https?)://([a-z0-9._~%-]+|\[[0-9a-fA-F:.]+\])(:\d{1,5})?$` — with no path, query,
fragment, userinfo, or trailing slash permitted, and the literal `null` rejected. Only
then is the host normalized and looked up, and scheme and port must match what the
mapping is served on.

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
6. **`UnknownHostGuard`'s position is not filterable.** Only its allowlist is data.

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
`pd_request_resolved`, `pd_request_unmatched`, `pd_environment_mismatch_detected`.

### 11.8 Hard postconditions

Every security-sensitive filter's return value is re-validated and clamped. Violations
are ignored and logged, never honoured.

| Filter | Postcondition |
|---|---|
| `pd_endpoint_class` | `PROTECTED = {ADMIN, LOGIN, AJAX, REST_MANAGEMENT, REST_CONTENT, COMMENT_POST, TRACKBACK, XMLRPC, CRON_HTTP, INFRASTRUCTURE, ASSET}`. If the pre-filter class is protected the result **must equal** it; if the post-filter class is protected the result is **rejected**. Reclassification only among `{ROUTED, WELL_KNOWN, SITEMAP}`. |
| `pd_preserved_query_vars` | Intersected with `/^[a-z0-9_]{1,32}$/`, then `RESERVED` subtracted unconditionally: `p, page_id, name, pagename, post_type, attachment, attachment_id, static, error, preview, preview_id, preview_nonce, post_status, rest_route` plus every var the resolver sets. |
| `pd_is_rebasable_path`, `pd_rebase_url` | `PROTECTED_PATHS = {wp-admin/, wp-login.php, wp-signup.php, wp-activate.php, xmlrpc.php, wp-cron.php, <rest-prefix>/post-domain/v1/}` forced not rebasable, with `EXEMPT = {wp-admin/admin-ajax.php}` as an **exact** match checked first. A rebased URL must be absolute, `http`/`https`, and its host permitted by `pd_link_host`; otherwise the original is returned. |
| `pd_link_host`, `pd_canonical_host` | `HostValue` validation: bare host, no scheme, no path, port only if it matches the request's; normalizes; must be `requested_host` or `canonical_host`. |
| `pd_admin_redirect_target`, `pd_canonical_url` | `AbsoluteUrl` validation: absolute, scheme in `{http,https}`, no userinfo, no control characters, host normalizes, host in the permitted set (`pd_admin_redirect_target`: primary or allowed-infrastructure; `pd_canonical_url`: primary, requested, or canonical). **No scheme downgrade** — an HTTPS request may not yield an HTTP result. |
| `pd_cors_allowed_origin` | `null`, or byte-identical to the validated request `Origin` whose host is a `VERIFIED` + active mapping with matching scheme and port. `*`, a different origin, or a list ⇒ no header. |
| `pd_asset_proxy_extensions` | Intersected with the hardcoded maximum `{woff2, woff, ttf, otf, eot}` — narrowing only. `svg` is absent by construction. |
| `pd_trusted_proxies` | Each entry must parse as a valid IP or CIDR; invalid dropped; empty ⇒ forwarded headers ignored. |
| `pd_allowed_infrastructure_hosts` | Lowercased, port-stripped, exact hostname or IP literal; anything containing `*` dropped. |
| `pd_max_subtree_depth` | Clamped `1..25`. |
| `pd_scope_enumeration_limit` | Clamped `0..5000`. |
| `pd_query_scope` | Must return a `QueryScope`. A non-`QueryScope`, or `is_bounded = true` with no constraint, is replaced with `is_bounded = false`. Unbounded is never reachable. |
| `pd_txt_record_label` | Matches `/^_?[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i`, 1–63 bytes, no dot, lowercased; else the default. Validated at create/rotate only. |
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
ssl_checked_at           DATETIME          NULL
ssl_next_attempt_at      DATETIME          NULL
ssl_transient_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0
ssl_provider_state       TEXT              NULL          -- JSON, raw provider axes
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
detail      LONGTEXT        NULL         -- JSON: outcome, resolver_class, attempt id, provider ref
created_at  DATETIME        NOT NULL
PRIMARY KEY (id)
KEY domain_created (domain_id, created_at)
```

Every state transition writes one row. This is the support artifact — "it stopped
working on Tuesday" becomes answerable, and `resolver_class` makes it visible which
code performed each ownership proof. Retention 90 days, pruned daily.

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
event, or a log line.

### 12.4 Compare-and-swap

```sql
UPDATE … SET …, revision = revision + 1, updated_at = ?
WHERE id = ? AND revision = ?
```

Zero affected rows ⇒ `pd_conflict` (409) for REST; bounded re-read-and-retry (3
attempts) for cron and CLI. REST exposes `revision` and `ETag: "<id>-<revision>"`.
`If-Match` is **required** on `PATCH`, `DELETE`, `POST /challenge`, and
`POST|DELETE /ssl`: missing ⇒ `428`, stale ⇒ `412`. `POST /verify` is exempt (idempotent
probe).

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

**Arithmetic:** increment first, then compare — `hard_failure_count += 1; if (hard_failure_count >= pd_verification_grace) → failed`. With the default 3: failures 1 and 2 keep the mapping verified; failure 3 fails it.

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
| `ensure()`, provider reports already issued | `none`/`requested` | `active` (immediate) |
| Validation outstanding | `requested` | `pending_validation` |
| Provider reports issued | `pending_validation` | `active` |
| Still validating | `pending_validation` | unchanged, `ssl_checked_at` advanced |
| **Resource missing** | `requested`/`pending_validation`/`active` | `failed` / `provider_resource_missing` |
| Hard error | any | `failed` |
| **Transient** | any | **unchanged**; `ssl_transient_count++`; `ssl_checked_at` **not** advanced |
| Removal requested | `requested`/`pending_validation`/`active`/`failed` | `pending_removal` |
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
record must remain in DNS permanently** — re-checks read it. Aliases verify at their
own name.

`pd_txt_record_label` runs **only** at create/rotate; its validated result is persisted
in `challenge_label`. Ordinary verification composes the name from persisted data and
never re-runs the filter. If persisted values fail validation at read time the row is
**corrupt**: `integrity_error` is set, the disposition becomes `BROKEN_503`, and
verification halts. It does not keep serving on a soft warning.

Full TXT-name validation: composed name ≤ 253 bytes, each label 1–63, ≤ 127 labels,
label charset enforced.

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

**Transport hardening:** HTTPS enforced on the endpoint; `redirection => 0`; response
capped at 64 KB; JSON `Content-Type` required; shape validated (`Status` an integer,
`Answer` an array of objects, `type === 16` for TXT) before any field is read. Non-200,
malformed JSON, wrong shape, oversize, or redirect ⇒ **`TRANSIENT`**. Endpoints come
only from the default list or the filter; nothing derives an endpoint from request data.

`NativeDnsResolver` remains for environments that cannot make outbound HTTPS calls,
with a hard restriction: **it may emit only `MATCH`, `MISMATCH`, or `TRANSIENT`.** Every
empty or failed lookup is `TRANSIENT`, so it can never deactivate a verified mapping —
correct behaviour for a resolver that cannot distinguish "record gone" from "resolver
unwell." Selecting it raises a persistent admin notice.

Multi-string TXT values are concatenated per RFC before matching; comparison is
`hash_equals`; **all** returned TXT values are examined, since a domain legitimately
carries many.

### 13.3 `pd_dns_resolver` is trusted code

A custom resolver **substitutes the domain-ownership proof mechanism**; it does not
integrate with it. A resolver returning `MATCH` unconditionally disables verification
entirely. Installing a non-default resolver raises a persistent admin notice naming the
class, and the class is recorded on every verification event.

### 13.4 Queue, budget, and leases

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

### 13.5 Cron topology

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

### 14.1 Contract

```php
enum RemovalOutcome { case REMOVED; case PENDING; case TRANSIENT; case FAILED; }
enum Ownership      { case OWNED; case FOREIGN; case UNMARKED; case ABSENT; case UNKNOWN; }

final class SslStatus {
    public readonly SslState $state;
    public readonly ?string  $ref;
    public readonly ?string  $code;       // sanitized
    public readonly ?string  $message;    // sanitized, ≤ 500 bytes
    public readonly bool     $transient;  // true => caller must not change state
}
final class RemovalResult {
    public readonly RemovalOutcome $outcome;
    public readonly ?string $code; public readonly ?string $message;
    public readonly ?int    $retry_after;
}
final class ReconcileReport {
    /** @var iterable<string,SslStatus> */ public readonly iterable $statuses;
    public readonly bool    $snapshot_complete;
    public readonly ?string $incomplete_reason;
}

interface SslDriver {
    public function id(): string;
    public function ensure( string $host ): SslStatus;      // idempotent create-or-get
    public function status( string $host ): SslStatus;
    public function ownership( string $host ): OwnershipResult;
    public function remove( string $host ): RemovalResult;  // idempotent
    public function reconcile( array $hosts ): ReconcileReport;
    public function dns_plan( string $host, bool $is_apex ): DnsPlan;
}
```

`ensure()` returns immediately with whatever the provider says now, never blocks on
issuance, and is safe to call repeatedly — a double-clicked button or a retried cron
cannot create two resources. `$transient === true` means "no information": the caller
advances neither state nor `ssl_checked_at`.

`remove()` returning an enum rather than `void` separates "gone" from "we asked".
`REMOVED` ⇒ `revoked`, row proceeds to hard delete. `PENDING` ⇒ stays `pending_removal`.
`TRANSIENT` ⇒ no state change, `deletion_attempts` **not** incremented, next attempt
honours `retry_after`. `FAILED` ⇒ stays `pending_removal`, attempts incremented toward
the force-delete ceiling.

**`snapshot_complete` is load-bearing.** When false, absence from the snapshot means
nothing and the reconciler must not infer `provider_resource_missing`; only observed
rows are updated and the reason is logged.

### 14.2 Registry and ownership

```php
final class SslDriverRegistry {
    public function register( SslDriver $d ): void;   // via pd_ssl_drivers
    public function get( string $id ): ?SslDriver;
    public function default(): SslDriver;             // site setting; NullDriver fallback
}
```

- A mapping **always** uses the driver id stored in `ssl_provider`, set once when SSL is
  first requested. Changing the site default never migrates existing mappings.
- An unregistered stored id ⇒ no operations attempted, serving unaffected, reported as
  `ssl_driver_missing`.
- **Ownership marking:** every resource the plugin creates carries
  `{ pd_install: <pd_installation_id>, pd_mapping: <id> }` in the provider's metadata,
  and `ssl_owned = 1` locally. Each driver expresses its own proof mechanism —
  Cloudflare `custom_metadata`, another provider's tags, labels, or description field.
- **No silent adoption.** `ensure()` finding a pre-existing resource with no marker, or
  a foreign marker, returns `FAILED / unowned_resource` and changes nothing.
- **Ownership is rechecked immediately before deletion**, via `ownership()`, never from
  `ssl_owned` — local state that a restore or a manual provider edit can falsify.
  `OWNED` ⇒ delete. `ABSENT` ⇒ `REMOVED`. `FOREIGN`/`UNMARKED` ⇒
  `FAILED / unowned_resource`. `UNKNOWN` ⇒ `TRANSIENT`.
- Adoption is explicit: `POST /domains/{id}/ssl/adopt {"confirm": true}` writes the
  marker, sets `ssl_owned = 1`, and records an event naming any prior marker. It is the
  only path by which the plugin takes responsibility for a resource it did not create.

### 14.3 Environment / clone detection

Options `pd_installation_id` (UUID, generated at install) and
`pd_installation_primary_host` (the `home` host at install). Checked on `admin_init` and
at the top of every sweep — never on the front-end path. A mismatch sets
`pd_environment_mismatch` and, while set:

- **every provider mutation is blocked** — `ensure`, `remove`, `adopt`, `reconcile`
  refuse with `environment_unresolved`. Provider reads for diagnostics are allowed.
- DNS verification continues (read-only, harmless); serving is unaffected.
- A blocking admin notice appears on every screen.

| Operator choice | Effect |
|---|---|
| **Restore / Move** | keep the installation id and `ssl_owned`; update the stored primary host; clear the flag |
| **Clone** | generate a **new** installation id; `ssl_owned = 0`, `ssl_ref = NULL`, `ssl_state = none`, `ssl_provider_state = NULL` on every row; reset all rows to `unverified` with **rotated challenges**; never auto-adopt any remote resource |

A database copy is not evidence of domain control, so a clone re-proves from scratch.
**Limitation:** a clone restored onto the *same* primary host is undetectable by this
mechanism.

### 14.4 Provider cooldowns

Option `pd_provider_cooldowns`, keyed by **driver id**:
`{ "cloudflare-saas": { "until": "…Z", "reason": "429", "source": "retry_after" } }`.
Checked before every sweep, before every provider call, and before scheduling a
continuation. A 429 halts that provider **across all rows** and suppresses continuations
until expiry. Other drivers and DNS verification are unaffected.

### 14.5 Ambiguous mutations

No non-idempotent call is retried blind. On timeout, connection reset, or 5xx from a
`POST` or `DELETE`, the driver first issues a read (`status()` / `GET ?hostname=`) to
learn the provider's actual state — create-then-timeout is otherwise the classic way to
produce a duplicate or a phantom resource.

`429` is honoured explicitly: `Retry-After` parsed (delta-seconds or HTTP-date),
converted to UTC, written to the relevant next-attempt column. Outcome `TRANSIENT`, no
state change, no attempt increment, no further calls to that provider in the same sweep.

### 14.6 Cloudflare for SaaS driver

The API exposes two independent axes, confirmed by current Cloudflare documentation:

- `ownership_verification` / `ownership_verification_http` validate **hostname
  ownership** and affect `status`.
- `ssl.validation_records` validate **certificate issuance** and affect `ssl.status`.

Production-ready is exactly `status: active` **and** `ssl.status: active` with DNS
pointing at the SaaS target. **Local `ACTIVE` requires precisely that combination.**

Both axes are read, stored raw in `ssl_provider_state`
(`{hostname_status, ssl_status, verification_errors, read_at}`), mapped separately, then
combined. The complete mapping lives in `references/cloudflare-status-map.php`,
**generated from the current API schema** rather than transcribed from prose, and
fixture-tested one case per documented value.

Combination rules:

| Axis A (`status`) | Axis B (`ssl.status`) | Result |
|---|---|---|
| `ACTIVE` | `ACTIVE` | `ACTIVE` |
| `ACTIVE` | pending | `PENDING_VALIDATION` |
| pending | anything not failed/revoked | `PENDING_VALIDATION` |
| either | failed | `FAILED` |
| either | revoked | `REVOKED` from `pending_removal`; otherwise `FAILED / provider_resource_missing` |
| **unknown on either axis** | — | `PENDING_VALIDATION` + `unknown_provider_state` event + admin alert |

**Unknown future provider values are non-destructive and alerting by construction** —
they can never produce `FAILED` or `REVOKED`, so a Cloudflare API addition cannot cause
the plugin to tear down a working certificate. `caa_error` and `moved` map to `FAILED`
with their own codes: both are actionable, neither is a certificate teardown.

Operations:

- `ensure()` → `POST /zones/{zone}/custom_hostnames` with `ssl.method`, `ssl.type: dv`,
  and the ownership marker in `custom_metadata`. A `duplicate_record` error is **not** a
  failure — it is re-read via `GET ?hostname=` and returned as current state, which is
  what makes the call idempotent.
- `status()` → `GET /zones/{zone}/custom_hostnames?hostname=`.
- `remove()` → `ownership()` first, then
  `DELETE /zones/{zone}/custom_hostnames/{id}`; a 404 counts as `REMOVED`, which is what
  idempotent removal requires for the deletion workflow to terminate.
- All calls go through the injected `HttpClient`, 10 s timeout, one retry on connection
  failure only — never on a 4xx.

Credentials come from `Ssl\Credentials` — constants (`PD_CLOUDFLARE_API_TOKEN`,
`PD_CLOUDFLARE_ZONE_ID`, `PD_CLOUDFLARE_CNAME_TARGET`) or the `pd_ssl_credentials`
option. Never on a mapping row, never in a REST response, never in an event or log.

### 14.7 DNS requirements

```php
final class DnsRecordSpec     { string $type; string $name; string $value; int $ttl; }
final class DnsRequirementSet {
    string $purpose;      // 'ownership' | 'routing' | 'ssl_validation'
    string $id; string $label;
    DnsRecordSpec[] $records;    // ALL records in a chosen set are required
    bool $apex_compatible; string $source;
}
final class DnsBlocker { string $code; string $message; string $remedy; string $source; }
final class DnsPlan {
    /** @var array<string, DnsRequirementSet[]> purpose => alternatives (OR within purpose) */
    array $alternatives;
    /** @var DnsBlocker[] */ array $blockers;
}
```

Core contributes exactly one set: `purpose: ownership`, one TXT record,
`source: 'core'`. Everything else comes from the driver and **may be absent entirely** —
the `NullDriver` supplies none, because where certificates are handled outside the
plugin the plugin has no idea what the domain should point at.

`'unsupported'` is **never** a record type. An unsatisfiable configuration produces a
`DnsBlocker`, which the admin renders as a blocker rather than as a record to create.

**No CNAME is assumed.** Routing values come from the driver's configured
customer-facing **CNAME target** (`PD_CLOUDFLARE_CNAME_TARGET`) — a distinct value, not
the fallback origin and not derivable from it. For Cloudflare for SaaS:

- non-apex with a CNAME target configured ⇒ one routing set, `CNAME`,
  `apex_compatible: false`
- apex with A/AAAA targets configured ⇒ one routing set containing **all** required
  A/AAAA records, `apex_compatible: true`
- apex with no apex-capable target ⇒ **no routing set**, plus a `DnsBlocker` naming what
  must be configured

Apex determination uses a maintained Public Suffix List
(`jeremykendall/php-domain-parser`) comparing against the registrable domain — never a
label count, which is wrong for `example.co.uk`. `pd_is_apex` allows a driver or
integrator to override with its own policy.

The admin renders alternatives as "create any one of these", which is what an OR group
means to the person editing a zone file.

### 14.8 Durable deletion

The local row is **never** deleted before external cleanup succeeds.

1. `DELETE /domains/{id}` (with `If-Match`) sets `deletion_requested_at`, forces
   `activation_state = inactive` — **serving stops immediately** — and, when a driver
   holds a resource, sets `ssl_state = pending_removal`. Returns **`202 Accepted`**.
   Refused `409` while aliases point at it.
2. `pd_ssl_sweep` calls `remove()` idempotently with exponential backoff, incrementing
   `deletion_attempts` on `FAILED` only.
3. `REMOVED` ⇒ `revoked` ⇒ hard delete, with a final event carrying the `host` snapshot.
4. `NullDriver` or `ssl_state = none` ⇒ nothing external exists ⇒ hard delete
   immediately, `200 OK`.
5. After 12 attempts / 24 h the row remains, an admin notice names the probable orphan,
   and an operator may `DELETE …?force=true`, which writes an event explicitly recording
   that a provider resource may have been left behind.

A row awaiting removal never serves and never re-verifies.

### 14.9 Reconciliation

The daily `Reconciler` calls `reconcile()` in chunks and adopts provider truth for
state, with two hard rules: a local/provider mismatch **never** triggers a delete at the
provider, and a transient response changes nothing. Divergences are written as events
and surfaced in Diagnostics, so an operator sees "we think active, Cloudflare says
pending" rather than discovering it from a browser warning.

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
    "state": "active", "provider": "cloudflare-saas", "owned": true,
    "checked_at": "…Z", "next_attempt_at": null, "error": null,
    "provider_state": { "hostname_status": "active", "ssl_status": "active" }
  },
  "serving": { "state": "serving", "reason": null, "blocked_by": null },
  "deletion": { "requested_at": null, "attempts": 0 },
  "dns_plan": {
    "alternatives": {
      "ownership": [ { "id": "core-ownership", "label": "Ownership TXT",
        "records": [ { "type": "TXT",
          "name": "_post-domain-challenge.xn--mnchen-3ya.example",
          "value": "post-domain-verify=9f2c…", "ttl": 300 } ],
        "apex_compatible": true, "source": "core" } ],
      "routing": [ … ]
    },
    "blockers": []
  },
  "branding": { "title": "Acme Club", "favicon_attachment_id": 91, "inherited": false },
  "created_at": "…Z", "updated_at": "…Z"
}
```

`host` is always the stored ASCII form; `host_display` is decorative. The `challenge`
**is** exposed — it is a value the domain owner must publish in public DNS, not a
credential. SSL credentials appear in no response, ever.

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

**Collections do not compute `serving`** — doing so runs `pd_mapping_is_active` and a
`ContentPolicy` validation per row, N filter invocations whose cost the plugin does not
control. `GET /domains` returns the three raw states; `GET /domains?_compute=serving`
opts in, capped at the page size, with the cost documented at the parameter. Individual
resources always compute it.

### 15.2 Routes

| Method | Route | Notes |
|---|---|---|
| `GET` | `/domains` | paginated; filters `verification_state`, `activation_state`, `ssl_state`, `post_id`, `search` |
| `POST` | `/domains` | `{host, post_id?, alias_of?, title?, favicon_attachment_id?}` |
| `GET` | `/domains/{id}` | |
| `PATCH` | `/domains/{id}` | `post_id`, `activation_state`, `title`, `favicon_attachment_id` only |
| `DELETE` | `/domains/{id}` | 202 + durable removal; `?force=true` after the ceiling |
| `POST` | `/domains/{id}/verify` | on-demand check; rate-limited 1/min per mapping |
| `POST` | `/domains/{id}/challenge` | rotates; resets verification; response says so |
| `GET` | `/domains/{id}/dns` | returns a `DnsPlan` |
| `POST` | `/domains/{id}/ssl` | `ensure()`, idempotent |
| `DELETE` | `/domains/{id}/ssl` | `remove()`, idempotent |
| `POST` | `/domains/{id}/ssl/adopt` | `{confirm:true}` — explicit ownership adoption |
| `GET` | `/environment` | installation id, stored vs current primary host, mismatch flag |
| `POST` | `/environment/resolve` | `{choice:"restore"\|"clone"}` |

`PATCH` excluding verification and SSL state is the API expression of invariant 1: there
is no request that makes a mapping verified. `verification`, `ssl`, `revision`, and
`deletion` are read-only. `post_id` on an alias row returns `pd_alias_no_target`.

### 15.3 Errors

`pd_host_invalid` (400), `pd_host_exists` (409), `pd_host_too_long` (400, naming the
composed TXT length), `pd_label_invalid` (400), `pd_alias_chain` (400),
`pd_alias_no_target` (400), `pd_post_invalid` (400), `pd_alias_in_use` (409),
`pd_conflict` (409), `pd_precondition_required` (428), `pd_precondition_failed` (412),
`pd_rate_limited` (429), `pd_environment_unresolved` (409), `pd_unowned_resource` (409),
`pd_forbidden` (403). Any of these requested on a non-primary host produces a plain 404
from WordPress, because the route does not exist there.

---

## 16. Admin

### 16.1 Screens

**Domains** — host (Unicode display, ASCII on hover), target, and three state chips.
The computed `serving` chip appears only on expanded rows, matching the collection
split. Row actions: verify now, rotate challenge, provision SSL, deactivate, delete.
Bulk: activate, deactivate, verify.

**Add domain** — one screen, three steps, no server-side wizard state: host → target
post (post-type-agnostic search restricted to the configured types) → the DNS plan,
grouped by purpose, OR-alternatives shown as "create any one of these", blockers shown
as blockers. The row is created `pending`/`inactive` at step one so the challenge exists
before instructions are displayed.

**Domain detail** — the DNS plan with per-record copy buttons; a live "last checked /
next attempt / last outcome" block; the event log; raw `ssl_provider_state`; SSL
actions.

**Settings** — target post types, SSL driver, DoH endpoints, admin-redirect toggle,
asset-proxy toggle, and generated web-server/CDN snippets for the 421 rule and CORS.

**Diagnostics** — sweep backlog depth and oldest due timestamp; WP-Cron health;
round-trip failures; path collisions; absolute primary-host URLs found in a rendered
mapped page; the browser-side CORS probe; unowned or missing SSL resources; the
environment-mismatch banner.

### 16.2 Operator flows

*Add a domain*: create → copy TXT → wait → verified → activate → optionally provision
SSL. Nothing serves until verified **and** active.

*Domain stops working*: the event log distinguishes "customer removed the TXT record"
(three hard failures, `failed`) from "our resolver was unreachable" (transient, still
verified) without anyone reading a log file.

*Move / restore / clone*: the blocking banner forces the choice before any provider
mutation runs.

*Remove a domain*: `202`, serving stops immediately, provider cleanup retries durably,
hard delete only after confirmation, force-delete available and recorded.

---

## 17. Testing

**Unit (no WordPress):** `IdnaNormalizer` against the fixed UTS-46 vectors;
`PathDecomposer`; `PathNormalizer`; `Subtree` round-trip as a **property** over a
generated fixture tree; collision ambiguity; grace arithmetic and counter resets;
`CanonicalPolicy`; `UrlPolicy`; `Classifier`; the Cloudflare status map, one case per
documented value; DoH response-shape rejection; `HostValue` / `AbsoluteUrl` validators
including scheme-downgrade rejection.

**Integration (wp-env):** the §7.2 compatibility matrix asserted on **rendered output**,
not on filter registration; query-string preservation through the unmatched redirect;
the full disposition matrix (400 / 421 / 404 / 503 / serve) across host states; admin
redirect method-awareness (302 vs 307) and the admin-ajax exemption; REST management
absent from discovery on a mapped host; CORS header presence and exact value; feed and
sitemap membership enforcement with an injected non-member; empty `post__in`
short-circuit; CAS conflict returning 409; lease conflict discarding a DNS result;
uninstall leaving posts untouched.

---

## 18. Build, release, uninstall

Composer with `symfony/polyfill-intl-idn:1.38.1` and `jeremykendall/php-domain-parser`
pinned exactly, `composer.lock` committed, `composer audit` in CI, and PHP-Scoper
prefixing all vendor code into `PostDomain\Vendor\` so the shipped plugin cannot collide
with or be hijacked by another plugin's autoloader. The release artifact is the prefixed
build, never the raw vendor tree. PHPCS against WordPress-Extra; PHPStan level 8.

Minimum: **WordPress 6.4, PHP 8.1.**

`uninstall.php` drops `pd_domains` and `pd_domain_events` and deletes
`pd_schema_version`, `pd_schema_engine`, `pd_settings`, `pd_ssl_credentials`,
`pd_installation_id`, `pd_installation_primary_host`, `pd_environment_mismatch`,
`pd_provider_cooldowns`, and all cron events. **No post, meta, or option belonging to
anything else is touched.**

---

## 19. Documentation deliverables

A README covering: installation; the DNS records a domain owner must create and why the
TXT record must stay; the complete filter reference with defaults, postconditions, and
examples; the `SslDriver` interface and how to add a driver; the `pd_dns_resolver`
trust boundary; the multisite exclusion and its reasoning; the 421 default and the
infrastructure allowlist, including the web-server rule the plugin cannot apply; the
CORS hosting boundary; the auth consequences of mapped-host REST and admin-ajax; the
`init : 99` registration requirement and the early-URL limitation; and the clone-detection
behaviour.

---

## 20. Open items

None blocking. Two items are deliberately deferred to implementation:

1. `references/cloudflare-status-map.php` must be **generated from the current
   Cloudflare API schema**, not transcribed. The published documentation confirms the
   two-axis model and the `active`/`active` ready combination but does not enumerate
   every value; the fixture file is the source of truth and the generation step is part
   of the first Cloudflare-driver task.
2. The `CteSubtreeAdapter` DB-capability probe needs a concrete minimum-version matrix
   (MySQL 8.0, MariaDB 10.2.2) confirmed against the versions in the target
   environments during that task.
