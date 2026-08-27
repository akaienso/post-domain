# post-domain 03 — Request classification, context, and dispositions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every request is classified from its raw path, its host is resolved to a
kind, policy is frozen in three ordered phases, and the request receives exactly
one of five dispositions: 400, 421, 404, 503, or serve.

**Architecture:** Two orthogonal classification axes decided at two different
times — `EndpointClass` from the raw path at `plugins_loaded : 0`, and
`Representation` from query vars at `parse_request`. Context is immutable and
built in three phases whose boundary is what must already exist for the answer to
be validatable: host policy, then serving eligibility, then content policy after
post types are registered.

**Tech Stack:** As Plans 01–02.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–02, and add:

- **A missing `ServingContext` never means "fall through as primary."** Every
  outcome is an explicit `Disposition` (spec §5.3).
- **Unknown hosts get `421` by default.** `PD_UNKNOWN_HOST_POLICY = 'passthrough'`
  is the documented lock-out escape, settable in `wp-config.php` (spec §5.3).
- **`wp-cron.php` over HTTP is fully host-validated.** The bypass keys on genuine
  hostlessness (`PHP_SAPI === 'cli'` or no `HTTP_HOST`) and on `WP_CLI`, never on
  `DOING_CRON` (spec §5.3).
- **Management REST routes are registered only when `HostContext::kind === PRIMARY`**
  (spec §9).
- **Redirects are temporary and method-aware:** 302 for GET/HEAD, 307 otherwise
  (spec §5.4).
- Filter results are re-validated and clamped; violations are logged and ignored,
  never honoured (spec §11.8).

---

## File map

| File | Responsibility |
|---|---|
| `src/Routing/EndpointClass.php` | The sixteen raw-path classes |
| `src/Routing/Classifier.php` | Raw path plus server state → `EndpointClass` |
| `src/Routing/Representation.php` | HTML / feed / embed / trackback / JSON |
| `src/Routing/PathDecomposition.php` | Base path plus representation and pagination suffixes |
| `src/Routing/PathDecomposer.php` | Splits suffixes off before the subtree walk |
| `src/Routing/HostKind.php` | Primary / mapped / allowed-infrastructure / unknown / malformed |
| `src/Routing/HostContext.php` | Immutable, always present |
| `src/Routing/HostContextFactory.php` | Phase A: parse, allowlist, normalize, look up |
| `src/Routing/ServingEligibility.php` | Phase B: may this host serve at all? |
| `src/Routing/ContentPolicy.php` | Phase C: post types, statuses, effective target |
| `src/Routing/ServingContext.php` | Immutable, frozen at `init : 99` |
| `src/Routing/ContextHolder.php` | Holds both; scoped push and pop for background work |
| `src/Routing/Disposition.php` | The seven outcomes |
| `src/Routing/UnknownHostGuard.php` | 400 and 421, at `plugins_loaded : 1` |
| `src/Http/AdminRedirect.php` | 302/307 to the primary host, ajax exempt |
| `src/Routing/MappedHostGuard.php` | Enforces the frozen disposition at `parse_request : 0` |
| `src/Routing/QueryVarPolicy.php` | The preserved-var allowlist and its reserved subtraction |
| `src/Plugin.php` | Composition root: builds the container and registers hooks |

---

### Task 1: Endpoint classification from the raw path

**Files:**
- Create: `src/Routing/EndpointClass.php`, `src/Routing/Classifier.php`
- Test: `tests/unit/Routing/ClassifierTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Routing\EndpointClass` (backed string enum) and
  `PostDomain\Routing\Classifier::__construct( string $rest_prefix )`,
  `::classify( string $path, array $server, array $get ): EndpointClass`.

REST is detected in **both** forms — the path prefix and `?rest_route=` — because
a `rest_route` request has path `/` and would otherwise be handed to the subtree
resolver as a root request (spec §4.1).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Routing/ClassifierTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\EndpointClass;

final class ClassifierTest extends TestCase {

	private function classify( string $path, array $server = array(), array $get = array() ): EndpointClass {
		return ( new Classifier( 'wp-json' ) )->classify( $path, $server, $get );
	}

	/**
	 * @dataProvider paths
	 */
	public function test_paths_classify( string $path, EndpointClass $expected ): void {
		$this->assertSame( $expected, $this->classify( $path ) );
	}

	/**
	 * @return array<string, array{0: string, 1: EndpointClass}>
	 */
	public static function paths(): array {
		return array(
			'admin'             => array( '/wp-admin/edit.php', EndpointClass::ADMIN ),
			'admin root'        => array( '/wp-admin/', EndpointClass::ADMIN ),
			'ajax'              => array( '/wp-admin/admin-ajax.php', EndpointClass::AJAX ),
			'ajax not prefix'   => array( '/wp-admin/admin-ajax.php.bak', EndpointClass::ADMIN ),
			'login'             => array( '/wp-login.php', EndpointClass::LOGIN ),
			'signup'            => array( '/wp-signup.php', EndpointClass::INFRASTRUCTURE ),
			'rest management'   => array( '/wp-json/post-domain/v1/domains', EndpointClass::REST_MANAGEMENT ),
			'rest content'      => array( '/wp-json/wp/v2/posts', EndpointClass::REST_CONTENT ),
			'comment post'      => array( '/wp-comments-post.php', EndpointClass::COMMENT_POST ),
			'trackback'         => array( '/wp-trackback.php', EndpointClass::TRACKBACK ),
			'xmlrpc'            => array( '/xmlrpc.php', EndpointClass::XMLRPC ),
			'cron over http'    => array( '/wp-cron.php', EndpointClass::CRON_HTTP ),
			'opml'              => array( '/wp-links-opml.php', EndpointClass::INFRASTRUCTURE ),
			'uploads'           => array( '/wp-content/uploads/a.woff2', EndpointClass::ASSET ),
			'includes'          => array( '/wp-includes/js/a.js', EndpointClass::ASSET ),
			'robots'            => array( '/robots.txt', EndpointClass::WELL_KNOWN ),
			'favicon'           => array( '/favicon.ico', EndpointClass::WELL_KNOWN ),
			'well known'        => array( '/.well-known/post-domain-probe', EndpointClass::WELL_KNOWN ),
			'core sitemap'      => array( '/wp-sitemap.xml', EndpointClass::SITEMAP ),
			'plugin sitemap'    => array( '/sitemap_index.xml', EndpointClass::SITEMAP ),
			'ordinary content'  => array( '/events/gala/', EndpointClass::ROUTED ),
			'root'              => array( '/', EndpointClass::ROUTED ),
		);
	}

	public function test_rest_route_query_form_is_rest_even_at_the_root(): void {
		$this->assertSame(
			EndpointClass::REST_CONTENT,
			$this->classify( '/', array(), array( 'rest_route' => '/wp/v2/posts' ) )
		);
	}

	public function test_rest_route_query_form_detects_the_management_namespace(): void {
		$this->assertSame(
			EndpointClass::REST_MANAGEMENT,
			$this->classify( '/', array(), array( 'rest_route' => '/post-domain/v1/domains' ) )
		);
	}

	public function test_cli_is_detected_from_hostlessness_not_from_doing_cron(): void {
		$this->assertSame(
			EndpointClass::CLI,
			$this->classify( '/wp-cron.php', array( 'PD_SAPI' => 'cli' ) )
		);

		$this->assertSame(
			EndpointClass::CRON_HTTP,
			$this->classify( '/wp-cron.php', array( 'HTTP_HOST' => 'example.test', 'PD_SAPI' => 'fpm-fcgi' ) ),
			'wp-cron.php over HTTP stays host-validated'
		);
	}

	public function test_a_custom_rest_prefix_is_honoured(): void {
		$this->assertSame(
			EndpointClass::REST_CONTENT,
			( new Classifier( 'api' ) )->classify( '/api/wp/v2/posts', array(), array() )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter ClassifierTest`
Expected: FAIL — `Error: Enum "PostDomain\Routing\EndpointClass" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/EndpointClass.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum EndpointClass: string {
	case CLI             = 'cli';
	case CRON            = 'cron';
	case ADMIN           = 'admin';
	case LOGIN           = 'login';
	case AJAX            = 'ajax';
	case REST_MANAGEMENT = 'rest_management';
	case REST_CONTENT    = 'rest_content';
	case COMMENT_POST    = 'comment_post';
	case TRACKBACK       = 'trackback';
	case XMLRPC          = 'xmlrpc';
	case CRON_HTTP       = 'cron_http';
	case INFRASTRUCTURE  = 'infrastructure';
	case ASSET           = 'asset';
	case WELL_KNOWN      = 'well_known';
	case SITEMAP         = 'sitemap';
	case ROUTED          = 'routed';

	/** Classes a filter may never produce or replace (spec §11.8). */
	public function is_protected(): bool {
		return ! in_array( $this, array( self::ROUTED, self::WELL_KNOWN, self::SITEMAP ), true );
	}
}
```

Create `src/Routing/Classifier.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Raw path only. No conditional tag is called: is_feed() and friends need query
 * vars that do not exist yet at plugins_loaded.
 */
final class Classifier {

	private const MANAGEMENT_NAMESPACE = 'post-domain/v1';

	public function __construct( private readonly string $rest_prefix ) {}

	/**
	 * @param array<string, mixed> $server
	 * @param array<string, mixed> $get
	 */
	public function classify( string $path, array $server, array $get ): EndpointClass {
		if ( 'cli' === ( $server['PD_SAPI'] ?? '' ) || ! isset( $server['HTTP_HOST'] ) ) {
			return EndpointClass::CLI;
		}

		$path = '/' . ltrim( parse_url( $path, PHP_URL_PATH ) ?? $path, '/' );

		if ( isset( $get['rest_route'] ) ) {
			return $this->rest_class( (string) $get['rest_route'] );
		}

		$prefix = '/' . trim( $this->rest_prefix, '/' ) . '/';

		if ( str_starts_with( $path, $prefix ) ) {
			return $this->rest_class( substr( $path, strlen( $prefix ) - 1 ) );
		}

		if ( '/wp-admin/admin-ajax.php' === $path ) {
			return EndpointClass::AJAX;
		}

		foreach (
			array(
				'/wp-login.php'       => EndpointClass::LOGIN,
				'/wp-comments-post.php' => EndpointClass::COMMENT_POST,
				'/wp-trackback.php'   => EndpointClass::TRACKBACK,
				'/xmlrpc.php'         => EndpointClass::XMLRPC,
				'/wp-cron.php'        => EndpointClass::CRON_HTTP,
				'/wp-signup.php'      => EndpointClass::INFRASTRUCTURE,
				'/wp-activate.php'    => EndpointClass::INFRASTRUCTURE,
				'/wp-links-opml.php'  => EndpointClass::INFRASTRUCTURE,
				'/wp-mail.php'        => EndpointClass::INFRASTRUCTURE,
				'/robots.txt'         => EndpointClass::WELL_KNOWN,
				'/favicon.ico'        => EndpointClass::WELL_KNOWN,
			) as $exact => $class
		) {
			if ( $exact === $path ) {
				return $class;
			}
		}

		if ( str_starts_with( $path, '/.well-known/' ) ) {
			return EndpointClass::WELL_KNOWN;
		}

		if ( str_starts_with( $path, '/wp-content/' ) || str_starts_with( $path, '/wp-includes/' ) ) {
			return EndpointClass::ASSET;
		}

		if ( 1 === preg_match( '~^/(wp-)?sitemap[^/]*\.xml$~', $path ) ) {
			return EndpointClass::SITEMAP;
		}

		if ( str_starts_with( $path, '/wp-admin/' ) ) {
			return EndpointClass::ADMIN;
		}

		return EndpointClass::ROUTED;
	}

	private function rest_class( string $route ): EndpointClass {
		$route = '/' . ltrim( $route, '/' );

		return str_starts_with( $route, '/' . self::MANAGEMENT_NAMESPACE )
			? EndpointClass::REST_MANAGEMENT
			: EndpointClass::REST_CONTENT;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter ClassifierTest`
Expected: PASS — 27 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/EndpointClass.php src/Routing/Classifier.php tests/unit/Routing/ClassifierTest.php
git commit -m "Classify endpoints from the raw path alone

REST is detected in both forms. A ?rest_route= request has path / and would
otherwise be handed to the subtree resolver as a root request."
```

---

### Task 2: Representation and path decomposition

**Files:**
- Create: `src/Routing/Representation.php`, `src/Routing/PathDecomposition.php`, `src/Routing/PathDecomposer.php`
- Test: `tests/unit/Routing/PathDecomposerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Routing\Representation` enum, `PostDomain\Routing\PathDecomposition` (readonly `string $base`, `Representation $rep`, `?string $feed_type`, `?int $paged`, `?int $comment_page`, `string $raw_query`), and `PostDomain\Routing\PathDecomposer::decompose( string $request_uri ): PathDecomposition`.

Splitting suffixes off **before** the subtree walk is what lets a descendant feed
resolve its base content path while the walk stays a pure path→post function
(spec §4.3).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Routing/PathDecomposerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PostDomain\Routing\PathDecomposer;
use PostDomain\Routing\Representation;

final class PathDecomposerTest extends TestCase {

	private function decompose( string $uri ): \PostDomain\Routing\PathDecomposition {
		return ( new PathDecomposer() )->decompose( $uri );
	}

	public function test_a_plain_path_is_html(): void {
		$d = $this->decompose( '/events/gala/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::HTML, $d->rep );
		$this->assertNull( $d->paged );
		$this->assertSame( '', $d->raw_query );
	}

	public function test_a_descendant_feed_keeps_its_base_path(): void {
		$d = $this->decompose( '/events/gala/feed/atom/?utm_source=x' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::FEED, $d->rep );
		$this->assertSame( 'atom', $d->feed_type );
		$this->assertSame( 'utm_source=x', $d->raw_query );
	}

	public function test_a_bare_feed_suffix_has_no_type(): void {
		$d = $this->decompose( '/events/gala/feed/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::FEED, $d->rep );
		$this->assertNull( $d->feed_type );
	}

	public function test_an_embed_suffix_is_split(): void {
		$d = $this->decompose( '/events/gala/embed/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::EMBED, $d->rep );
	}

	public function test_pagination_is_split(): void {
		$d = $this->decompose( '/events/page/3/' );

		$this->assertSame( 'events', $d->base );
		$this->assertSame( 3, $d->paged );
	}

	public function test_comment_pagination_is_split(): void {
		$d = $this->decompose( '/events/gala/comment-page-2/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( 2, $d->comment_page );
	}

	public function test_a_feed_after_pagination_splits_both(): void {
		$d = $this->decompose( '/events/page/2/feed/rss2/' );

		$this->assertSame( 'events', $d->base );
		$this->assertSame( 2, $d->paged );
		$this->assertSame( Representation::FEED, $d->rep );
		$this->assertSame( 'rss2', $d->feed_type );
	}

	public function test_the_root_decomposes_to_an_empty_base(): void {
		$this->assertSame( '', $this->decompose( '/' )->base );
	}

	public function test_the_raw_query_is_preserved_verbatim(): void {
		$d = $this->decompose( '/x/?a=1&b=%20two&utm_campaign=spring+sale' );

		$this->assertSame( 'a=1&b=%20two&utm_campaign=spring+sale', $d->raw_query );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter PathDecomposerTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\PathDecomposer" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/Representation.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum Representation: string {
	case HTML      = 'html';
	case FEED      = 'feed';
	case EMBED     = 'embed';
	case TRACKBACK = 'trackback';
	case JSON      = 'json';
}
```

Create `src/Routing/PathDecomposition.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class PathDecomposition {

	public function __construct(
		public readonly string $base,
		public readonly Representation $rep,
		public readonly ?string $feed_type,
		public readonly ?int $paged,
		public readonly ?int $comment_page,
		public readonly string $raw_query
	) {}
}
```

Create `src/Routing/PathDecomposer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Splits representation and pagination suffixes off the path so the subtree walk
 * only ever sees a content path.
 */
final class PathDecomposer {

	public function decompose( string $request_uri ): PathDecomposition {
		$query = '';
		$path  = $request_uri;

		if ( str_contains( $request_uri, '?' ) ) {
			[ $path, $query ] = explode( '?', $request_uri, 2 );
		}

		$path = strtok( $path, '#' );
		$path = trim( (string) $path, '/' );

		$segments     = '' === $path ? array() : explode( '/', $path );
		$rep          = Representation::HTML;
		$feed_type    = null;
		$paged        = null;
		$comment_page = null;

		// Suffixes are trailing, so consume from the end.
		while ( array() !== $segments ) {
			$last = end( $segments );

			if ( 1 === preg_match( '/^comment-page-([0-9]+)$/', (string) $last, $m ) ) {
				$comment_page = (int) $m[1];
				array_pop( $segments );
				continue;
			}

			if ( Representation::HTML === $rep && in_array( $last, array( 'feed', 'embed' ), true ) ) {
				$rep = 'feed' === $last ? Representation::FEED : Representation::EMBED;
				array_pop( $segments );
				continue;
			}

			if ( Representation::HTML === $rep
				&& in_array( $last, array( 'rss', 'rss2', 'atom', 'rdf' ), true )
				&& count( $segments ) >= 2
				&& 'feed' === $segments[ count( $segments ) - 2 ] ) {
				$feed_type = (string) $last;
				$rep       = Representation::FEED;
				array_pop( $segments );
				array_pop( $segments );
				continue;
			}

			if ( null === $paged
				&& count( $segments ) >= 2
				&& 'page' === $segments[ count( $segments ) - 2 ]
				&& 1 === preg_match( '/^[0-9]+$/', (string) $last ) ) {
				$paged = (int) $last;
				array_pop( $segments );
				array_pop( $segments );
				continue;
			}

			break;
		}

		return new PathDecomposition(
			implode( '/', $segments ),
			$rep,
			$feed_type,
			$paged,
			$comment_page,
			$query
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter PathDecomposerTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/Representation.php src/Routing/PathDecomposition.php src/Routing/PathDecomposer.php tests/unit/Routing/PathDecomposerTest.php
git commit -m "Split representation and pagination suffixes before the subtree walk

A descendant feed has to resolve its base content path, and the walk stays a
pure path-to-post function because it never learns feeds exist."
```

---

### Task 3: Host context, phase A

**Files:**
- Create: `src/Routing/HostKind.php`, `src/Routing/HostContext.php`, `src/Routing/HostContextFactory.php`
- Test: `tests/integration/Routing/HostContextFactoryTest.php`

**Interfaces:**
- Consumes: `AuthorityParser`, `InfrastructureAllowlist`, `HostNormalizer`, `TrustedProxy` (Plan 01); `MappingRepository` (Plan 02).
- Produces:
  - `PostDomain\Routing\HostKind` enum.
  - `PostDomain\Routing\HostContext` — readonly `string $raw_authority`, `?Authority $authority`, `?string $ascii_host`, `HostKind $kind`, `?Mapping $mapping`, `EndpointClass $endpoint`, `bool $is_https`, `string $method`; plus `has_row(): bool` and `may_serve(): bool`.
  - `PostDomain\Routing\HostContextFactory::build( array $server, array $get ): HostContext`.

The order is the point: parse the authority, compare the allowlist, normalize,
then look up (spec §3.6, §5.2 Phase A).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/HostContextFactoryTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\HostContextFactory;
use PostDomain\Routing\HostKind;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\Schema;
use PostDomain\Support\TrustedProxy;
use WP_UnitTestCase;

final class HostContextFactoryTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
	}

	private function factory( array $allowlist = array() ): HostContextFactory {
		return new HostContextFactory(
			new TrustedProxy( array() ),
			new AuthorityParser(),
			new InfrastructureAllowlist( $allowlist ),
			new HostNormalizer( new IdnaNormalizer() ),
			new Classifier( 'wp-json' ),
			$this->repo,
			'primary.test'
		);
	}

	private function build( string $host, array $allowlist = array(), string $path = '/' ): \PostDomain\Routing\HostContext {
		return $this->factory( $allowlist )->build(
			array( 'HTTP_HOST' => $host, 'REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET' ),
			array()
		);
	}

	private function seed_mapping( string $host ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, $host, null, 42, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_the_primary_host_is_primary(): void {
		$this->assertSame( HostKind::PRIMARY, $this->build( 'primary.test' )->kind );
	}

	public function test_a_mapped_host_is_mapped_whatever_its_state(): void {
		$this->seed_mapping( 'mapped.test' );
		$context = $this->build( 'mapped.test' );

		$this->assertSame( HostKind::MAPPED, $context->kind );
		$this->assertTrue( $context->has_row() );
		$this->assertFalse( $context->may_serve(), 'unverified and inactive must not serve' );
	}

	public function test_an_unknown_host_is_unknown(): void {
		$this->assertSame( HostKind::UNKNOWN, $this->build( 'stranger.test' )->kind );
	}

	public function test_a_malformed_authority_is_malformed(): void {
		$this->assertSame( HostKind::MALFORMED, $this->build( 'bad host:' )->kind );
	}

	public function test_an_allowlisted_host_is_infrastructure(): void {
		$this->assertSame(
			HostKind::ALLOWED_INFRASTRUCTURE,
			$this->build( 'health.internal', array( 'health.internal' ) )->kind
		);
	}

	public function test_an_allowlisted_ip_literal_is_infrastructure(): void {
		$this->assertSame(
			HostKind::ALLOWED_INFRASTRUCTURE,
			$this->build( '10.0.0.4', array( '10.0.0.4' ) )->kind
		);
	}

	public function test_a_malformed_near_match_of_an_allowlisted_host_is_still_malformed(): void {
		$this->assertSame(
			HostKind::MALFORMED,
			$this->build( 'health.internal:', array( 'health.internal' ) )->kind,
			'a malformed authority must never be reshaped into an allowlisted host'
		);
	}

	public function test_a_unicode_host_matches_its_punycode_row(): void {
		$this->seed_mapping( 'xn--mnchen-3ya.example' );
		$context = $this->build( 'münchen.example' );

		$this->assertSame( HostKind::MAPPED, $context->kind );
		$this->assertSame( 'xn--mnchen-3ya.example', $context->ascii_host );
	}

	public function test_the_endpoint_class_is_carried(): void {
		$this->assertSame(
			\PostDomain\Routing\EndpointClass::ADMIN,
			$this->build( 'primary.test', array(), '/wp-admin/edit.php' )->endpoint
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter HostContextFactoryTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\HostContextFactory" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/HostKind.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum HostKind: string {
	case PRIMARY                = 'primary';
	case MAPPED                 = 'mapped';
	case ALLOWED_INFRASTRUCTURE = 'allowed_infrastructure';
	case UNKNOWN                = 'unknown';
	case MALFORMED              = 'malformed';
}
```

Create `src/Routing/HostContext.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Authority;

final class HostContext {

	public function __construct(
		public readonly string $raw_authority,
		public readonly ?Authority $authority,
		public readonly ?string $ascii_host,
		public readonly HostKind $kind,
		public readonly ?Mapping $mapping,
		public readonly EndpointClass $endpoint,
		public readonly bool $is_https,
		public readonly string $method
	) {}

	public function has_row(): bool {
		return null !== $this->mapping;
	}

	/** Stored eligibility only; the filter veto is applied in phase B. */
	public function may_serve(): bool {
		if ( null === $this->mapping ) {
			return false;
		}

		return VerificationState::VERIFIED === $this->mapping->verification_state
			&& ActivationState::ACTIVE === $this->mapping->activation_state
			&& null === $this->mapping->integrity_error;
	}
}
```

Create `src/Routing/HostContextFactory.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\TrustedProxy;

final class HostContextFactory {

	public function __construct(
		private readonly TrustedProxy $proxy,
		private readonly AuthorityParser $parser,
		private readonly InfrastructureAllowlist $allowlist,
		private readonly HostNormalizer $normalizer,
		private readonly Classifier $classifier,
		private readonly MappingRepository $repo,
		private readonly string $primary_host
	) {}

	/**
	 * @param array<string, mixed> $server
	 * @param array<string, mixed> $get
	 */
	public function build( array $server, array $get ): HostContext {
		$raw      = $this->proxy->served_authority( $server );
		$path     = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$endpoint = $this->classifier->classify( $path, $server, $get );
		$https    = ! empty( $server['HTTPS'] ) && 'off' !== $server['HTTPS'];
		$method   = strtoupper( isset( $server['REQUEST_METHOD'] ) ? (string) $server['REQUEST_METHOD'] : 'GET' );

		$authority = $this->parser->parse( $raw );

		if ( null === $authority ) {
			return new HostContext( $raw, null, null, HostKind::MALFORMED, null, $endpoint, $https, $method );
		}

		if ( $this->allowlist->allows( $authority ) ) {
			return new HostContext(
				$raw,
				$authority,
				null,
				HostKind::ALLOWED_INFRASTRUCTURE,
				null,
				$endpoint,
				$https,
				$method
			);
		}

		$ascii = $this->normalizer->normalize( $authority );

		if ( null === $ascii ) {
			return new HostContext( $raw, $authority, null, HostKind::MALFORMED, null, $endpoint, $https, $method );
		}

		if ( $ascii === $this->primary_host ) {
			return new HostContext( $raw, $authority, $ascii, HostKind::PRIMARY, null, $endpoint, $https, $method );
		}

		$mapping = $this->repo->by_host( $ascii );

		return new HostContext(
			$raw,
			$authority,
			$ascii,
			null === $mapping ? HostKind::UNKNOWN : HostKind::MAPPED,
			$mapping,
			$endpoint,
			$https,
			$method
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter HostContextFactoryTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/HostKind.php src/Routing/HostContext.php src/Routing/HostContextFactory.php tests/integration/Routing/HostContextFactoryTest.php
git commit -m "Build the host context in the specified order

Parse, then allowlist, then normalize, then look up. A malformed near-match of
an allowlisted host stays malformed, which is the ordering's whole purpose."
```

---

### Task 4: The unknown-host guard

**Files:**
- Create: `src/Routing/UnknownHostGuard.php`
- Test: `tests/integration/Routing/UnknownHostGuardTest.php`

**Interfaces:**
- Consumes: `HostContext` (Task 3).
- Produces: `PostDomain\Routing\UnknownHostGuard::__construct( string $policy )` and `::response_for( HostContext $c ): ?int` — the HTTP status to send, or `null` to continue.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/UnknownHostGuardTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\UnknownHostGuard;
use WP_UnitTestCase;

final class UnknownHostGuardTest extends WP_UnitTestCase {

	private function context( HostKind $kind, EndpointClass $endpoint = EndpointClass::ROUTED ): HostContext {
		return new HostContext( 'x', null, null, $kind, null, $endpoint, true, 'GET' );
	}

	public function test_a_malformed_authority_is_400(): void {
		$this->assertSame(
			400,
			( new UnknownHostGuard( '421' ) )->response_for( $this->context( HostKind::MALFORMED ) )
		);
	}

	public function test_an_unknown_host_is_421_by_default(): void {
		$this->assertSame(
			421,
			( new UnknownHostGuard( '421' ) )->response_for( $this->context( HostKind::UNKNOWN ) )
		);
	}

	public function test_passthrough_policy_lets_an_unknown_host_continue(): void {
		$this->assertNull(
			( new UnknownHostGuard( 'passthrough' ) )->response_for( $this->context( HostKind::UNKNOWN ) )
		);
	}

	public function test_passthrough_policy_still_rejects_a_malformed_authority(): void {
		$this->assertSame(
			400,
			( new UnknownHostGuard( 'passthrough' ) )->response_for( $this->context( HostKind::MALFORMED ) )
		);
	}

	public function test_primary_mapped_and_infrastructure_continue(): void {
		$guard = new UnknownHostGuard( '421' );

		foreach ( array( HostKind::PRIMARY, HostKind::MAPPED, HostKind::ALLOWED_INFRASTRUCTURE ) as $kind ) {
			$this->assertNull( $guard->response_for( $this->context( $kind ) ) );
		}
	}

	public function test_cli_never_fires_the_guard(): void {
		$this->assertNull(
			( new UnknownHostGuard( '421' ) )->response_for(
				$this->context( HostKind::UNKNOWN, EndpointClass::CLI )
			)
		);
	}

	public function test_cron_over_http_is_still_guarded(): void {
		$this->assertSame(
			421,
			( new UnknownHostGuard( '421' ) )->response_for(
				$this->context( HostKind::UNKNOWN, EndpointClass::CRON_HTTP )
			),
			'wp-cron.php over HTTP is an ordinary request with a Host header'
		);
	}

	public function test_an_unrecognised_policy_falls_back_to_421(): void {
		$this->assertSame(
			421,
			( new UnknownHostGuard( 'nonsense' ) )->response_for( $this->context( HostKind::UNKNOWN ) )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter UnknownHostGuardTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\UnknownHostGuard" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/UnknownHostGuard.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Runs at plugins_loaded : 1, before the admin redirect. Its position is not
 * filterable; only the allowlist is data.
 */
final class UnknownHostGuard {

	public const POLICIES = array( '421', 'passthrough' );

	private string $policy;

	public function __construct( string $policy ) {
		$this->policy = in_array( $policy, self::POLICIES, true ) ? $policy : '421';
	}

	public function response_for( HostContext $context ): ?int {
		if ( EndpointClass::CLI === $context->endpoint || EndpointClass::CRON === $context->endpoint ) {
			return null;
		}

		if ( HostKind::MALFORMED === $context->kind ) {
			return 400;
		}

		if ( HostKind::UNKNOWN === $context->kind && '421' === $this->policy ) {
			return 421;
		}

		return null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter UnknownHostGuardTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/UnknownHostGuard.php tests/integration/Routing/UnknownHostGuardTest.php
git commit -m "Reject unknown hosts with 421 and malformed ones with 400

Passthrough remains available through PD_UNKNOWN_HOST_POLICY as the lock-out
escape, and it still rejects a malformed authority."
```

---

### Task 5: Admin redirect, method-aware, ajax exempt

**Files:**
- Create: `src/Http/AdminRedirect.php`
- Test: `tests/integration/Http/AdminRedirectTest.php`

**Interfaces:**
- Consumes: `HostContext` (Task 3).
- Produces: `PostDomain\Http\AdminRedirect::__construct( string $primary_origin, bool $enabled )` and `::redirect_for( HostContext $c, string $request_uri ): ?array{url: string, status: int}`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Http/AdminRedirectTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Http;

use PostDomain\Http\AdminRedirect;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use WP_UnitTestCase;

final class AdminRedirectTest extends WP_UnitTestCase {

	private function context( EndpointClass $endpoint, string $method = 'GET', HostKind $kind = HostKind::MAPPED ): HostContext {
		return new HostContext( 'mapped.test', null, 'mapped.test', $kind, null, $endpoint, true, $method );
	}

	public function test_admin_on_a_mapped_host_redirects_temporarily(): void {
		$redirect = ( new AdminRedirect( 'https://primary.test', true ) )
			->redirect_for( $this->context( EndpointClass::ADMIN ), '/wp-admin/edit.php?post_type=page' );

		$this->assertSame( 302, $redirect['status'] );
		$this->assertSame( 'https://primary.test/wp-admin/edit.php?post_type=page', $redirect['url'] );
	}

	public function test_a_non_idempotent_method_uses_307(): void {
		$redirect = ( new AdminRedirect( 'https://primary.test', true ) )
			->redirect_for( $this->context( EndpointClass::LOGIN, 'POST' ), '/wp-login.php' );

		$this->assertSame( 307, $redirect['status'], '307 preserves method and body' );
	}

	public function test_admin_ajax_is_exempt(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::AJAX ), '/wp-admin/admin-ajax.php' )
		);
	}

	public function test_the_primary_host_is_never_redirected(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::ADMIN, 'GET', HostKind::PRIMARY ), '/wp-admin/' )
		);
	}

	public function test_a_pending_mapping_still_redirects(): void {
		$this->assertNotNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::ADMIN ), '/wp-admin/' ),
			'a mapping that cannot serve is still not the primary host'
		);
	}

	public function test_the_redirect_can_be_disabled(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', false ) )
				->redirect_for( $this->context( EndpointClass::ADMIN ), '/wp-admin/' )
		);
	}

	public function test_a_routed_request_is_not_redirected(): void {
		$this->assertNull(
			( new AdminRedirect( 'https://primary.test', true ) )
				->redirect_for( $this->context( EndpointClass::ROUTED ), '/events/' )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter AdminRedirectTest`
Expected: FAIL — `Error: Class "PostDomain\Http\AdminRedirect" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Http/AdminRedirect.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;

/**
 * Default policy, not an invariant. What is invariant is the cookie boundary
 * underneath: auth cookies bind to COOKIE_DOMAIN and are never shared.
 */
final class AdminRedirect {

	public function __construct(
		private readonly string $primary_origin,
		private readonly bool $enabled
	) {}

	/**
	 * @return array{url: string, status: int}|null
	 */
	public function redirect_for( HostContext $context, string $request_uri ): ?array {
		if ( ! $this->enabled || HostKind::PRIMARY === $context->kind ) {
			return null;
		}

		if ( ! in_array( $context->endpoint, array( EndpointClass::ADMIN, EndpointClass::LOGIN ), true ) ) {
			return null;
		}

		$idempotent = in_array( $context->method, array( 'GET', 'HEAD' ), true );

		return array(
			'url'    => rtrim( $this->primary_origin, '/' ) . '/' . ltrim( $request_uri, '/' ),
			'status' => $idempotent ? 302 : 307,
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter AdminRedirectTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Http/AdminRedirect.php tests/integration/Http/AdminRedirectTest.php
git commit -m "Send admin and login on a mapped host back to the primary host

Temporary and method-aware: a permanent redirect cached in browsers outlives any
mapping change, and 307 preserves a POST that a 302 would silently turn into a
GET. admin-ajax is exempt so public ajax keeps working."
```

---

### Task 6: Serving eligibility and content policy

**Files:**
- Create: `src/Routing/ServingEligibility.php`, `src/Routing/ContentPolicy.php`, `src/Routing/ServingContext.php`, `src/Routing/ContextHolder.php`
- Test: `tests/integration/Routing/PolicyPhaseTest.php`

**Interfaces:**
- Consumes: `HostContext` (Task 3), `AliasResolver` (Plan 02).
- Produces:
  - `PostDomain\Routing\ServingEligibility` — readonly `Mapping $mapping`, `string $requested_host`, `string $canonical_host`, `bool $is_active`; built by `::decide( HostContext $c, AliasResolver $a ): ?self`.
  - `PostDomain\Routing\ContentPolicy::freeze( ServingEligibility $e, AliasResolver $a ): ?ServingContext`.
  - `PostDomain\Routing\ServingContext` — readonly `Mapping $mapping`, `string $requested_host`, `string $canonical_host`, `bool $is_active`, `int $effective_post_id`, `string[] $subtree_post_types`, `string[] $post_statuses`, `int $max_depth`, `string[] $preserved_query_vars`, `?Resolution $resolution`, `Representation $representation`; plus `with_resolution( Resolution $r ): self`.
  - `PostDomain\Routing\ContextHolder` — `host()`, `serving()`, `set_host()`, `set_serving()`, `resolve()`, `with( Mapping $m, callable $fn ): mixed`.

`pd_mapping_is_active` is strictly `$stored && (bool) $filtered` — veto only, once
per request (spec §11.2, §11.8).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/PolicyPhaseTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class PolicyPhaseTest extends WP_UnitTestCase {

	private DbRepository $repo;
	private AliasResolver $aliases;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->aliases = new AliasResolver( $this->repo );
		$this->post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_status' => 'publish' )
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_mapping_is_active' );
		remove_all_filters( 'pd_target_post_for_host' );
		remove_all_filters( 'pd_subtree_post_types' );
		parent::tear_down();
	}

	private function servable( string $host = 'mapped.test' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, $host, null, $this->post_id, 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
	}

	private function context( Mapping $m ): HostContext {
		return new HostContext(
			$m->host, null, $m->host, HostKind::MAPPED, $m, EndpointClass::ROUTED, true, 'GET'
		);
	}

	public function test_a_verified_active_mapping_is_eligible(): void {
		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNotNull( $eligibility );
		$this->assertTrue( $eligibility->is_active );
		$this->assertSame( 'mapped.test', $eligibility->canonical_host );
	}

	public function test_the_active_filter_can_veto(): void {
		add_filter( 'pd_mapping_is_active', '__return_false' );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNotNull( $eligibility );
		$this->assertFalse( $eligibility->is_active );
	}

	public function test_the_active_filter_cannot_grant(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'pending.test', null, $this->post_id, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge'
			)
		);

		add_filter( 'pd_mapping_is_active', '__return_true' );

		$eligibility = ServingEligibility::decide( $this->context( $mapping ), $this->aliases );

		$this->assertNotNull( $eligibility );
		$this->assertFalse( $eligibility->is_active, 'stored state ANDs with the filter' );
	}

	public function test_an_alias_reports_its_canonical_host(): void {
		$canonical = $this->servable( 'canonical.test' );
		$alias     = $this->repo->save(
			new Mapping(
				0, 'alias.test', $canonical->id, null, 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge'
			)
		);

		$eligibility = ServingEligibility::decide( $this->context( $alias ), $this->aliases );

		$this->assertSame( 'alias.test', $eligibility?->requested_host );
		$this->assertSame( 'canonical.test', $eligibility?->canonical_host );
	}

	public function test_content_policy_freezes_the_effective_target(): void {
		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );
		$serving     = ContentPolicy::freeze( (object) $eligibility === null ? null : $eligibility, $this->aliases );

		$this->assertNotNull( $serving );
		$this->assertSame( $this->post_id, $serving->effective_post_id );
		$this->assertSame( array( 'page' ), $serving->subtree_post_types );
		$this->assertSame( array( 'publish' ), $serving->post_statuses );
		$this->assertSame( 10, $serving->max_depth );
	}

	public function test_the_target_filter_is_validated_against_the_allowed_types(): void {
		$other = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		add_filter( 'pd_target_post_for_host', static fn(): int => $other );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNull(
			ContentPolicy::freeze( $eligibility, $this->aliases ),
			'a target outside the allowed post types is invalid, and invalid means 503'
		);
	}

	public function test_a_trashed_target_is_invalid(): void {
		wp_trash_post( $this->post_id );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertNull( ContentPolicy::freeze( $eligibility, $this->aliases ) );
	}

	public function test_max_depth_is_clamped(): void {
		add_filter( 'pd_max_subtree_depth', static fn(): int => 900 );

		$eligibility = ServingEligibility::decide( $this->context( $this->servable() ), $this->aliases );

		$this->assertSame( 25, ContentPolicy::freeze( $eligibility, $this->aliases )?->max_depth );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter PolicyPhaseTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\ServingEligibility" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/ServingEligibility.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\Mapping;

/**
 * Phase B, frozen at plugins_loaded : 11. Answers only "is this host permitted to
 * serve?" — no content-model question is asked here.
 */
final class ServingEligibility {

	public function __construct(
		public readonly Mapping $mapping,
		public readonly string $requested_host,
		public readonly string $canonical_host,
		public readonly bool $is_active
	) {}

	public static function decide( HostContext $context, AliasResolver $aliases ): ?self {
		$mapping = $context->mapping;

		if ( HostKind::MAPPED !== $context->kind || null === $mapping ) {
			return null;
		}

		$stored = $context->may_serve();

		/** Veto only: the filter can reduce, never grant. */
		$active = $stored && (bool) apply_filters( 'pd_mapping_is_active', $stored, $mapping, $context );

		return new self(
			$mapping,
			$mapping->host,
			$aliases->canonical_host( $mapping ),
			$active
		);
	}
}
```

Create `src/Routing/ServingContext.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\Mapping;

final class ServingContext {

	/**
	 * @param string[] $subtree_post_types
	 * @param string[] $post_statuses
	 * @param string[] $preserved_query_vars
	 */
	public function __construct(
		public readonly Mapping $mapping,
		public readonly string $requested_host,
		public readonly string $canonical_host,
		public readonly bool $is_active,
		public readonly int $effective_post_id,
		public readonly array $subtree_post_types,
		public readonly array $post_statuses,
		public readonly int $max_depth,
		public readonly array $preserved_query_vars,
		public readonly ?object $resolution = null,
		public readonly Representation $representation = Representation::HTML
	) {}

	public function with_resolution( object $resolution, Representation $representation ): self {
		return new self(
			$this->mapping,
			$this->requested_host,
			$this->canonical_host,
			$this->is_active,
			$this->effective_post_id,
			$this->subtree_post_types,
			$this->post_statuses,
			$this->max_depth,
			$this->preserved_query_vars,
			$resolution,
			$representation
		);
	}
}
```

Create `src/Routing/ContentPolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\AliasResolver;

/**
 * Phase C, frozen at init : 99 — the earliest point at which any of these answers
 * can be validated, because post types and statuses must already be registered.
 */
final class ContentPolicy {

	public static function freeze( ?ServingEligibility $eligibility, AliasResolver $aliases ): ?ServingContext {
		if ( null === $eligibility ) {
			return null;
		}

		$mapping   = $eligibility->mapping;
		$canonical = $aliases->canonical_for( $mapping );

		if ( null === $canonical || null === $canonical->post_id ) {
			return null;
		}

		$default_type = get_post_type( $canonical->post_id );

		if ( false === $default_type ) {
			return null;
		}

		/** @var string[] $types */
		$types = (array) apply_filters( 'pd_subtree_post_types', array( $default_type ), $mapping );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );

		if ( array() === $types ) {
			return null;
		}

		/** @var string[] $statuses */
		$statuses = (array) apply_filters( 'pd_post_statuses', array( 'publish' ), $mapping );
		$statuses = array_values(
			array_filter( $statuses, static fn( $s ): bool => is_string( $s ) && null !== get_post_status_object( $s ) )
		);

		if ( array() === $statuses ) {
			return null;
		}

		$target = (int) apply_filters( 'pd_target_post_for_host', (int) $canonical->post_id, $mapping );
		$post   = get_post( $target );

		if ( null === $post
			|| ! in_array( $post->post_type, $types, true )
			|| ! in_array( $post->post_status, $statuses, true ) ) {
			return null;
		}

		$depth = (int) apply_filters( 'pd_max_subtree_depth', 10, $mapping );
		$depth = max( 1, min( 25, $depth ) );

		return new ServingContext(
			$mapping,
			$eligibility->requested_host,
			$eligibility->canonical_host,
			$eligibility->is_active,
			$target,
			$types,
			$statuses,
			$depth,
			QueryVarPolicy::preserved( $mapping )
		);
	}
}
```

Create `src/Routing/ContextHolder.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\Mapping;

final class ContextHolder {

	private ?HostContext $host = null;

	private ?ServingContext $serving = null;

	public function set_host( HostContext $context ): void {
		$this->host = $context;
	}

	public function host(): ?HostContext {
		return $this->host;
	}

	public function set_serving( ?ServingContext $context ): void {
		$this->serving = $context;
	}

	public function serving(): ?ServingContext {
		return $this->serving;
	}

	public function resolve( object $resolution, Representation $representation ): void {
		if ( null !== $this->serving ) {
			$this->serving = $this->serving->with_resolution( $resolution, $representation );
		}
	}

	/**
	 * Scoped push and pop, so cron, CLI, and mail can borrow a mapping's context.
	 */
	public function with( ServingContext $context, callable $fn ): mixed {
		$previous      = $this->serving;
		$this->serving = $context;

		try {
			return $fn();
		} finally {
			$this->serving = $previous;
		}
	}

	public function mapping(): ?Mapping {
		return $this->serving?->mapping ?? $this->host?->mapping;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter PolicyPhaseTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/ServingEligibility.php src/Routing/ContentPolicy.php src/Routing/ServingContext.php src/Routing/ContextHolder.php tests/integration/Routing/PolicyPhaseTest.php
git commit -m "Freeze policy in three phases, each where its answer is checkable

Content policy cannot run before init:99 because post types are not registered
yet, and a target validated against unregistered types is not validated at all."
```

---

### Task 7: The preserved query-var allowlist

**Files:**
- Create: `src/Routing/QueryVarPolicy.php`
- Test: `tests/integration/Routing/QueryVarPolicyTest.php`

**Interfaces:**
- Consumes: `Mapping` (Plan 02).
- Produces: `PostDomain\Routing\QueryVarPolicy::preserved( Mapping $m ): string[]` and `::RESERVED` (the subtraction list).

The raw query string is preserved verbatim elsewhere; this allowlist governs only
what is copied into `$wp->query_vars` (spec §4.4, §11.8).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/QueryVarPolicyTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\QueryVarPolicy;
use WP_UnitTestCase;

final class QueryVarPolicyTest extends WP_UnitTestCase {

	private function mapping(): Mapping {
		return new Mapping(
			1, 'mapped.test', null, 42, 1,
			VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
			null, str_repeat( 'a', 32 ), '_post-domain-challenge'
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_preserved_query_vars' );
		parent::tear_down();
	}

	public function test_the_default_allowlist(): void {
		$this->assertSame(
			array( 'paged', 'page', 'cpage', 'replytocom', 'feed', 'embed' ),
			QueryVarPolicy::preserved( $this->mapping() )
		);
	}

	public function test_preview_vars_are_absent_by_default(): void {
		$preserved = QueryVarPolicy::preserved( $this->mapping() );

		foreach ( array( 'preview', 'preview_id', 'preview_nonce', 'attachment' ) as $var ) {
			$this->assertNotContains( $var, $preserved );
		}
	}

	public function test_a_filter_may_add_a_harmless_var(): void {
		add_filter(
			'pd_preserved_query_vars',
			static fn( array $vars ): array => array_merge( $vars, array( 'utm_medium' ) )
		);

		$this->assertContains( 'utm_medium', QueryVarPolicy::preserved( $this->mapping() ) );
	}

	public function test_reserved_routing_vars_are_subtracted_unconditionally(): void {
		add_filter(
			'pd_preserved_query_vars',
			static fn( array $vars ): array => array_merge(
				$vars,
				array( 'p', 'page_id', 'post_type', 'name', 'pagename', 'rest_route', 'preview' )
			)
		);

		$preserved = QueryVarPolicy::preserved( $this->mapping() );

		foreach ( QueryVarPolicy::RESERVED as $reserved ) {
			$this->assertNotContains( $reserved, $preserved, "{$reserved} must never be reintroduced" );
		}
	}

	public function test_malformed_var_names_are_dropped(): void {
		add_filter(
			'pd_preserved_query_vars',
			static fn( array $vars ): array => array_merge(
				$vars,
				array( 'Bad-Name', 'ok_name', str_repeat( 'x', 40 ), '' )
			)
		);

		$preserved = QueryVarPolicy::preserved( $this->mapping() );

		$this->assertContains( 'ok_name', $preserved );
		$this->assertNotContains( 'Bad-Name', $preserved );
		$this->assertNotContains( str_repeat( 'x', 40 ), $preserved );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter QueryVarPolicyTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\QueryVarPolicy" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/QueryVarPolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\Mapping;

final class QueryVarPolicy {

	public const DEFAULTS = array( 'paged', 'page', 'cpage', 'replytocom', 'feed', 'embed' );

	/** Never reachable through the filter: these steer WP_Query itself. */
	public const RESERVED = array(
		'p',
		'page_id',
		'name',
		'pagename',
		'post_type',
		'attachment',
		'attachment_id',
		'static',
		'error',
		'preview',
		'preview_id',
		'preview_nonce',
		'post_status',
		'rest_route',
	);

	/** @return string[] */
	public static function preserved( Mapping $mapping ): array {
		/** @var string[] $vars */
		$vars = (array) apply_filters( 'pd_preserved_query_vars', self::DEFAULTS, $mapping );

		$vars = array_filter(
			$vars,
			static fn( $var ): bool => is_string( $var ) && 1 === preg_match( '/^[a-z0-9_]{1,32}$/', $var )
		);

		$vars = array_diff( $vars, self::RESERVED );

		return array_values( array_unique( $vars ) );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter QueryVarPolicyTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/QueryVarPolicy.php tests/integration/Routing/QueryVarPolicyTest.php
git commit -m "Allowlist the query vars promoted into the query, subtract the routing ones

The raw query string survives untouched; this governs only what reaches
WP_Query. A routing var reintroduced by a filter turns a subtree page into an
archive."
```

---

### Task 8: Dispositions and the mapped-host guard

**Files:**
- Create: `src/Routing/Disposition.php`, `src/Routing/MappedHostGuard.php`
- Test: `tests/integration/Routing/DispositionMatrixTest.php`

**Interfaces:**
- Consumes: `HostContext` (Task 3), `ServingContext` (Task 6), `UnknownHostGuard` (Task 4).
- Produces: `PostDomain\Routing\Disposition` enum and `PostDomain\Routing\MappedHostGuard::decide( HostContext $c, ?ServingEligibility $e, ?ServingContext $s, string $unknown_policy ): Disposition`.

This is the plan's gate: a missing `ServingContext` never means "fall through as
primary" (spec §5.3).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/DispositionMatrixTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\Disposition;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\MappedHostGuard;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class DispositionMatrixTest extends WP_UnitTestCase {

	private DbRepository $repo;
	private AliasResolver $aliases;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->aliases = new AliasResolver( $this->repo );
		$this->post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_status' => 'publish' )
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_mapping_is_active' );
		parent::tear_down();
	}

	private function decide( HostContext $context ): Disposition {
		$eligibility = ServingEligibility::decide( $context, $this->aliases );
		$serving     = null === $eligibility || ! $eligibility->is_active
			? null
			: ContentPolicy::freeze( $eligibility, $this->aliases );

		return MappedHostGuard::decide( $context, $eligibility, $serving, '421' );
	}

	private function context( HostKind $kind, ?Mapping $mapping ): HostContext {
		return new HostContext(
			'x', null, $mapping?->host, $kind, $mapping, EndpointClass::ROUTED, true, 'GET'
		);
	}

	private function mapping( VerificationState $v, ActivationState $a, ?string $integrity = null, ?int $post = null ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped' . wp_rand( 1000, 9999 ) . '.test', null, $post ?? $this->post_id, 1,
				$v, $a, SslState::NONE, $integrity, str_repeat( wp_rand( 0, 9 ) . '', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_malformed_is_400(): void {
		$this->assertSame( Disposition::MALFORMED_400, $this->decide( $this->context( HostKind::MALFORMED, null ) ) );
	}

	public function test_unknown_is_421(): void {
		$this->assertSame( Disposition::UNKNOWN_421, $this->decide( $this->context( HostKind::UNKNOWN, null ) ) );
	}

	public function test_allowlisted_infrastructure_routes_as_primary(): void {
		$this->assertSame(
			Disposition::PRIMARY,
			$this->decide( $this->context( HostKind::ALLOWED_INFRASTRUCTURE, null ) )
		);
	}

	public function test_the_primary_host_routes_as_primary(): void {
		$this->assertSame( Disposition::PRIMARY, $this->decide( $this->context( HostKind::PRIMARY, null ) ) );
	}

	public function test_an_unverified_mapping_is_404(): void {
		$mapping = $this->mapping( VerificationState::UNVERIFIED, ActivationState::ACTIVE );

		$this->assertSame( Disposition::NOT_SERVING_404, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_an_inactive_mapping_is_404(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::INACTIVE );

		$this->assertSame( Disposition::NOT_SERVING_404, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_a_vetoed_mapping_is_404(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		add_filter( 'pd_mapping_is_active', '__return_false' );

		$this->assertSame( Disposition::NOT_SERVING_404, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_a_broken_content_policy_is_503(): void {
		$orphan  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, null, $orphan );
		wp_delete_post( $orphan, true );

		$this->assertSame( Disposition::BROKEN_503, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_an_integrity_error_is_503(): void {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'corrupt.test', null, $this->post_id, 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				'challenge_label_invalid', str_repeat( 'z', 32 ), '_post-domain-challenge'
			)
		);

		$this->assertSame( Disposition::BROKEN_503, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}

	public function test_a_healthy_mapping_serves(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$this->assertSame( Disposition::SERVE, $this->decide( $this->context( HostKind::MAPPED, $mapping ) ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter DispositionMatrixTest`
Expected: FAIL — `Error: Enum "PostDomain\Routing\Disposition" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/Disposition.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum Disposition: string {
	case PRIMARY         = 'primary';
	case INFRASTRUCTURE  = 'infrastructure';
	case SERVE           = 'serve';
	case MALFORMED_400   = 'malformed_400';
	case UNKNOWN_421     = 'unknown_421';
	case NOT_SERVING_404 = 'not_serving_404';
	case BROKEN_503      = 'broken_503';

	public function status(): ?int {
		return match ( $this ) {
			self::MALFORMED_400   => 400,
			self::UNKNOWN_421     => 421,
			self::NOT_SERVING_404 => 404,
			self::BROKEN_503      => 503,
			default               => null,
		};
	}
}
```

Create `src/Routing/MappedHostGuard.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Computed once at init : 99, enforced at parse_request : 0. A missing
 * ServingContext is never a fall-through: every path ends in a named outcome.
 */
final class MappedHostGuard {

	public static function decide(
		HostContext $context,
		?ServingEligibility $eligibility,
		?ServingContext $serving,
		string $unknown_policy
	): Disposition {
		$guard  = new UnknownHostGuard( $unknown_policy );
		$status = $guard->response_for( $context );

		if ( 400 === $status ) {
			return Disposition::MALFORMED_400;
		}

		if ( 421 === $status ) {
			return Disposition::UNKNOWN_421;
		}

		if ( HostKind::MAPPED !== $context->kind ) {
			return Disposition::PRIMARY;
		}

		if ( null === $eligibility || ! $eligibility->is_active ) {
			return Disposition::NOT_SERVING_404;
		}

		if ( null === $serving ) {
			return Disposition::BROKEN_503;
		}

		return Disposition::SERVE;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DispositionMatrixTest`
Expected: PASS — 10 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/Disposition.php src/Routing/MappedHostGuard.php tests/integration/Routing/DispositionMatrixTest.php
git commit -m "Give every request exactly one named disposition

404 rather than 421 for a known-but-not-serving host: the host is ours, it is
simply not serving. A broken content policy is 503, never a quiet fall-through
that would leak the primary site under a customer domain."
```

---

### Task 9: Composition root and hook registration

**Files:**
- Create: `src/Plugin.php`
- Modify: `post-domain.php` (call `Plugin::boot()`)
- Test: `tests/integration/PluginBootTest.php`

**Interfaces:**
- Consumes: everything in this plan.
- Produces: `PostDomain\Plugin::boot(): void` and `PostDomain\Plugin::instance(): self` with `::context(): ContextHolder` and `::repository(): MappingRepository`, which later plans resolve their dependencies from.

Hook priorities are the specification (spec §5.4).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/PluginBootTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Plugin;
use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

	public function test_hooks_register_at_the_specified_priorities(): void {
		Plugin::boot();

		$this->assertSame( 0, has_action( 'plugins_loaded', array( Plugin::instance(), 'build_host_context' ) ) );
		$this->assertSame( 1, has_action( 'plugins_loaded', array( Plugin::instance(), 'guard_unknown_host' ) ) );
		$this->assertSame( 2, has_action( 'plugins_loaded', array( Plugin::instance(), 'redirect_admin' ) ) );
		$this->assertSame( 11, has_action( 'plugins_loaded', array( Plugin::instance(), 'freeze_eligibility' ) ) );
		$this->assertSame( 99, has_action( 'init', array( Plugin::instance(), 'freeze_content_policy' ) ) );
		$this->assertSame( 0, has_action( 'parse_request', array( Plugin::instance(), 'enforce_disposition' ) ) );
	}

	public function test_the_container_exposes_the_context_holder_and_repository(): void {
		Plugin::boot();

		$this->assertInstanceOf( \PostDomain\Routing\ContextHolder::class, Plugin::instance()->context() );
		$this->assertInstanceOf( \PostDomain\Contracts\MappingRepository::class, Plugin::instance()->repository() );
	}

	public function test_booting_twice_does_not_double_register(): void {
		Plugin::boot();
		Plugin::boot();

		$this->assertSame(
			1,
			count( array_filter(
				$GLOBALS['wp_filter']['init'][99] ?? array(),
				static fn( array $entry ): bool =>
					is_array( $entry['function'] ) && $entry['function'][0] instanceof Plugin
			) )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter PluginBootTest`
Expected: FAIL — `Error: Class "PostDomain\Plugin" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Plugin.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Http\AdminRedirect;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\ContextHolder;
use PostDomain\Routing\Disposition;
use PostDomain\Routing\HostContextFactory;
use PostDomain\Routing\MappedHostGuard;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Routing\UnknownHostGuard;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\Schema;
use PostDomain\Support\TrustedProxy;

final class Plugin {

	private static ?self $instance = null;

	private ContextHolder $context;

	private MappingRepository $repository;

	private AliasResolver $aliases;

	private ?ServingEligibility $eligibility = null;

	private Disposition $disposition = Disposition::PRIMARY;

	private function __construct() {
		$this->context    = new ContextHolder();
		$this->repository = new DbRepository();
		$this->aliases    = new AliasResolver( $this->repository );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();

		if ( has_action( 'plugins_loaded', array( $plugin, 'build_host_context' ) ) ) {
			return;
		}

		add_action( 'plugins_loaded', array( $plugin, 'build_host_context' ), 0 );
		add_action( 'plugins_loaded', array( $plugin, 'guard_unknown_host' ), 1 );
		add_action( 'plugins_loaded', array( $plugin, 'redirect_admin' ), 2 );
		add_action( 'plugins_loaded', array( $plugin, 'freeze_eligibility' ), 11 );
		add_action( 'init', array( $plugin, 'freeze_content_policy' ), 99 );
		add_action( 'parse_request', array( $plugin, 'enforce_disposition' ), 0 );

		Schema::maybe_upgrade();
	}

	public function context(): ContextHolder {
		return $this->context;
	}

	public function repository(): MappingRepository {
		return $this->repository;
	}

	public function aliases(): AliasResolver {
		return $this->aliases;
	}

	public function disposition(): Disposition {
		return $this->disposition;
	}

	public function build_host_context(): void {
		/** @var string[] $allowlist */
		$allowlist = (array) apply_filters(
			'pd_allowed_infrastructure_hosts',
			defined( 'PD_ALLOWED_HOSTS' ) ? (array) constant( 'PD_ALLOWED_HOSTS' ) : array()
		);

		/** @var string[] $proxies */
		$proxies = (array) apply_filters(
			'pd_trusted_proxies',
			defined( 'PD_TRUSTED_PROXIES' ) ? (array) constant( 'PD_TRUSTED_PROXIES' ) : array()
		);

		$factory = new HostContextFactory(
			new TrustedProxy( $proxies ),
			new AuthorityParser(),
			new InfrastructureAllowlist( $allowlist ),
			new HostNormalizer( new IdnaNormalizer() ),
			new Classifier( rest_get_url_prefix() ),
			$this->repository,
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->context->set_host( $factory->build( $_SERVER, $_GET ) );
	}

	public function guard_unknown_host(): void {
		$host = $this->context->host();

		if ( null === $host ) {
			return;
		}

		$status = ( new UnknownHostGuard( $this->unknown_host_policy() ) )->response_for( $host );

		if ( null === $status ) {
			return;
		}

		status_header( $status );
		nocache_headers();
		exit;
	}

	public function redirect_admin(): void {
		$host = $this->context->host();

		if ( null === $host ) {
			return;
		}

		$enabled  = (bool) apply_filters( 'pd_admin_redirect', true );
		$redirect = ( new AdminRedirect( home_url(), $enabled ) )->redirect_for(
			$host,
			isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/'
		);

		if ( null === $redirect ) {
			return;
		}

		wp_redirect( $redirect['url'], $redirect['status'] ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	public function freeze_eligibility(): void {
		$host = $this->context->host();

		if ( null !== $host ) {
			$this->eligibility = ServingEligibility::decide( $host, $this->aliases );
		}
	}

	public function freeze_content_policy(): void {
		$serving = null === $this->eligibility || ! $this->eligibility->is_active
			? null
			: ContentPolicy::freeze( $this->eligibility, $this->aliases );

		$this->context->set_serving( $serving );

		$host = $this->context->host();

		if ( null !== $host ) {
			$this->disposition = MappedHostGuard::decide(
				$host,
				$this->eligibility,
				$serving,
				$this->unknown_host_policy()
			);
		}
	}

	public function enforce_disposition(): void {
		$status = $this->disposition->status();

		if ( null === $status || 400 === $status || 421 === $status ) {
			return;
		}

		status_header( $status );
		nocache_headers();
		exit;
	}

	private function unknown_host_policy(): string {
		$policy = defined( 'PD_UNKNOWN_HOST_POLICY' ) ? (string) constant( 'PD_UNKNOWN_HOST_POLICY' ) : '421';
		$policy = (string) apply_filters( 'pd_unknown_host_policy', $policy );

		return in_array( $policy, UnknownHostGuard::POLICIES, true ) ? $policy : '421';
	}
}
```

Append to `post-domain.php`, after the version guard:

```php
\PostDomain\Plugin::boot();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter PluginBootTest`
Expected: PASS — 3 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Plugin.php post-domain.php tests/integration/PluginBootTest.php
git commit -m "Wire the request pipeline at the specified hook priorities

The priorities are the specification, not a detail: the host context has to
exist before the guard can classify it, and the guard has to run before the
admin redirect."
```

---

## Gate for Plan 03

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `DispositionMatrixTest` passes all ten cases, covering 400, 421, primary,
404 for each of the three not-serving reasons, 503 for both broken reasons, and
serve.
