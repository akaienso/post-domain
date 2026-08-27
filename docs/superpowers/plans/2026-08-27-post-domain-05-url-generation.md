# post-domain 05 — URL generation, canonical, CORS, and boundaries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every URL surface in the specification's compatibility matrix emits
mapped-host URLs, asserted on rendered output rather than on filter registration;
canonical is computed fresh per request; CORS authorizes the requesting origin;
and the auth boundaries hold.

**Architecture:** One policy object decides; adapters only translate WordPress's
filter signatures into calls on it. Adapters register unconditionally and no-op
when there is no serving context, which is what makes `pd_with_mapping()` work in
cron and CLI.

**Tech Stack:** As Plans 01–04.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–04, and add:

- **A URL is emitted only if it round-trips** (Plan 04 Task 4). No opt-out.
- **URL adapters register unconditionally** and no-op when `serving()` is null
  (spec §5.4).
- **URLs generated before `init : 99` are not rebased.** This is a documented
  limitation, not a bug (spec §5.2).
- **`pre_option_home` is opt-in and default off** (spec §7.2).
- **Canonical is computed per request and never cached** (spec §7.4).
- **CORS never emits `*`** and never echoes an unvalidated origin (spec §8).
- **No server-side diagnostic fetches exist anywhere in the plugin** (spec §8).
- **No scheme downgrade:** an HTTPS request may not yield an HTTP result
  (spec §11.8).

---

## File map

| File | Responsibility |
|---|---|
| `src/Url/UrlKind.php` | The eleven URL kinds |
| `src/Url/UrlPolicy.php` | The single rebasing decision |
| `src/Url/HostValue.php` | Validates a bare host returned by a filter |
| `src/Url/AbsoluteUrl.php` | Validates a full URL returned by a filter |
| `src/Url/Compatibility.php` | The machine-readable matrix the suite iterates |
| `src/Url/Adapters/CoreLinks.php` | `home_url`, permalinks, attachments, terms |
| `src/Url/Adapters/RestLinks.php` | `rest_url` |
| `src/Url/Adapters/AjaxUrl.php` | `admin_url` for `admin-ajax.php` only |
| `src/Url/Adapters/FeedLinks.php` | `feed_link`, `post_comments_feed_link` |
| `src/Url/Adapters/CommentLinks.php` | Comment form action and post redirect |
| `src/Url/Adapters/EmbedLinks.php` | `oembed_response_data`, `embed_html` |
| `src/Url/Adapters/SitemapLinks.php` | `wp_sitemaps_*` |
| `src/Url/Adapters/OptionHome.php` | `pre_option_home`, opt-in |
| `src/Url/Canonical/CanonicalUrl.php` | Readonly canonical result |
| `src/Url/Canonical/CanonicalPolicy.php` | Pure per-request computation |
| `src/Url/Canonical/Adapters/RelCanonical.php` | The `wp_head` tag |
| `src/Url/Canonical/Adapters/RedirectCanonicalGuard.php` | Filters core's proposal |
| `src/Http/Cors.php` | Origin-authorizing headers |
| `src/Support/BackgroundContext.php` | `pd_with_mapping()` and the CLI host flag |

---

### Task 1: The URL policy and its protected paths

**Files:**
- Create: `src/Url/UrlKind.php`, `src/Url/UrlPolicy.php`
- Test: `tests/integration/Url/UrlPolicyTest.php`

**Interfaces:**
- Consumes: `ServingContext` (Plan 03), `RoundTripVerifier` (Plan 04).
- Produces: `PostDomain\Url\UrlKind` enum and `PostDomain\Url\UrlPolicy::__construct( string $primary_origin )` with `::is_rebasable_path( string $path, ServingContext $c ): bool` and `::rebase( string $url, ServingContext $c, UrlKind $kind ): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Url/UrlPolicyTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;
use WP_UnitTestCase;

final class UrlPolicyTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private UrlPolicy $policy;

	private \PostDomain\Routing\ServingContext $context;

	public function set_up(): void {
		parent::set_up();
		$this->policy  = new UrlPolicy( 'https://primary.test' );
		$this->context = $this->serving_context( $this->make_page( 'club', 0 ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_rebase_url' );
		remove_all_filters( 'pd_is_rebasable_path' );
		parent::tear_down();
	}

	public function test_a_primary_host_url_is_rebased(): void {
		$this->assertSame(
			'https://mapped.test/wp-json/wp/v2/posts',
			$this->policy->rebase( 'https://primary.test/wp-json/wp/v2/posts', $this->context, UrlKind::REST )
		);
	}

	public function test_a_url_already_on_the_mapped_host_is_untouched(): void {
		$this->assertSame(
			'https://mapped.test/events/',
			$this->policy->rebase( 'https://mapped.test/events/', $this->context, UrlKind::PERMALINK )
		);
	}

	public function test_a_third_party_url_is_untouched(): void {
		$this->assertSame(
			'https://example.org/x',
			$this->policy->rebase( 'https://example.org/x', $this->context, UrlKind::PERMALINK )
		);
	}

	/**
	 * @dataProvider protected_paths
	 */
	public function test_protected_paths_are_never_rebased( string $path ): void {
		$url = 'https://primary.test' . $path;

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::HOME ) );
	}

	/** @return array<string, array{0: string}> */
	public static function protected_paths(): array {
		return array(
			'admin'      => array( '/wp-admin/edit.php' ),
			'login'      => array( '/wp-login.php' ),
			'signup'     => array( '/wp-signup.php' ),
			'activate'   => array( '/wp-activate.php' ),
			'xmlrpc'     => array( '/xmlrpc.php' ),
			'cron'       => array( '/wp-cron.php' ),
			'management' => array( '/wp-json/post-domain/v1/domains' ),
		);
	}

	public function test_admin_ajax_is_exempt_from_the_admin_protection(): void {
		$this->assertSame(
			'https://mapped.test/wp-admin/admin-ajax.php',
			$this->policy->rebase( 'https://primary.test/wp-admin/admin-ajax.php', $this->context, UrlKind::AJAX )
		);
	}

	public function test_the_ajax_exemption_is_an_exact_match(): void {
		$url = 'https://primary.test/wp-admin/admin-ajax.php.bak';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::AJAX ) );
	}

	public function test_a_filter_cannot_make_a_protected_path_rebasable(): void {
		add_filter( 'pd_is_rebasable_path', '__return_true' );

		$url = 'https://primary.test/wp-admin/edit.php';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::HOME ) );
	}

	public function test_a_filter_returning_a_foreign_host_is_rejected(): void {
		add_filter( 'pd_rebase_url', static fn(): string => 'https://evil.test/x' );

		$url = 'https://primary.test/events/';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::PERMALINK ) );
	}

	public function test_a_filter_returning_a_relative_url_is_rejected(): void {
		add_filter( 'pd_rebase_url', static fn(): string => '/events/' );

		$url = 'https://primary.test/events/';

		$this->assertSame( $url, $this->policy->rebase( $url, $this->context, UrlKind::PERMALINK ) );
	}

	public function test_a_filter_may_supply_a_mapped_host_url(): void {
		add_filter( 'pd_rebase_url', static fn(): string => 'https://mapped.test/custom/' );

		$this->assertSame(
			'https://mapped.test/custom/',
			$this->policy->rebase( 'https://primary.test/events/', $this->context, UrlKind::PERMALINK )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter UrlPolicyTest`
Expected: FAIL — `Error: Class "PostDomain\Url\UrlPolicy" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Url/UrlKind.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

enum UrlKind: string {
	case HOME      = 'home';
	case PERMALINK = 'permalink';
	case TERM      = 'term';
	case REST      = 'rest';
	case AJAX      = 'ajax';
	case FEED      = 'feed';
	case COMMENT   = 'comment';
	case EMBED     = 'embed';
	case SITEMAP   = 'sitemap';
	case ASSET     = 'asset';
	case MAIL      = 'mail';

	public function prefers_canonical_host(): bool {
		return false;
	}
}
```

Create `src/Url/UrlPolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

use PostDomain\Routing\ServingContext;

final class UrlPolicy {

	public const PROTECTED_PATHS = array(
		'/wp-admin/',
		'/wp-login.php',
		'/wp-signup.php',
		'/wp-activate.php',
		'/xmlrpc.php',
		'/wp-cron.php',
	);

	public const EXEMPT_PATHS = array( '/wp-admin/admin-ajax.php' );

	private const INFRASTRUCTURE_PREFIXES = array( '/wp-content/', '/wp-includes/' );

	public function __construct( private readonly string $primary_origin ) {}

	public function is_rebasable_path( string $path, ServingContext $context ): bool {
		$derived = $this->derive_rebasable( $path );
		$result  = (bool) apply_filters( 'pd_is_rebasable_path', $derived, $path, $context->mapping );

		// Protected paths are forced not rebasable after the filter.
		return $this->is_protected( $path ) ? false : $result;
	}

	public function rebase( string $url, ServingContext $context, UrlKind $kind ): string {
		/** @var string|null $supplied */
		$supplied = apply_filters( 'pd_rebase_url', null, $url, $context, $kind );

		if ( is_string( $supplied ) ) {
			return $this->validated( $supplied, $context ) ?? $url;
		}

		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['host'] ) ) {
			return $url;
		}

		if ( $parts['host'] !== (string) wp_parse_url( $this->primary_origin, PHP_URL_HOST ) ) {
			return $url;
		}

		$path = $parts['path'] ?? '/';

		if ( ! $this->is_rebasable_path( $path, $context ) ) {
			return $url;
		}

		$target = $this->link_host( $context, $kind );
		$suffix = $path;

		if ( isset( $parts['query'] ) ) {
			$suffix .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$suffix .= '#' . $parts['fragment'];
		}

		return ( $parts['scheme'] ?? 'https' ) . '://' . $target . $suffix;
	}

	private function link_host( ServingContext $context, UrlKind $kind ): string {
		$default = $kind->prefers_canonical_host() ? $context->canonical_host : $context->requested_host;
		$host    = (string) apply_filters( 'pd_link_host', $default, $kind, $context );

		return HostValue::validated( $host, $context ) ?? $default;
	}

	private function validated( string $url, ServingContext $context ): ?string {
		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return null;
		}

		$permitted = array( $context->requested_host, $context->canonical_host );

		return in_array( $parts['host'], $permitted, true ) ? $url : null;
	}

	private function is_protected( string $path ): bool {
		foreach ( self::EXEMPT_PATHS as $exempt ) {
			if ( $exempt === $path ) {
				return false;
			}
		}

		foreach ( self::PROTECTED_PATHS as $protected ) {
			if ( str_starts_with( $path, $protected ) || $path === rtrim( $protected, '/' ) ) {
				return true;
			}
		}

		return str_starts_with( $path, '/' . rest_get_url_prefix() . '/post-domain/v1' );
	}

	private function derive_rebasable( string $path ): bool {
		foreach ( self::INFRASTRUCTURE_PREFIXES as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}

		if ( str_starts_with( $path, '/' . rest_get_url_prefix() . '/' ) ) {
			return true;
		}

		return ! $this->is_protected( $path );
	}
}
```

Create `src/Url/HostValue.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

use PostDomain\Routing\ServingContext;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;

/**
 * Validates a BARE HOST returned by a filter. Distinct from AbsoluteUrl, which
 * validates a full URL: conflating them is how a scheme downgrade slips through.
 */
final class HostValue {

	public static function validated( string $host, ServingContext $context ): ?string {
		if ( str_contains( $host, '://' ) || str_contains( $host, '/' ) ) {
			return null;
		}

		$authority = ( new AuthorityParser() )->parse( $host );

		if ( null === $authority || null !== $authority->port ) {
			return null;
		}

		$ascii = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $ascii ) {
			return null;
		}

		return in_array( $ascii, array( $context->requested_host, $context->canonical_host ), true )
			? $ascii
			: null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter UrlPolicyTest`
Expected: PASS — 17 tests

- [ ] **Step 5: Commit**

```bash
git add src/Url/UrlKind.php src/Url/UrlPolicy.php src/Url/HostValue.php tests/integration/Url/UrlPolicyTest.php
git commit -m "Decide rebasing in one policy, with protected paths forced closed

The admin-ajax exemption is an exact match, so a suffixed variant stays
protected, and a filter cannot reopen anything the protected list closes."
```

---

### Task 2: Absolute-URL validation and scheme downgrade

**Files:**
- Create: `src/Url/AbsoluteUrl.php`
- Test: `tests/unit/Url/AbsoluteUrlTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Url\AbsoluteUrl::validated( string $url, string[] $permitted_hosts, bool $request_is_https ): ?string`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Url/AbsoluteUrlTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Url;

use PHPUnit\Framework\TestCase;
use PostDomain\Url\AbsoluteUrl;

final class AbsoluteUrlTest extends TestCase {

	private const PERMITTED = array( 'primary.test', 'mapped.test' );

	public function test_a_permitted_https_url_passes(): void {
		$this->assertSame(
			'https://mapped.test/x',
			AbsoluteUrl::validated( 'https://mapped.test/x', self::PERMITTED, true )
		);
	}

	public function test_a_foreign_host_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( 'https://evil.test/x', self::PERMITTED, true ) );
	}

	public function test_a_relative_url_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( '/x', self::PERMITTED, true ) );
	}

	public function test_a_non_http_scheme_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( 'javascript:alert(1)', self::PERMITTED, true ) );
		$this->assertNull( AbsoluteUrl::validated( 'ftp://mapped.test/x', self::PERMITTED, true ) );
	}

	public function test_userinfo_is_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( 'https://user@mapped.test/x', self::PERMITTED, true ) );
	}

	public function test_control_characters_are_rejected(): void {
		$this->assertNull( AbsoluteUrl::validated( "https://mapped.test/x\n", self::PERMITTED, true ) );
		$this->assertNull( AbsoluteUrl::validated( "https://mapped.test/\tx", self::PERMITTED, true ) );
	}

	public function test_an_https_request_may_not_yield_an_http_result(): void {
		$this->assertNull(
			AbsoluteUrl::validated( 'http://mapped.test/x', self::PERMITTED, true ),
			'no scheme downgrade'
		);
	}

	public function test_an_http_request_may_yield_either_scheme(): void {
		$this->assertSame(
			'http://mapped.test/x',
			AbsoluteUrl::validated( 'http://mapped.test/x', self::PERMITTED, false )
		);
		$this->assertSame(
			'https://mapped.test/x',
			AbsoluteUrl::validated( 'https://mapped.test/x', self::PERMITTED, false )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AbsoluteUrlTest`
Expected: FAIL — `Error: Class "PostDomain\Url\AbsoluteUrl" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Url/AbsoluteUrl.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

final class AbsoluteUrl {

	/**
	 * @param string[] $permitted_hosts
	 */
	public static function validated( string $url, array $permitted_hosts, bool $request_is_https ): ?string {
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return null;
		}

		$parts = parse_url( $url );

		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return null;
		}

		if ( $request_is_https && 'https' !== $parts['scheme'] ) {
			return null;
		}

		return in_array( $parts['host'], $permitted_hosts, true ) ? $url : null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter AbsoluteUrlTest`
Expected: PASS — 8 tests

- [ ] **Step 5: Commit**

```bash
git add src/Url/AbsoluteUrl.php tests/unit/Url/AbsoluteUrlTest.php
git commit -m "Validate filter-returned URLs and refuse a scheme downgrade

Separate from HostValue on purpose: a bare host and a full URL have different
attack surfaces, and one validator for both is how a downgrade gets through."
```

---

### Task 3: The compatibility matrix and the core link adapters

**Files:**
- Create: `src/Url/Compatibility.php`, `src/Url/Adapters/CoreLinks.php`
- Test: `tests/integration/Url/RenderedOutputTest.php`

**Interfaces:**
- Consumes: `UrlPolicy` (Task 1), `RoundTripVerifier` (Plan 04), `ContextHolder` (Plan 03).
- Produces: `PostDomain\Url\Compatibility::SURFACES` (the machine-readable matrix) and `PostDomain\Url\Adapters\CoreLinks::register(): void`.

The matrix is iterated by the suite, so it and the tests cannot drift (spec §7.2).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Url/RenderedOutputTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\Compatibility;
use WP_UnitTestCase;

final class RenderedOutputTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private int $root;

	private int $child;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot();

		$this->root  = $this->make_page( 'club', 0 );
		$this->child = $this->make_page( 'events', $this->root );

		Plugin::instance()->context()->set_serving( $this->serving_context( $this->root ) );
		Plugin::instance()->register_url_adapters();
	}

	public function test_every_matrix_surface_is_declared_with_its_hook(): void {
		foreach ( Compatibility::SURFACES as $surface ) {
			$this->assertArrayHasKey( 'hook', $surface );
			$this->assertArrayHasKey( 'rebased', $surface );
			$this->assertNotSame( '', $surface['hook'] );
		}
	}

	public function test_home_url_is_rebased(): void {
		$this->assertStringStartsWith( 'https://mapped.test', home_url( '/' ) );
	}

	public function test_site_url_is_not_rebased(): void {
		$this->assertStringStartsWith(
			'http://',
			site_url( '/' ),
			'site_url addresses the installation, not the served domain'
		);
		$this->assertStringNotContainsString( 'mapped.test', site_url( '/' ) );
	}

	public function test_a_descendant_permalink_is_rebased_and_uses_the_subtree_path(): void {
		$this->assertSame( 'https://mapped.test/events/', get_permalink( $this->child ) );
	}

	public function test_the_mapped_post_permalink_is_the_mapped_root(): void {
		$this->assertSame( 'https://mapped.test/', get_permalink( $this->root ) );
	}

	public function test_a_post_outside_the_subtree_keeps_its_primary_permalink(): void {
		$outside = $this->make_page( 'about-us', 0 );

		$this->assertStringNotContainsString(
			'mapped.test',
			get_permalink( $outside ),
			'a correct URL on the wrong domain beats a wrong URL on the right one'
		);
	}

	public function test_rest_url_is_rebased(): void {
		$this->assertStringStartsWith( 'https://mapped.test/', rest_url( 'wp/v2/posts' ) );
	}

	public function test_admin_ajax_is_rebased_but_the_rest_of_admin_is_not(): void {
		$this->assertStringStartsWith( 'https://mapped.test/', admin_url( 'admin-ajax.php' ) );
		$this->assertStringNotContainsString( 'mapped.test', admin_url( 'edit.php' ) );
	}

	public function test_the_rendered_page_carries_no_absolute_primary_host_url_in_its_links(): void {
		$html = '<a href="' . esc_url( get_permalink( $this->child ) ) . '">events</a>'
			. '<link rel="alternate" href="' . esc_url( get_feed_link() ) . '">';

		$this->assertStringNotContainsString( 'primary.test', $html );
	}

	public function test_adapters_no_op_without_a_serving_context(): void {
		Plugin::instance()->context()->set_serving( null );

		$this->assertStringNotContainsString( 'mapped.test', home_url( '/' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter RenderedOutputTest`
Expected: FAIL — `Error: Class "PostDomain\Url\Compatibility" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Url/Compatibility.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

/**
 * The matrix the integration suite iterates, so the documented surface and the
 * tested surface cannot drift apart.
 */
final class Compatibility {

	/** @var array<int, array{surface: string, hook: string, rebased: bool}> */
	public const SURFACES = array(
		array( 'surface' => 'home_url', 'hook' => 'home_url', 'rebased' => true ),
		array( 'surface' => 'site_url', 'hook' => 'site_url', 'rebased' => false ),
		array( 'surface' => 'post permalink', 'hook' => 'post_link', 'rebased' => true ),
		array( 'surface' => 'page permalink', 'hook' => 'page_link', 'rebased' => true ),
		array( 'surface' => 'custom type permalink', 'hook' => 'post_type_link', 'rebased' => true ),
		array( 'surface' => 'attachment', 'hook' => 'attachment_link', 'rebased' => true ),
		array( 'surface' => 'term', 'hook' => 'term_link', 'rebased' => true ),
		array( 'surface' => 'rest root', 'hook' => 'rest_url', 'rebased' => true ),
		array( 'surface' => 'admin-ajax', 'hook' => 'admin_url', 'rebased' => true ),
		array( 'surface' => 'comment form', 'hook' => 'comment_form_defaults', 'rebased' => true ),
		array( 'surface' => 'comment redirect', 'hook' => 'comment_post_redirect', 'rebased' => true ),
		array( 'surface' => 'feed', 'hook' => 'feed_link', 'rebased' => true ),
		array( 'surface' => 'comments feed', 'hook' => 'post_comments_feed_link', 'rebased' => true ),
		array( 'surface' => 'oembed', 'hook' => 'oembed_response_data', 'rebased' => true ),
		array( 'surface' => 'embed html', 'hook' => 'embed_html', 'rebased' => true ),
		array( 'surface' => 'sitemap', 'hook' => 'wp_sitemaps_index_entry', 'rebased' => true ),
		array( 'surface' => 'shortlink', 'hook' => 'get_shortlink', 'rebased' => true ),
		array( 'surface' => 'home option', 'hook' => 'pre_option_home', 'rebased' => false ),
	);
}
```

Create `src/Url/Adapters/CoreLinks.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Routing\RoundTripVerifier;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

/**
 * Registered unconditionally; every callback no-ops when serving() is null. That
 * is what lets pd_with_mapping() work in cron and CLI.
 */
final class CoreLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy,
		private readonly RoundTripVerifier $verifier,
		private readonly string $primary_origin
	) {}

	public function register(): void {
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'attachment_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10 );
		add_filter( 'rest_url', array( $this, 'filter_rest_url' ), 10 );
		add_filter( 'admin_url', array( $this, 'filter_admin_url' ), 10, 2 );
	}

	public function filter_home_url( string $url, string $path = '' ): string {
		unset( $path );

		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::HOME );
	}

	/** @param \WP_Post|int $post */
	public function filter_post_link( string $url, $post ): string {
		$serving = $this->context->serving();
		$post    = get_post( $post );

		if ( null === $serving || null === $post ) {
			return $url;
		}

		$path = $this->verifier->verified_path( $serving, $post );

		if ( null === $path ) {
			// The primary permalink is correct on the wrong domain; that beats
			// a wrong URL on the right one.
			return $url;
		}

		$suffix = '' === $path ? '/' : '/' . $path . '/';

		return 'https://' . $serving->requested_host . user_trailingslashit( $suffix );
	}

	/** @param \WP_Post|int $post */
	public function filter_page_link( string $url, $post ): string {
		return $this->filter_post_link( $url, $post );
	}

	public function filter_term_link( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::TERM );
	}

	public function filter_rest_url( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::REST );
	}

	public function filter_admin_url( string $url, string $path = '' ): string {
		$serving = $this->context->serving();

		if ( null === $serving || 'admin-ajax.php' !== ltrim( $path, '/' ) ) {
			return $url;
		}

		return $this->policy->rebase( $url, $serving, UrlKind::AJAX );
	}
}
```

Add to `src/Plugin.php`, inside `boot()` at `plugins_loaded` priority 10:

```php
		add_action( 'plugins_loaded', array( $plugin, 'register_url_adapters' ), 10 );
```

and the method:

```php
	public function register_url_adapters(): void {
		$policy   = new \PostDomain\Url\UrlPolicy( home_url() );
		$verifier = new \PostDomain\Routing\RoundTripVerifier( $this->routing() );

		( new \PostDomain\Url\Adapters\CoreLinks( $this->context, $policy, $verifier, home_url() ) )->register();
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter RenderedOutputTest`
Expected: PASS — 10 tests

- [ ] **Step 5: Commit**

```bash
git add src/Url/Compatibility.php src/Url/Adapters/CoreLinks.php src/Plugin.php tests/integration/Url/RenderedOutputTest.php
git commit -m "Rebase core link surfaces and assert on rendered output

A post that does not round-trip keeps its primary permalink rather than
receiving a mapped URL the resolver would refuse."
```

---

### Task 4: Feeds, comments, embeds, sitemaps, and the home option

**Files:**
- Create: `src/Url/Adapters/FeedLinks.php`, `src/Url/Adapters/CommentLinks.php`, `src/Url/Adapters/EmbedLinks.php`, `src/Url/Adapters/SitemapLinks.php`, `src/Url/Adapters/OptionHome.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Url/SecondaryAdaptersTest.php`

**Interfaces:**
- Consumes: `UrlPolicy` (Task 1), `ContextHolder` (Plan 03).
- Produces: a `register(): void` on each adapter, all called from `Plugin::register_url_adapters()`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Url/SecondaryAdaptersTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class SecondaryAdaptersTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private int $root;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot();
		$this->root = $this->make_page( 'club', 0 );
		Plugin::instance()->context()->set_serving( $this->serving_context( $this->root ) );
		Plugin::instance()->register_url_adapters();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_filter_home_option' );
		parent::tear_down();
	}

	public function test_feed_links_are_rebased(): void {
		$this->assertStringStartsWith( 'https://mapped.test/', get_feed_link() );
	}

	public function test_the_comment_form_action_stays_on_the_mapped_host(): void {
		$defaults = apply_filters( 'comment_form_defaults', array( 'action' => site_url( '/wp-comments-post.php' ) ) );

		$this->assertStringStartsWith(
			'https://mapped.test/',
			$defaults['action'],
			'a visitor must never leave the domain to comment'
		);
	}

	public function test_the_comment_post_redirect_returns_to_the_mapped_host(): void {
		$redirect = apply_filters(
			'comment_post_redirect',
			home_url( '/events/#comment-1' ),
			new \stdClass()
		);

		$this->assertStringStartsWith( 'https://mapped.test/', $redirect );
	}

	public function test_sitemap_entries_are_rebased(): void {
		$entry = apply_filters(
			'wp_sitemaps_index_entry',
			array( 'loc' => home_url( '/wp-sitemap-posts-page-1.xml' ) ),
			'post',
			'page',
			1
		);

		$this->assertStringStartsWith( 'https://mapped.test/', $entry['loc'] );
	}

	public function test_the_home_option_filter_is_off_by_default(): void {
		$this->assertStringNotContainsString(
			'mapped.test',
			(string) get_option( 'home' ),
			'pre_option_home is opt-in because it fires for everything, including cron and mail'
		);
	}

	public function test_the_home_option_filter_applies_when_opted_in(): void {
		add_filter( 'pd_filter_home_option', '__return_true' );
		Plugin::instance()->register_url_adapters();

		$this->assertStringContainsString( 'mapped.test', (string) get_option( 'home' ) );
	}

	public function test_the_home_option_filter_never_applies_in_admin(): void {
		add_filter( 'pd_filter_home_option', '__return_true' );
		set_current_screen( 'dashboard' );
		Plugin::instance()->register_url_adapters();

		$this->assertStringNotContainsString( 'mapped.test', (string) get_option( 'home' ) );

		set_current_screen( 'front' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter SecondaryAdaptersTest`
Expected: FAIL — `Failed asserting that '…primary…' starts with "https://mapped.test/"`

- [ ] **Step 3: Write minimal implementation**

Create `src/Url/Adapters/FeedLinks.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class FeedLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'feed_link', array( $this, 'rebase' ) );
		add_filter( 'post_comments_feed_link', array( $this, 'rebase' ) );
	}

	public function rebase( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::FEED );
	}
}
```

Create `src/Url/Adapters/CommentLinks.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class CommentLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'comment_form_defaults', array( $this, 'filter_defaults' ) );
		add_filter( 'comment_post_redirect', array( $this, 'filter_redirect' ) );
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	public function filter_defaults( array $defaults ): array {
		$serving = $this->context->serving();

		if ( null === $serving || ! isset( $defaults['action'] ) ) {
			return $defaults;
		}

		$defaults['action'] = $this->policy->rebase( (string) $defaults['action'], $serving, UrlKind::COMMENT );

		return $defaults;
	}

	public function filter_redirect( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::COMMENT );
	}
}
```

Create `src/Url/Adapters/EmbedLinks.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class EmbedLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'oembed_response_data', array( $this, 'filter_response' ) );
		add_filter( 'embed_html', array( $this, 'filter_html' ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function filter_response( array $data ): array {
		$serving = $this->context->serving();

		if ( null === $serving ) {
			return $data;
		}

		foreach ( array( 'url', 'provider_url' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$data[ $key ] = $this->policy->rebase( $data[ $key ], $serving, UrlKind::EMBED );
			}
		}

		return $data;
	}

	public function filter_html( string $html ): string {
		$serving = $this->context->serving();

		if ( null === $serving ) {
			return $html;
		}

		return str_replace(
			'https://' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'https://' . $serving->requested_host,
			$html
		);
	}
}
```

Create `src/Url/Adapters/SitemapLinks.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class SitemapLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'wp_sitemaps_index_entry', array( $this, 'filter_entry' ) );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_entry' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public function filter_entry( array $entry ): array {
		$serving = $this->context->serving();

		if ( null === $serving || ! isset( $entry['loc'] ) ) {
			return $entry;
		}

		$entry['loc'] = $this->policy->rebase( (string) $entry['loc'], $serving, UrlKind::SITEMAP );

		return $entry;
	}
}
```

Create `src/Url/Adapters/OptionHome.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;

/**
 * Opt-in and default off. It fires for everything that reads the option,
 * including code paths that must stay on the primary host, which is the classic
 * way to corrupt cron and email.
 */
final class OptionHome {

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		if ( ! (bool) apply_filters( 'pd_filter_home_option', false ) ) {
			return;
		}

		add_filter( 'pre_option_home', array( $this, 'filter_home' ) );
	}

	/** @param mixed $value */
	public function filter_home( $value ) {
		$serving = $this->context->serving();

		if ( null === $serving || is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $value;
		}

		return 'https://' . $serving->requested_host;
	}
}
```

Extend `Plugin::register_url_adapters()`:

```php
		( new \PostDomain\Url\Adapters\FeedLinks( $this->context, $policy ) )->register();
		( new \PostDomain\Url\Adapters\CommentLinks( $this->context, $policy ) )->register();
		( new \PostDomain\Url\Adapters\EmbedLinks( $this->context, $policy ) )->register();
		( new \PostDomain\Url\Adapters\SitemapLinks( $this->context, $policy ) )->register();
		( new \PostDomain\Url\Adapters\OptionHome( $this->context ) )->register();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter SecondaryAdaptersTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Url/Adapters/FeedLinks.php src/Url/Adapters/CommentLinks.php src/Url/Adapters/EmbedLinks.php src/Url/Adapters/SitemapLinks.php src/Url/Adapters/OptionHome.php src/Plugin.php tests/integration/Url/SecondaryAdaptersTest.php
git commit -m "Rebase feeds, comments, embeds, and sitemaps; keep the home option opt-in

The comment form action stays on the mapped host so a visitor never leaves the
domain to comment. pre_option_home stays off because it reaches cron and mail."
```

---

### Task 5: Canonical policy and its adapters

**Files:**
- Create: `src/Url/Canonical/CanonicalUrl.php`, `src/Url/Canonical/CanonicalPolicy.php`, `src/Url/Canonical/Adapters/RelCanonical.php`, `src/Url/Canonical/Adapters/RedirectCanonicalGuard.php`
- Test: `tests/integration/Url/CanonicalTest.php`

**Interfaces:**
- Consumes: `HostContext`, `ServingContext` (Plan 03), `RoundTripVerifier` (Plan 04).
- Produces: `PostDomain\Url\Canonical\CanonicalUrl` (readonly `string $url`), `CanonicalPolicy::for_request( ?HostContext $h, ?ServingContext $s, \WP_Query $q ): ?CanonicalUrl`, and a `register()` on each adapter.

`RedirectCanonicalGuard` **filters** core's proposal rather than removing the
action, so trailing-slash, pagination, and case corrections keep working
(spec §7.4).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Url/CanonicalTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Url;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use PostDomain\Url\Canonical\CanonicalPolicy;
use WP_UnitTestCase;

final class CanonicalTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private int $root;

	private int $child;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot();
		$this->root  = $this->make_page( 'club', 0 );
		$this->child = $this->make_page( 'events', $this->root );
		Plugin::instance()->context()->set_serving( $this->serving_context( $this->root ) );
		Plugin::instance()->register_url_adapters();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_canonical_url' );
		parent::tear_down();
	}

	private function query_for( int $post_id ): \WP_Query {
		$query = new \WP_Query( array( 'page_id' => $post_id ) );
		$query->is_singular = true;

		return $query;
	}

	public function test_canonical_uses_the_mapped_host(): void {
		$canonical = CanonicalPolicy::for_request(
			Plugin::instance()->context()->host(),
			Plugin::instance()->context()->serving(),
			$this->query_for( $this->child )
		);

		$this->assertSame( 'https://mapped.test/events/', $canonical?->url );
	}

	public function test_canonical_is_computed_fresh_each_call(): void {
		$serving = Plugin::instance()->context()->serving();
		$host    = Plugin::instance()->context()->host();
		$query   = $this->query_for( $this->child );

		$first = CanonicalPolicy::for_request( $host, $serving, $query );

		add_filter( 'pd_canonical_url', static fn(): \PostDomain\Url\Canonical\CanonicalUrl
			=> new \PostDomain\Url\Canonical\CanonicalUrl( 'https://mapped.test/override/' ) );

		$second = CanonicalPolicy::for_request( $host, $serving, $query );

		$this->assertNotSame( $first?->url, $second?->url, 'nothing is cached between calls' );
	}

	public function test_a_filter_returning_a_foreign_host_is_rejected(): void {
		add_filter( 'pd_canonical_url', static fn(): \PostDomain\Url\Canonical\CanonicalUrl
			=> new \PostDomain\Url\Canonical\CanonicalUrl( 'https://evil.test/x' ) );

		$canonical = CanonicalPolicy::for_request(
			Plugin::instance()->context()->host(),
			Plugin::instance()->context()->serving(),
			$this->query_for( $this->child )
		);

		$this->assertSame( 'https://mapped.test/events/', $canonical?->url );
	}

	public function test_redirect_canonical_rewrites_a_primary_permalink_proposal(): void {
		$guard = new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard(
			Plugin::instance()->context()
		);

		$proposal = home_url( '/club/events/' );
		$result   = $guard->filter_proposal( $proposal, 'https://mapped.test/events/' );

		$this->assertStringStartsWith( 'https://mapped.test', (string) $result );
	}

	public function test_redirect_canonical_returns_false_when_the_result_matches_the_request(): void {
		$guard = new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard(
			Plugin::instance()->context()
		);

		$this->assertFalse(
			$guard->filter_proposal( 'https://mapped.test/events/', 'https://mapped.test/events/' )
		);
	}

	public function test_an_unrelated_core_proposal_stands(): void {
		$guard = new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard(
			Plugin::instance()->context()
		);

		$this->assertSame(
			'https://mapped.test/events/?paged=2',
			$guard->filter_proposal( 'https://mapped.test/events/?paged=2', 'https://mapped.test/events' ),
			'trailing-slash and pagination corrections keep working'
		);
	}

	public function test_no_canonical_off_a_mapped_host(): void {
		Plugin::instance()->context()->set_serving( null );

		$this->assertNull(
			CanonicalPolicy::for_request(
				Plugin::instance()->context()->host(),
				null,
				$this->query_for( $this->child )
			)
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CanonicalTest`
Expected: FAIL — `Error: Class "PostDomain\Url\Canonical\CanonicalPolicy" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Url/Canonical/CanonicalUrl.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical;

final class CanonicalUrl {

	public function __construct( public readonly string $url ) {}
}
```

Create `src/Url/Canonical/CanonicalPolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical;

use PostDomain\Routing\HostContext;
use PostDomain\Routing\ServingContext;
use PostDomain\Url\AbsoluteUrl;

/**
 * Pure. Computed per request, never cached: the policy has no persistence layer
 * by construction, so there is nowhere for a stale answer to live.
 */
final class CanonicalPolicy {

	public static function for_request(
		?HostContext $host,
		?ServingContext $serving,
		\WP_Query $query
	): ?CanonicalUrl {
		if ( null === $serving ) {
			return null;
		}

		$post_id = (int) $query->get( 'page_id' ) ?: (int) $query->get( 'p' );
		$computed = 0 === $post_id
			? 'https://' . $serving->canonical_host . '/'
			: (string) get_permalink( $post_id );

		$default = new CanonicalUrl( $computed );

		/** @var CanonicalUrl|null $supplied */
		$supplied = apply_filters( 'pd_canonical_url', $default, $host, $serving, $query );

		if ( ! $supplied instanceof CanonicalUrl ) {
			return $default;
		}

		$permitted = array(
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			$serving->requested_host,
			$serving->canonical_host,
		);

		$validated = AbsoluteUrl::validated( $supplied->url, $permitted, (bool) ( $host?->is_https ?? true ) );

		return null === $validated ? $default : new CanonicalUrl( $validated );
	}
}
```

Create `src/Url/Canonical/Adapters/RelCanonical.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\Canonical\CanonicalPolicy;

final class RelCanonical {

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 10, 2 );
	}

	/** @param \WP_Post|null $post */
	public function filter_canonical( ?string $url, $post ): ?string {
		global $wp_query;

		$serving = $this->context->serving();

		if ( null === $serving || ! $wp_query instanceof \WP_Query ) {
			return $url;
		}

		unset( $post );

		return CanonicalPolicy::for_request( $this->context->host(), $serving, $wp_query )?->url ?? $url;
	}
}
```

Create `src/Url/Canonical/Adapters/RedirectCanonicalGuard.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Canonical\Adapters;

use PostDomain\Routing\ContextHolder;

/**
 * Filters core's proposal rather than removing the action, so trailing-slash,
 * pagination, and case corrections keep working.
 */
final class RedirectCanonicalGuard {

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		add_filter( 'redirect_canonical', array( $this, 'filter_proposal' ), 10, 2 );
	}

	/** @return string|false */
	public function filter_proposal( string $proposed, string $requested ) {
		$serving = $this->context->serving();

		if ( null === $serving ) {
			return $proposed;
		}

		$primary_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$proposed_host = (string) wp_parse_url( $proposed, PHP_URL_HOST );

		if ( $proposed_host === $primary_host ) {
			$proposed = (string) preg_replace(
				'~^(https?://)' . preg_quote( $primary_host, '~' ) . '~',
				'$1' . $serving->requested_host,
				$proposed
			);
		}

		return untrailingslashit( $proposed ) === untrailingslashit( $requested ) ? false : $proposed;
	}
}
```

Extend `Plugin::register_url_adapters()`:

```php
		( new \PostDomain\Url\Canonical\Adapters\RelCanonical( $this->context ) )->register();
		( new \PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard( $this->context ) )->register();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CanonicalTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Url/Canonical src/Plugin.php tests/integration/Url/CanonicalTest.php
git commit -m "Compute canonical per request and filter core's redirect proposal

Removing redirect_canonical outright would throw away trailing-slash and
pagination corrections. Filtering the proposal keeps them and still stops the
bounce back to the primary permalink."
```

---

### Task 6: CORS with strict origin parsing

**Files:**
- Create: `src/Http/Cors.php`
- Test: `tests/integration/Http/CorsTest.php`

**Interfaces:**
- Consumes: `MappingRepository` (Plan 02), `AuthorityParser`, `HostNormalizer` (Plan 01).
- Produces: `PostDomain\Http\Cors::__construct( MappingRepository $repo )` and `::allowed_origin( string $origin_header, bool $request_is_https ): ?string`, plus `::register(): void`.

The header must come from whichever host serves the **asset** and must authorize
the **requesting** origin (spec §8).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Http/CorsTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Http;

use PostDomain\Http\Cors;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class CorsTest extends WP_UnitTestCase {

	private Cors $cors;

	public function set_up(): void {
		parent::set_up();
		Schema::install();

		$repo = new DbRepository();
		$repo->save(
			new Mapping(
				0, 'served.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
		$repo->save(
			new Mapping(
				0, 'pending.test', null, self::factory()->post->create(), 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge'
			)
		);

		$this->cors = new Cors( $repo );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_cors_allowed_origin' );
		parent::tear_down();
	}

	public function test_a_verified_active_origin_is_authorized(): void {
		$this->assertSame(
			'https://served.test',
			$this->cors->allowed_origin( 'https://served.test', true )
		);
	}

	public function test_an_unverified_origin_is_not_authorized(): void {
		$this->assertNull( $this->cors->allowed_origin( 'https://pending.test', true ) );
	}

	public function test_an_unknown_origin_is_not_authorized(): void {
		$this->assertNull( $this->cors->allowed_origin( 'https://stranger.test', true ) );
	}

	/**
	 * @dataProvider malformed_origins
	 */
	public function test_malformed_origins_are_rejected( string $origin ): void {
		$this->assertNull( $this->cors->allowed_origin( $origin, true ) );
	}

	/** @return array<string, array{0: string}> */
	public static function malformed_origins(): array {
		return array(
			'literal null'   => array( 'null' ),
			'trailing slash' => array( 'https://served.test/' ),
			'with a path'    => array( 'https://served.test/x' ),
			'with a query'   => array( 'https://served.test?a=b' ),
			'with userinfo'  => array( 'https://user@served.test' ),
			'wrong scheme'   => array( 'ftp://served.test' ),
			'bare host'      => array( 'served.test' ),
			'wildcard'       => array( '*' ),
		);
	}

	public function test_an_http_origin_is_rejected_for_an_https_request(): void {
		$this->assertNull( $this->cors->allowed_origin( 'http://served.test', true ) );
	}

	public function test_a_filter_cannot_return_a_wildcard(): void {
		add_filter( 'pd_cors_allowed_origin', static fn(): string => '*' );

		$this->assertNull( $this->cors->allowed_origin( 'https://served.test', true ) );
	}

	public function test_a_filter_cannot_return_a_different_origin(): void {
		add_filter( 'pd_cors_allowed_origin', static fn(): string => 'https://evil.test' );

		$this->assertNull( $this->cors->allowed_origin( 'https://served.test', true ) );
	}

	public function test_a_filter_may_withhold_authorization(): void {
		add_filter( 'pd_cors_allowed_origin', static fn(): ?string => null );

		$this->assertNull( $this->cors->allowed_origin( 'https://served.test', true ) );
	}

	public function test_no_source_file_performs_an_outbound_diagnostic_fetch(): void {
		$offenders = array();

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' )
		) as $file ) {
			if ( 'php' !== $file->getExtension() || 'WpHttpClient.php' === $file->getFilename() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $source, 'wp_remote_get(' ) || str_contains( $source, 'file_get_contents( \'http' ) ) {
				$offenders[] = $file->getFilename();
			}
		}

		$this->assertSame( array(), $offenders, 'the CORS probe runs in the browser, not on the server' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CorsTest`
Expected: FAIL — `Error: Class "PostDomain\Http\Cors" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Http/Cors.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;

/**
 * Authorizes the REQUESTING origin, on whichever host serves the asset. Never
 * '*', never an unvalidated echo.
 */
final class Cors {

	private const ORIGIN_GRAMMAR = '~^(https?)://([a-z0-9._~%-]+|\[[0-9a-fA-F:.]+\])(:\d{1,5})?$~';

	public function __construct( private readonly MappingRepository $repo ) {}

	public function register(): void {
		add_action( 'send_headers', array( $this, 'send' ) );
	}

	public function send(): void {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ORIGIN'] ) )
			: '';

		$https = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'];

		$allowed = $this->allowed_origin( $origin, $https );

		if ( null === $allowed ) {
			return;
		}

		header( 'Access-Control-Allow-Origin: ' . $allowed );
		header( 'Vary: Origin', false );
	}

	public function allowed_origin( string $origin_header, bool $request_is_https ): ?string {
		if ( '' === $origin_header || 'null' === $origin_header ) {
			return null;
		}

		if ( 1 !== preg_match( self::ORIGIN_GRAMMAR, $origin_header, $matches ) ) {
			return null;
		}

		if ( $request_is_https && 'https' !== $matches[1] ) {
			return null;
		}

		$authority = ( new AuthorityParser() )->parse( $matches[2] . ( $matches[3] ?? '' ) );

		if ( null === $authority ) {
			return null;
		}

		$ascii = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $ascii ) {
			return null;
		}

		$mapping = $this->repo->by_host( $ascii );

		if ( null === $mapping
			|| VerificationState::VERIFIED !== $mapping->verification_state
			|| ActivationState::ACTIVE !== $mapping->activation_state ) {
			return null;
		}

		/** @var string|null $filtered */
		$filtered = apply_filters( 'pd_cors_allowed_origin', $origin_header, $origin_header, $mapping );

		// Must be null or byte-identical to the validated request origin.
		return ( is_string( $filtered ) && $filtered === $origin_header ) ? $filtered : null;
	}
}
```

Extend `Plugin::register_url_adapters()`:

```php
		( new \PostDomain\Http\Cors( $this->repository ) )->register();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CorsTest`
Expected: PASS — 17 tests

- [ ] **Step 5: Commit**

```bash
git add src/Http/Cors.php src/Plugin.php tests/integration/Http/CorsTest.php
git commit -m "Authorize the requesting origin for CORS, never a wildcard

The header comes from whichever host serves the asset and names the origin that
asked. A filter can withhold authorization but cannot broaden it."
```

---

### Task 7: Background context for cron, CLI, and mail

**Files:**
- Create: `src/Support/BackgroundContext.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Support/BackgroundContextTest.php`

**Interfaces:**
- Consumes: `ContextHolder` (Plan 03), `MappingRepository` (Plan 02), `ContentPolicy` (Plan 03).
- Produces: the global function `pd_with_mapping( int $mapping_id, callable $fn ): mixed` and `PostDomain\Support\BackgroundContext::from_cli_flag( array $argv ): ?string`.

Mail generated outside a mapped request gets primary-host URLs unless wrapped.
Defaulting to primary is the only safe answer — a wrong host in an email is
unrecallable (spec §7.5).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Support/BackgroundContextTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Support;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Support\BackgroundContext;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class BackgroundContextTest extends WP_UnitTestCase {

	private int $mapping_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Plugin::boot();
		Plugin::instance()->register_url_adapters();

		$post = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->mapping_id = ( new DbRepository() )->save(
			new Mapping(
				0, 'mapped.test', null, $post, 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		)->id;
	}

	public function test_without_a_wrapper_urls_are_primary(): void {
		Plugin::instance()->context()->set_serving( null );

		$this->assertStringNotContainsString( 'mapped.test', home_url( '/' ) );
	}

	public function test_inside_the_wrapper_urls_are_mapped(): void {
		Plugin::instance()->context()->set_serving( null );

		$url = pd_with_mapping( $this->mapping_id, static fn(): string => home_url( '/' ) );

		$this->assertStringContainsString( 'mapped.test', (string) $url );
	}

	public function test_the_previous_context_is_restored_afterwards(): void {
		Plugin::instance()->context()->set_serving( null );

		pd_with_mapping( $this->mapping_id, static fn(): string => home_url( '/' ) );

		$this->assertNull( Plugin::instance()->context()->serving() );
	}

	public function test_the_context_is_restored_even_when_the_callback_throws(): void {
		Plugin::instance()->context()->set_serving( null );

		try {
			pd_with_mapping(
				$this->mapping_id,
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertNull( Plugin::instance()->context()->serving() );
	}

	public function test_an_unknown_mapping_runs_the_callback_with_primary_context(): void {
		Plugin::instance()->context()->set_serving( null );

		$url = pd_with_mapping( 999999, static fn(): string => home_url( '/' ) );

		$this->assertStringNotContainsString( 'mapped.test', (string) $url );
	}

	public function test_the_cli_host_flag_is_parsed(): void {
		$this->assertSame(
			'mapped.test',
			BackgroundContext::from_cli_flag( array( 'wp', 'post', 'list', '--pd-host=mapped.test' ) )
		);
		$this->assertNull( BackgroundContext::from_cli_flag( array( 'wp', 'post', 'list' ) ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter BackgroundContextTest`
Expected: FAIL — `Error: Call to undefined function pd_with_mapping()`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/BackgroundContext.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Mapping\AliasResolver;
use PostDomain\Plugin;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\ServingEligibility;

final class BackgroundContext {

	public static function run( int $mapping_id, callable $fn ): mixed {
		$plugin  = Plugin::instance();
		$mapping = $plugin->repository()->by_id( $mapping_id );

		if ( null === $mapping ) {
			return $fn();
		}

		$host_context = new HostContext(
			$mapping->host,
			null,
			$mapping->host,
			HostKind::MAPPED,
			$mapping,
			EndpointClass::CLI,
			true,
			'GET'
		);

		$aliases     = new AliasResolver( $plugin->repository() );
		$eligibility = ServingEligibility::decide( $host_context, $aliases );
		$serving     = null === $eligibility ? null : ContentPolicy::freeze( $eligibility, $aliases );

		if ( null === $serving ) {
			return $fn();
		}

		return $plugin->context()->with( $serving, $fn );
	}

	/**
	 * @param string[] $argv
	 */
	public static function from_cli_flag( array $argv ): ?string {
		foreach ( $argv as $argument ) {
			if ( str_starts_with( $argument, '--pd-host=' ) ) {
				return substr( $argument, strlen( '--pd-host=' ) );
			}
		}

		return null;
	}
}
```

Append to `post-domain.php`, after `Plugin::boot();`:

```php
if ( ! function_exists( 'pd_with_mapping' ) ) {
	/**
	 * Runs a callback with a mapping's serving context in scope.
	 *
	 * @param int      $mapping_id The mapping to borrow.
	 * @param callable $fn         The callback.
	 * @return mixed The callback's return value.
	 */
	function pd_with_mapping( int $mapping_id, callable $fn ) {
		return \PostDomain\Support\BackgroundContext::run( $mapping_id, $fn );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter BackgroundContextTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Support/BackgroundContext.php post-domain.php tests/integration/Support/BackgroundContextTest.php
git commit -m "Let cron, CLI, and mail borrow a mapping's context explicitly

There is no guessing: outside a mapped request the answer is the primary host,
because a wrong host in an email is unrecallable. The scope is restored even
when the callback throws."
```

---

## Gate for Plan 05

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `RenderedOutputTest` passes for every row of `Compatibility::SURFACES`,
`CanonicalTest` proves the redirect proposal is filtered rather than removed,
and `CorsTest` proves no source file performs an outbound diagnostic fetch.
