# post-domain 01 — Foundation and host model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An installable WordPress plugin that boots on single-site, is inert on
multisite, and turns any raw `Host` header into a normalized ASCII hostname or a
definite rejection — with the complete toolchain and CI green.

**Architecture:** A Composer project with PSR-4 autoloading under `PostDomain\`,
vendor code prefixed into `PostDomain\Vendor\` at build time. The host model is
four pure, dependency-free classes composed in a fixed order: parse the authority,
compare the allowlist, normalize the host, and only then decide anything. Each is
independently unit-testable without WordPress.

**Tech Stack:** PHP 8.1, WordPress 6.4, Composer, PHPUnit 9.6,
`symfony/polyfill-intl-idn` 1.38.1, `jeremykendall/php-domain-parser`,
PHP-Scoper, PHP_CodeSniffer with WordPress-Extra, PHPStan level 8,
`@wordpress/env` for integration tests, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

These apply to every task in every plan in this suite.

- **WordPress 6.4 minimum, PHP 8.1 minimum.** No PHP extension is a hard
  requirement (spec §18, §3.4).
- **Single site only.** Activation on multisite is refused; every other bootstrap
  on multisite registers no hooks at all (spec §1.1).
- **Clean room.** No code, convention, or content model from any other project
  informs this one. Conventions come from the spec.
- **Portability test:** two unrelated sites, with different content models and
  different authoritative DNS providers, must both run this plugin unmodified
  (spec §1.2).
- **`PostDomain\Support\IdnaNormalizer` is the only caller of the vendored UTS-46
  implementation.** It calls `Symfony\Polyfill\Intl\Idn\Idn::*` directly, never
  the global `idn_to_*` functions (spec §3.4).
- **Dependencies are pinned exactly** — `symfony/polyfill-intl-idn:1.38.1` — with
  `composer.lock` committed and the prefixed build reproducible from it (spec §18).
- **Mapped hosts are exact hosts.** Wildcard mappings are invalid and wildcard
  certificate provisioning is out of scope (spec §1, §14.16).
- **Commits carry no AI, Claude, Codex, or generated-content attribution.**
- All stored timestamps are UTC via `gmdate( 'Y-m-d H:i:s' )`; `current_time()` is
  never called (spec §12.4).
- Credentials never appear in a database row, a REST response, an event, or a log
  line (spec §12.4, §14.11).

---

## File map

| File | Responsibility |
|---|---|
| `composer.json` | Dependencies, PSR-4 autoload, the `test` / `lint` / `analyse` / `build` scripts every later plan invokes |
| `composer.lock` | Pinned resolution, committed |
| `.gitignore` | Excludes `vendor/`, `node_modules/`, `build/`, `.phpunit.result.cache` |
| `phpunit.xml.dist` | Two suites: `unit` (no WordPress) and `integration` (wp-env) |
| `phpcs.xml.dist` | WordPress-Extra ruleset, PHP 8.1 target |
| `phpstan.neon.dist` | Level 8, WordPress stubs, `src/` only |
| `scoper.inc.php` | Prefixes all vendor code into `PostDomain\Vendor\` |
| `.wp-env.json` | wp-env definition pinning WordPress 6.4 and PHP 8.1 |
| `.github/workflows/ci.yml` | Runs lint, analyse, unit, integration on push |
| `package.json` | `@wordpress/env` devDependency only |
| `post-domain.php` | Plugin header and bootstrap, in the fixed order of spec §5.4 |
| `src/Support/Environment.php` | Answers "may this plugin run here?" — versions and multisite |
| `src/Support/Activation.php` | The activation-time refusal |
| `src/Support/Authority.php` | Value object: extracted host, port, IPv6 form |
| `src/Support/AuthorityParser.php` | Raw `Host` header → `Authority` or `null` |
| `src/Support/InfrastructureAllowlist.php` | Exact-match allowlist over parsed hosts |
| `src/Support/IdnaNormalizer.php` | The single UTS-46 implementation |
| `src/Support/HostNormalizer.php` | `Authority` → normalized ASCII host or `null` |
| `src/Support/TrustedProxy.php` | Which authority to trust from `$_SERVER` |
| `tests/bootstrap-unit.php` | Composer autoload only |
| `tests/bootstrap-integration.php` | Loads the WordPress test suite and the plugin |
| `tests/unit/Support/*Test.php` | One test file per Support class |
| `tests/unit/fixtures/uts46.txt` | Fixed UTS-46 conformance vectors |
| `tests/integration/ActivationTest.php` | Activation and multisite inertness against real WordPress |

---

### Task 1: Composer project and the environment gate

**Files:**
- Create: `composer.json`, `.gitignore`, `phpunit.xml.dist`, `tests/bootstrap-unit.php`
- Create: `src/Support/Environment.php`
- Test: `tests/unit/Support/EnvironmentTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Support\Environment::blocker( string $php_version, string $wp_version, bool $is_multisite ): ?string` — returns a human-readable reason the plugin must not run, or `null` when it may. Consumed by Task 2's bootstrap and Task 3's activation guard.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/EnvironmentTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\Environment;

final class EnvironmentTest extends TestCase {

	public function test_supported_environment_has_no_blocker(): void {
		$this->assertNull( Environment::blocker( '8.1.0', '6.4', false ) );
		$this->assertNull( Environment::blocker( '8.3.2', '6.7.1', false ) );
	}

	public function test_multisite_is_blocked(): void {
		$this->assertSame(
			'post-domain does not support multisite networks.',
			Environment::blocker( '8.2.0', '6.5', true )
		);
	}

	public function test_php_below_floor_is_blocked(): void {
		$this->assertSame(
			'post-domain requires PHP 8.1 or later; this site runs 8.0.30.',
			Environment::blocker( '8.0.30', '6.5', false )
		);
	}

	public function test_wordpress_below_floor_is_blocked(): void {
		$this->assertSame(
			'post-domain requires WordPress 6.4 or later; this site runs 6.3.2.',
			Environment::blocker( '8.1.0', '6.3.2', false )
		);
	}

	public function test_multisite_outranks_a_version_blocker(): void {
		$this->assertSame(
			'post-domain does not support multisite networks.',
			Environment::blocker( '8.0.0', '6.0', true )
		);
	}
}
```

- [ ] **Step 2: Create the project files, then run the test to verify it fails**

Create `composer.json`:

```json
{
	"name": "akaienso/post-domain",
	"description": "Maps a domain name to a single WordPress post, resolved rather than redirected.",
	"type": "wordpress-plugin",
	"license": "GPL-2.0-or-later",
	"require": {
		"php": ">=8.1",
		"symfony/polyfill-intl-idn": "1.38.1",
		"jeremykendall/php-domain-parser": "^6.3"
	},
	"require-dev": {
		"phpunit/phpunit": "^9.6",
		"yoast/phpunit-polyfills": "^2.0",
		"squizlabs/php_codesniffer": "^3.7",
		"wp-coding-standards/wpcs": "^3.0",
		"dealerdirect/phpcodesniffer-composer-installer": "^1.0",
		"phpstan/phpstan": "^1.10",
		"szepeviktor/phpstan-wordpress": "^1.3",
		"humbug/php-scoper": "^0.18"
	},
	"autoload": {
		"psr-4": { "PostDomain\\": "src/" }
	},
	"autoload-dev": {
		"psr-4": { "PostDomain\\Tests\\": "tests/" }
	},
	"config": {
		"allow-plugins": {
			"dealerdirect/phpcodesniffer-composer-installer": true
		}
	},
	"scripts": {
		"test": "phpunit --testsuite unit",
		"test:integration": "wp-env run tests-cli --env-cwd=wp-content/plugins/post-domain vendor/bin/phpunit --testsuite integration",
		"lint": "phpcs",
		"analyse": "phpstan analyse"
	}
}
```

Create `.gitignore`:

```
/vendor/
/node_modules/
/build/
.phpunit.result.cache
```

Create `phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap-unit.php" colors="true" failOnWarning="true" failOnRisky="true">
	<testsuites>
		<testsuite name="unit">
			<directory suffix="Test.php">tests/unit</directory>
		</testsuite>
		<testsuite name="integration">
			<directory suffix="Test.php">tests/integration</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

Create `tests/bootstrap-unit.php`:

```php
<?php
declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';
```

Run: `composer install && vendor/bin/phpunit --testsuite unit --filter EnvironmentTest`
Expected: FAIL — `Error: Class "PostDomain\Support\Environment" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/Environment.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Answers one question: may this plugin run here?
 */
final class Environment {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.4';

	/**
	 * @return string|null A human-readable reason, or null when the plugin may run.
	 */
	public static function blocker( string $php_version, string $wp_version, bool $is_multisite ): ?string {
		if ( $is_multisite ) {
			return 'post-domain does not support multisite networks.';
		}

		if ( version_compare( $php_version, self::MIN_PHP, '<' ) ) {
			return sprintf(
				'post-domain requires PHP %s or later; this site runs %s.',
				self::MIN_PHP,
				$php_version
			);
		}

		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			return sprintf(
				'post-domain requires WordPress %s or later; this site runs %s.',
				self::MIN_WP,
				$wp_version
			);
		}

		return null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter EnvironmentTest`
Expected: PASS — 5 tests, 6 assertions

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock .gitignore phpunit.xml.dist tests/bootstrap-unit.php src/Support/Environment.php tests/unit/Support/EnvironmentTest.php
git commit -m "Add the Composer project and the environment gate

Multisite outranks the version floors: a network install must report the
unsupported-network reason even when it also runs an old PHP, because the
network is the reason the plugin will never run there."
```

---

### Task 2: Plugin bootstrap in the specified order

**Files:**
- Create: `post-domain.php`, `src/Support/Activation.php`
- Test: `tests/unit/Support/ActivationTest.php`

**Interfaces:**
- Consumes: `Environment::blocker()` from Task 1.
- Produces: `PostDomain\Support\Activation::refusal(): ?string` — the message `register_activation_hook` dies with, or `null` to allow activation. The bootstrap file itself defines the load order asserted by Task 3.

The order is fixed by spec §5.4 and is the point of this task: the autoloader
loads first, the activation hook registers **second** — before the multisite
early return, so that the refusal exists on the very installs it must refuse —
and only then does the plugin go inert or boot.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/ActivationTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\Activation;

final class ActivationTest extends TestCase {

	public function test_no_refusal_on_a_supported_single_site(): void {
		$this->assertNull( Activation::refusal( '8.1.0', '6.4', false ) );
	}

	public function test_multisite_refusal_explains_why(): void {
		$refusal = Activation::refusal( '8.2.0', '6.5', true );

		$this->assertNotNull( $refusal );
		$this->assertStringContainsString( 'multisite', $refusal );
		$this->assertStringContainsString( 'different problem', $refusal );
	}

	public function test_version_refusal_names_the_floor(): void {
		$refusal = Activation::refusal( '8.0.0', '6.4', false );

		$this->assertNotNull( $refusal );
		$this->assertStringContainsString( 'PHP 8.1', $refusal );
	}

	public function test_bootstrap_registers_the_activation_hook_before_the_multisite_return(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../post-domain.php' );

		$autoload   = strpos( $source, "require_once __DIR__ . '/vendor/autoload.php';" );
		$hook       = strpos( $source, 'register_activation_hook' );
		$multisite  = strpos( $source, 'is_multisite()' );

		$this->assertNotFalse( $autoload, 'the bootstrap must require the autoloader' );
		$this->assertNotFalse( $hook, 'the bootstrap must register the activation hook' );
		$this->assertNotFalse( $multisite, 'the bootstrap must check for multisite' );
		$this->assertLessThan( $hook, $autoload, 'autoloader must load before the hook registers' );
		$this->assertLessThan( $multisite, $hook, 'the hook must register before the multisite return' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter ActivationTest`
Expected: FAIL — `Error: Class "PostDomain\Support\Activation" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/Activation.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class Activation {

	/**
	 * @return string|null The message to die with, or null to allow activation.
	 */
	public static function refusal( string $php_version, string $wp_version, bool $is_multisite ): ?string {
		if ( $is_multisite ) {
			return 'post-domain cannot be activated on a multisite network. '
				. 'Domain mapping in a network is a different problem with a different '
				. 'solution, and supporting both makes both worse.';
		}

		return Environment::blocker( $php_version, $wp_version, false );
	}

	public static function guard(): void {
		$refusal = self::refusal( PHP_VERSION, get_bloginfo( 'version' ), is_multisite() );

		if ( null !== $refusal ) {
			wp_die( esc_html( $refusal ) );
		}
	}
}
```

Create `post-domain.php`:

```php
<?php
/**
 * Plugin Name: post-domain
 * Description: Maps a domain name to a single post, resolved rather than redirected.
 * Version:     0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// 1. The autoloader, so the activation guard below can be resolved.
require_once __DIR__ . '/vendor/autoload.php';

// 2. The activation hook, registered BEFORE the multisite return so that the
//    refusal exists on exactly the installs it has to refuse.
register_activation_hook( __FILE__, array( \PostDomain\Support\Activation::class, 'guard' ) );

// 3. Inert on multisite: no hooks at all, one notice.
if ( is_multisite() ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html( 'post-domain is inactive: it does not support multisite networks.' )
				. '</p></div>';
		}
	);

	return;
}

$pd_blocker = \PostDomain\Support\Environment::blocker( PHP_VERSION, get_bloginfo( 'version' ), false );

if ( null !== $pd_blocker ) {
	add_action(
		'admin_notices',
		static function () use ( $pd_blocker ): void {
			echo '<div class="notice notice-error"><p>' . esc_html( $pd_blocker ) . '</p></div>';
		}
	);

	return;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter ActivationTest`
Expected: PASS — 4 tests

- [ ] **Step 5: Commit**

```bash
git add post-domain.php src/Support/Activation.php tests/unit/Support/ActivationTest.php
git commit -m "Bootstrap the plugin in the order the design requires

The activation hook registers before the multisite early return. Registering
it afterwards means the refusal never installs on a network site, which is the
one install where it has to exist."
```

---

### Task 3: wp-env harness and activation behaviour against real WordPress

**Files:**
- Create: `.wp-env.json`, `package.json`, `tests/bootstrap-integration.php`
- Test: `tests/integration/ActivationTest.php`

**Interfaces:**
- Consumes: the bootstrap from Task 2.
- Produces: the `composer test:integration` command and the integration bootstrap that every later plan's integration tests load.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/ActivationTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {

	public function test_the_plugin_is_loaded(): void {
		$this->assertTrue( class_exists( \PostDomain\Support\Environment::class ) );
	}

	public function test_this_install_is_single_site(): void {
		$this->assertFalse( is_multisite(), 'the integration suite must run on single site' );
	}

	public function test_the_activation_guard_allows_this_install(): void {
		$this->assertNull(
			\PostDomain\Support\Activation::refusal( PHP_VERSION, get_bloginfo( 'version' ), is_multisite() )
		);
	}

	public function test_the_activation_guard_refuses_a_network(): void {
		$refusal = \PostDomain\Support\Activation::refusal( PHP_VERSION, get_bloginfo( 'version' ), true );

		$this->assertNotNull( $refusal );
		$this->assertStringContainsString( 'multisite', $refusal );
	}
}
```

- [ ] **Step 2: Create the harness, then run the test to verify it fails**

Create `.wp-env.json`:

```json
{
	"core": "WordPress/WordPress#6.4",
	"phpVersion": "8.1",
	"plugins": [ "." ],
	"config": {
		"WP_DEBUG": true,
		"WP_DEBUG_DISPLAY": true
	}
}
```

Create `package.json`:

```json
{
	"name": "post-domain",
	"private": true,
	"devDependencies": {
		"@wordpress/env": "^10.0.0"
	}
}
```

Create `tests/bootstrap-integration.php`:

```php
<?php
declare( strict_types = 1 );

$pd_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once __DIR__ . '/../vendor/autoload.php';
require_once $pd_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/post-domain.php';
	}
);

require $pd_tests_dir . '/includes/bootstrap.php';
```

Add the integration bootstrap to `phpunit.xml.dist` by replacing the
`integration` suite declaration with one that carries its own bootstrap:

```xml
		<testsuite name="integration">
			<directory suffix="Test.php">tests/integration</directory>
		</testsuite>
```

and create `phpunit-integration.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap-integration.php" colors="true" failOnWarning="true">
	<testsuites>
		<testsuite name="integration">
			<directory suffix="Test.php">tests/integration</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

Update the `test:integration` script in `composer.json` to:

```json
		"test:integration": "wp-env run tests-cli --env-cwd=wp-content/plugins/post-domain vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

Run: `npx wp-env start && composer test:integration`
Expected: FAIL — the suite cannot start until the bootstrap resolves; once it
does, `test_the_plugin_is_loaded` fails with
`Failed asserting that false is true` if the plugin did not load.

- [ ] **Step 3: Write minimal implementation**

No production code is required — Task 2 already provides it. If
`test_the_plugin_is_loaded` fails, the cause is the `muplugins_loaded` filter
path in `tests/bootstrap-integration.php`; correct the path so it resolves to the
repository root's `post-domain.php`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration`
Expected: PASS — 4 tests

- [ ] **Step 5: Commit**

```bash
git add .wp-env.json package.json package-lock.json tests/bootstrap-integration.php phpunit-integration.xml.dist composer.json tests/integration/ActivationTest.php
git commit -m "Run integration tests against a real WordPress 6.4 on PHP 8.1

Rendered-output assertions are the whole point of the integration suite, and
mocks cannot produce rendered output."
```

---

### Task 4: Authority parsing

**Files:**
- Create: `src/Support/Authority.php`, `src/Support/AuthorityParser.php`
- Test: `tests/unit/Support/AuthorityParserTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `PostDomain\Support\Authority` — readonly `string $host`, `?int $port`, `bool $is_ipv6_literal`, `string $bracketed_form`.
  - `PostDomain\Support\AuthorityParser::parse( string $raw ): ?Authority` — `null` always means the request is `MALFORMED_400`.

This runs **before** the allowlist and before IDN normalization, so a malformed
authority can never be reshaped into something that matches an allowlist entry
(spec §3.1).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/AuthorityParserTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\AuthorityParser;

final class AuthorityParserTest extends TestCase {

	/**
	 * @dataProvider malformed_authorities
	 */
	public function test_malformed_authorities_are_rejected( string $raw, string $why ): void {
		$this->assertNull( ( new AuthorityParser() )->parse( $raw ), $why );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function malformed_authorities(): array {
		return array(
			'empty port'           => array( 'example.com:', 'a colon with no port is malformed' ),
			'zero port'            => array( 'example.com:0', 'port 0 is out of range' ),
			'port out of range'    => array( 'example.com:99999', 'ports stop at 65535' ),
			'non numeric port'     => array( 'example.com:abc', 'ports are decimal only' ),
			'hex port'             => array( 'example.com:0x50', 'ports are decimal only' ),
			'signed port'          => array( 'example.com:+80', 'ports carry no sign' ),
			'unclosed bracket'     => array( '[::1', 'an unbalanced bracket is malformed' ),
			'bare ipv6 with port'  => array( '::1:80', 'unbracketed ipv6 with a port is ambiguous' ),
			'internal space'       => array( 'ex ample.com', 'internal whitespace is malformed' ),
			'internal tab'         => array( "ex\tample.com", 'internal whitespace is malformed' ),
			'userinfo'             => array( 'user@example.com', 'userinfo has no place in a Host header' ),
			'path'                 => array( 'example.com/wp-admin', 'a path is not part of the authority' ),
			'backslash'            => array( 'example.com\\evil', 'a backslash is a path separator' ),
			'query'                => array( 'example.com?a=b', 'a query is not part of the authority' ),
			'fragment'             => array( 'example.com#x', 'a fragment is not part of the authority' ),
			'null byte'            => array( "example.com\0", 'NUL is a control character' ),
			'control character'    => array( "example.com\x01", 'control characters are malformed' ),
		);
	}

	public function test_a_plain_host_parses_with_identity_unchanged(): void {
		$authority = ( new AuthorityParser() )->parse( 'Example.COM' );

		$this->assertNotNull( $authority );
		$this->assertSame( 'Example.COM', $authority->host, 'parsing must not alter identity' );
		$this->assertNull( $authority->port );
		$this->assertFalse( $authority->is_ipv6_literal );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		$authority = ( new AuthorityParser() )->parse( "  example.com\t" );

		$this->assertNotNull( $authority );
		$this->assertSame( 'example.com', $authority->host );
	}

	public function test_a_valid_port_is_extracted(): void {
		$authority = ( new AuthorityParser() )->parse( 'example.com:8443' );

		$this->assertNotNull( $authority );
		$this->assertSame( 'example.com', $authority->host );
		$this->assertSame( 8443, $authority->port );
	}

	public function test_a_bracketed_ipv6_literal_parses(): void {
		$authority = ( new AuthorityParser() )->parse( '[2001:db8::1]:443' );

		$this->assertNotNull( $authority );
		$this->assertTrue( $authority->is_ipv6_literal );
		$this->assertSame( '2001:db8::1', $authority->host );
		$this->assertSame( '[2001:db8::1]', $authority->bracketed_form );
		$this->assertSame( 443, $authority->port );
	}

	public function test_an_invalid_bracketed_literal_is_rejected(): void {
		$this->assertNull( ( new AuthorityParser() )->parse( '[not:an:address:zz]' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AuthorityParserTest`
Expected: FAIL — `Error: Class "PostDomain\Support\AuthorityParser" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/Authority.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

final class Authority {

	public function __construct(
		public readonly string $host,
		public readonly ?int $port,
		public readonly bool $is_ipv6_literal,
		public readonly string $bracketed_form
	) {}
}
```

Create `src/Support/AuthorityParser.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * A Host header is an authority, not a hostname. Anything this returns null for
 * is MALFORMED_400 — never silently repaired, because a repaired authority could
 * match an allowlist entry it has no right to.
 */
final class AuthorityParser {

	public function parse( string $raw ): ?Authority {
		$value = trim( $raw, " \t" );

		if ( '' === $value ) {
			return null;
		}

		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return null;
		}

		if ( 1 === preg_match( '~[\s/\\\\?#@]~', $value ) ) {
			return null;
		}

		if ( str_starts_with( $value, '[' ) ) {
			return $this->parse_bracketed( $value );
		}

		if ( substr_count( $value, ':' ) > 1 ) {
			return null;
		}

		$host = $value;
		$port = null;

		if ( str_contains( $value, ':' ) ) {
			[ $host, $port_text ] = explode( ':', $value, 2 );
			$port                 = $this->parse_port( $port_text );

			if ( null === $port || '' === $host ) {
				return null;
			}
		}

		return new Authority( $host, $port, false, $host );
	}

	private function parse_bracketed( string $value ): ?Authority {
		if ( 1 !== preg_match( '/^\[([0-9A-Fa-f:.]+)\](?::([0-9]+))?$/', $value, $matches ) ) {
			return null;
		}

		$literal = $matches[1];

		if ( false === filter_var( $literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return null;
		}

		$port = null;

		if ( isset( $matches[2] ) ) {
			$port = $this->parse_port( $matches[2] );

			if ( null === $port ) {
				return null;
			}
		}

		return new Authority( $literal, $port, true, '[' . $literal . ']' );
	}

	private function parse_port( string $text ): ?int {
		if ( 1 !== preg_match( '/^[0-9]+$/', $text ) ) {
			return null;
		}

		$port = (int) $text;

		return ( $port >= 1 && $port <= 65535 ) ? $port : null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter AuthorityParserTest`
Expected: PASS — 22 tests

- [ ] **Step 5: Commit**

```bash
git add src/Support/Authority.php src/Support/AuthorityParser.php tests/unit/Support/AuthorityParserTest.php
git commit -m "Parse the Host header as an authority before anything reads it

A malformed port or bracket form must never be discarded in a way that turns a
malformed authority into an allowlisted host, so parsing runs first and its
only failure mode is a definite rejection."
```

---

### Task 5: Infrastructure allowlist

**Files:**
- Create: `src/Support/InfrastructureAllowlist.php`
- Test: `tests/unit/Support/InfrastructureAllowlistTest.php`

**Interfaces:**
- Consumes: `Authority`, `AuthorityParser` from Task 4.
- Produces: `PostDomain\Support\InfrastructureAllowlist::__construct( string[] $entries )` and `::allows( Authority $authority ): bool`, plus `::entries(): string[]` exposing the sanitized list for diagnostics.

The allowlist is compared **after** parsing and **before** IDN normalization,
because the hosts that belong on it — health-check names, bare IPs, `localhost` —
are exactly the ones the normalizer rejects (spec §3.2).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/InfrastructureAllowlistTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\InfrastructureAllowlist;

final class InfrastructureAllowlistTest extends TestCase {

	private function allows( array $entries, string $raw ): bool {
		$authority = ( new AuthorityParser() )->parse( $raw );
		$this->assertNotNull( $authority, 'the fixture authority must parse' );

		return ( new InfrastructureAllowlist( $entries ) )->allows( $authority );
	}

	public function test_an_exact_hostname_matches_case_insensitively(): void {
		$this->assertTrue( $this->allows( array( 'origin.example.com' ), 'ORIGIN.Example.com' ) );
	}

	public function test_localhost_matches(): void {
		$this->assertTrue( $this->allows( array( 'localhost' ), 'localhost' ) );
	}

	public function test_an_ipv4_literal_matches(): void {
		$this->assertTrue( $this->allows( array( '10.0.0.4' ), '10.0.0.4' ) );
	}

	public function test_a_bracketed_ipv6_literal_matches_in_bracketed_form(): void {
		$this->assertTrue( $this->allows( array( '[2001:db8::1]' ), '[2001:db8::1]' ) );
	}

	public function test_a_port_on_the_request_does_not_defeat_the_match(): void {
		$this->assertTrue( $this->allows( array( 'origin.example.com' ), 'origin.example.com:8443' ) );
	}

	public function test_a_different_host_does_not_match(): void {
		$this->assertFalse( $this->allows( array( 'origin.example.com' ), 'evil.example.com' ) );
	}

	public function test_no_suffix_matching(): void {
		$this->assertFalse( $this->allows( array( 'example.com' ), 'sub.example.com' ) );
	}

	public function test_wildcard_entries_are_dropped(): void {
		$list = new InfrastructureAllowlist( array( '*.example.com', 'origin.example.com' ) );

		$this->assertSame( array( 'origin.example.com' ), $list->entries() );
	}

	public function test_entries_carrying_a_port_are_dropped(): void {
		$list = new InfrastructureAllowlist( array( 'origin.example.com:8443', 'localhost' ) );

		$this->assertSame( array( 'localhost' ), $list->entries() );
	}

	public function test_unparseable_entries_are_dropped(): void {
		$list = new InfrastructureAllowlist( array( 'bad host', '[::1', 'localhost' ) );

		$this->assertSame( array( 'localhost' ), $list->entries() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter InfrastructureAllowlistTest`
Expected: FAIL — `Error: Class "PostDomain\Support\InfrastructureAllowlist" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/InfrastructureAllowlist.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Exact-match only. No wildcards, no suffixes: an allowlist entry is a host,
 * not an authority.
 */
final class InfrastructureAllowlist {

	/** @var string[] */
	private array $entries;

	/**
	 * @param string[] $entries
	 */
	public function __construct( array $entries ) {
		$parser        = new AuthorityParser();
		$this->entries = array();

		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) || str_contains( $entry, '*' ) ) {
				continue;
			}

			$authority = $parser->parse( $entry );

			if ( null === $authority || null !== $authority->port ) {
				continue;
			}

			$this->entries[] = strtolower( $entry );
		}

		$this->entries = array_values( array_unique( $this->entries ) );
	}

	public function allows( Authority $authority ): bool {
		$candidate = strtolower(
			$authority->is_ipv6_literal ? $authority->bracketed_form : $authority->host
		);

		return in_array( $candidate, $this->entries, true );
	}

	/** @return string[] */
	public function entries(): array {
		return $this->entries;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter InfrastructureAllowlistTest`
Expected: PASS — 10 tests

- [ ] **Step 5: Commit**

```bash
git add src/Support/InfrastructureAllowlist.php tests/unit/Support/InfrastructureAllowlistTest.php
git commit -m "Compare the infrastructure allowlist on parsed, exact hosts

The hosts that belong on this list are the ones the IDN pipeline rejects, so the
comparison has to happen between parsing and normalization."
```

---

### Task 6: IDN normalization, single implementation

**Files:**
- Create: `src/Support/IdnaNormalizer.php`, `tests/unit/fixtures/uts46.txt`
- Test: `tests/unit/Support/IdnaNormalizerTest.php`

**Interfaces:**
- Consumes: `Symfony\Polyfill\Intl\Idn\Idn` (vendored).
- Produces: `PostDomain\Support\IdnaNormalizer::to_ascii( string $host ): ?string` and `::to_display( string $ascii ): string`.

`IdnaNormalizer` is the **only** caller of the vendored UTS-46 implementation, and
it calls the class directly rather than the global `idn_to_*` functions, so
behaviour is identical whether or not a PHP IDN extension is present (spec §3.4).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/fixtures/uts46.txt` with the fixed conformance vectors, one
`input;expected_ascii` pair per line and `#` for comments:

```
# Fixed UTS-46 vectors. A change here is a deliberate behaviour change.
münchen.example;xn--mnchen-3ya.example
xn--mnchen-3ya.example;xn--mnchen-3ya.example
Bücher.example;xn--bcher-kva.example
example.com;example.com
EXAMPLE.COM;example.com
faß.example;xn--fa-hia.example
日本.example;xn--wgv71a.example
xn--wgv71a.example;xn--wgv71a.example
```

Create `tests/unit/Support/IdnaNormalizerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\IdnaNormalizer;

final class IdnaNormalizerTest extends TestCase {

	/**
	 * @dataProvider uts46_vectors
	 */
	public function test_uts46_conformance_vectors( string $input, string $expected ): void {
		$this->assertSame( $expected, ( new IdnaNormalizer() )->to_ascii( $input ) );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function uts46_vectors(): array {
		$lines   = (array) file( __DIR__ . '/../../fixtures/uts46.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$vectors = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			[ $input, $expected ] = explode( ';', $line, 2 );
			$vectors[]            = array( $input, $expected );
		}

		return $vectors;
	}

	public function test_unicode_and_punycode_input_converge_on_one_ascii_host(): void {
		$normalizer = new IdnaNormalizer();

		$this->assertSame(
			$normalizer->to_ascii( 'münchen.example' ),
			$normalizer->to_ascii( 'xn--mnchen-3ya.example' ),
			'the same domain typed two ways must produce one row key'
		);
	}

	public function test_invalid_punycode_is_rejected(): void {
		$this->assertNull( ( new IdnaNormalizer() )->to_ascii( 'xn--.example' ) );
	}

	public function test_display_form_returns_unicode(): void {
		$this->assertSame(
			'münchen.example',
			( new IdnaNormalizer() )->to_display( 'xn--mnchen-3ya.example' )
		);
	}

	public function test_the_global_idn_functions_are_never_called(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../src/Support/IdnaNormalizer.php' );

		$this->assertStringNotContainsString( 'idn_to_ascii(', $source );
		$this->assertStringNotContainsString( 'idn_to_utf8(', $source );
		$this->assertStringContainsString( 'Idn::idn_to_ascii', $source );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter IdnaNormalizerTest`
Expected: FAIL — `Error: Class "PostDomain\Support\IdnaNormalizer" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/IdnaNormalizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use Symfony\Polyfill\Intl\Idn\Idn;

/**
 * The single UTS-46 implementation. Called through the class, never through the
 * global idn_to_* functions: those exist only when a PHP extension provides
 * them, and two UTS-46 implementations disagreeing on one input is a
 * verification-bypass shape.
 */
final class IdnaNormalizer {

	private const VARIANT = INTL_IDNA_VARIANT_UTS46;

	private const FLAGS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

	public function to_ascii( string $host ): ?string {
		$unicode = Idn::idn_to_utf8( $host, IDNA_NONTRANSITIONAL_TO_UNICODE, self::VARIANT, $unicode_info );

		if ( false === $unicode ) {
			return null;
		}

		$ascii = Idn::idn_to_ascii( $unicode, self::FLAGS, self::VARIANT, $ascii_info );

		if ( false === $ascii || '' === $ascii ) {
			return null;
		}

		$ascii = strtolower( $ascii );

		// The round trip must be stable, which is what catches invalid punycode.
		$again = Idn::idn_to_utf8( $ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, self::VARIANT );

		if ( false === $again ) {
			return null;
		}

		$restable = Idn::idn_to_ascii( $again, self::FLAGS, self::VARIANT );

		if ( false === $restable || strtolower( (string) $restable ) !== $ascii ) {
			return null;
		}

		return $ascii;
	}

	public function to_display( string $ascii ): string {
		$unicode = Idn::idn_to_utf8( $ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, self::VARIANT );

		return false === $unicode ? $ascii : $unicode;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter IdnaNormalizerTest`
Expected: PASS — 12 tests (8 vectors plus 4 behaviours)

- [ ] **Step 5: Commit**

```bash
git add src/Support/IdnaNormalizer.php tests/unit/fixtures/uts46.txt tests/unit/Support/IdnaNormalizerTest.php
git commit -m "Normalize IDN through one bundled UTS-46 implementation

Calling the polyfill class directly rather than the global functions keeps
behaviour identical on every host. Two implementations that disagree on one
input is how a verification bypass gets in."
```

---

### Task 7: Host normalization

**Files:**
- Create: `src/Support/HostNormalizer.php`
- Test: `tests/unit/Support/HostNormalizerTest.php`

**Interfaces:**
- Consumes: `Authority` (Task 4), `IdnaNormalizer` (Task 6).
- Produces: `PostDomain\Support\HostNormalizer::normalize( Authority $authority ): ?string` — the stored ASCII form, or `null` for `MALFORMED_400`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/HostNormalizerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;

final class HostNormalizerTest extends TestCase {

	private function normalize( string $raw ): ?string {
		$authority = ( new AuthorityParser() )->parse( $raw );
		$this->assertNotNull( $authority, 'the fixture authority must parse' );

		return ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );
	}

	public function test_a_plain_host_lowercases(): void {
		$this->assertSame( 'example.com', $this->normalize( 'Example.COM' ) );
	}

	public function test_one_trailing_dot_is_stripped(): void {
		$this->assertSame( 'example.com', $this->normalize( 'example.com.' ) );
	}

	public function test_unicode_becomes_punycode(): void {
		$this->assertSame( 'xn--mnchen-3ya.example', $this->normalize( 'münchen.example' ) );
	}

	public function test_ip_literals_are_not_mappable_hosts(): void {
		$this->assertNull( $this->normalize( '10.0.0.4' ) );
		$this->assertNull( $this->normalize( '[2001:db8::1]' ) );
	}

	public function test_wildcard_labels_are_rejected(): void {
		$this->assertNull( $this->normalize( '*.example.com' ), 'wildcard mappings are out of scope' );
		$this->assertNull( $this->normalize( '*example.com' ) );
	}

	public function test_a_label_over_63_bytes_is_rejected(): void {
		$this->assertNull( $this->normalize( str_repeat( 'a', 64 ) . '.example' ) );
	}

	public function test_a_host_over_253_bytes_is_rejected(): void {
		$long = implode( '.', array_fill( 0, 10, str_repeat( 'a', 25 ) ) ) . '.example';

		$this->assertGreaterThan( 253, strlen( $long ) );
		$this->assertNull( $this->normalize( $long ) );
	}

	public function test_a_leading_hyphen_label_is_rejected(): void {
		$this->assertNull( $this->normalize( '-bad.example' ) );
	}

	public function test_a_trailing_hyphen_label_is_rejected(): void {
		$this->assertNull( $this->normalize( 'bad-.example' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter HostNormalizerTest`
Expected: FAIL — `Error: Class "PostDomain\Support\HostNormalizer" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/HostNormalizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Total, never throws. Its output is the stored key, the lookup key, and what
 * goes into DNS.
 */
final class HostNormalizer {

	public function __construct( private readonly IdnaNormalizer $idna ) {}

	public function normalize( Authority $authority ): ?string {
		if ( $authority->is_ipv6_literal ) {
			return null;
		}

		$host = $authority->host;

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( str_ends_with( $host, '.' ) ) {
			$host = substr( $host, 0, -1 );
		}

		if ( '' === $host || str_contains( $host, '*' ) ) {
			return null;
		}

		$ascii = $this->idna->to_ascii( $host );

		if ( null === $ascii || strlen( $ascii ) > 253 ) {
			return null;
		}

		foreach ( explode( '.', $ascii ) as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return null;
			}
		}

		return $ascii;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter HostNormalizerTest`
Expected: PASS — 12 tests

- [ ] **Step 5: Commit**

```bash
git add src/Support/HostNormalizer.php tests/unit/Support/HostNormalizerTest.php
git commit -m "Normalize a parsed authority into the stored ASCII host

Wildcard labels are rejected here rather than anywhere later: mapped hosts are
exact hosts, so a wildcard mapping cannot come into existence at all."
```

---

### Task 8: Trusted proxies

**Files:**
- Create: `src/Support/TrustedProxy.php`
- Test: `tests/unit/Support/TrustedProxyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Support\TrustedProxy::__construct( string[] $cidrs )` and `::served_authority( array $server ): string` — the raw authority string to parse, honouring forwarded headers only from an allowlisted `REMOTE_ADDR`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Support/TrustedProxyTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostDomain\Support\TrustedProxy;

final class TrustedProxyTest extends TestCase {

	public function test_forwarded_headers_are_ignored_by_default(): void {
		$proxy = new TrustedProxy( array() );

		$this->assertSame(
			'real.example',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'real.example',
					'HTTP_X_FORWARDED_HOST' => 'spoofed.example',
					'REMOTE_ADDR'           => '203.0.113.9',
				)
			)
		);
	}

	public function test_forwarded_host_is_honoured_from_a_trusted_proxy(): void {
		$proxy = new TrustedProxy( array( '10.0.0.0/8' ) );

		$this->assertSame(
			'forwarded.example',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'lb.internal',
					'HTTP_X_FORWARDED_HOST' => 'forwarded.example',
					'REMOTE_ADDR'           => '10.1.2.3',
				)
			)
		);
	}

	public function test_forwarded_host_is_ignored_from_an_untrusted_address(): void {
		$proxy = new TrustedProxy( array( '10.0.0.0/8' ) );

		$this->assertSame(
			'lb.internal',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'lb.internal',
					'HTTP_X_FORWARDED_HOST' => 'forwarded.example',
					'REMOTE_ADDR'           => '203.0.113.9',
				)
			)
		);
	}

	public function test_only_the_first_forwarded_host_is_taken(): void {
		$proxy = new TrustedProxy( array( '10.0.0.0/8' ) );

		$this->assertSame(
			'first.example',
			$proxy->served_authority(
				array(
					'HTTP_HOST'             => 'lb.internal',
					'HTTP_X_FORWARDED_HOST' => 'first.example, second.example',
					'REMOTE_ADDR'           => '10.1.2.3',
				)
			)
		);
	}

	public function test_invalid_cidr_entries_are_dropped(): void {
		$proxy = new TrustedProxy( array( 'not-a-cidr', '10.0.0.0/8' ) );

		$this->assertSame( array( '10.0.0.0/8' ), $proxy->cidrs() );
	}

	public function test_a_missing_host_header_yields_an_empty_authority(): void {
		$this->assertSame( '', ( new TrustedProxy( array() ) )->served_authority( array() ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter TrustedProxyTest`
Expected: FAIL — `Error: Class "PostDomain\Support\TrustedProxy" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/TrustedProxy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Forwarded headers are honoured only from an allowlisted REMOTE_ADDR. There is
 * no filter that turns them on without an IP allowlist: that would be host
 * header injection with extra steps.
 */
final class TrustedProxy {

	/** @var string[] */
	private array $cidrs;

	/**
	 * @param string[] $cidrs
	 */
	public function __construct( array $cidrs ) {
		$this->cidrs = array();

		foreach ( $cidrs as $cidr ) {
			if ( is_string( $cidr ) && $this->is_valid_cidr( $cidr ) ) {
				$this->cidrs[] = $cidr;
			}
		}
	}

	/** @return string[] */
	public function cidrs(): array {
		return $this->cidrs;
	}

	/**
	 * @param array<string, mixed> $server
	 */
	public function served_authority( array $server ): string {
		$direct = isset( $server['HTTP_HOST'] ) ? (string) $server['HTTP_HOST'] : '';

		if ( array() === $this->cidrs ) {
			return $direct;
		}

		$remote = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';

		if ( ! $this->is_trusted( $remote ) ) {
			return $direct;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_HOST'] )
			? (string) $server['HTTP_X_FORWARDED_HOST']
			: '';

		if ( '' === $forwarded ) {
			return $direct;
		}

		$first = explode( ',', $forwarded )[0];

		return trim( $first );
	}

	private function is_valid_cidr( string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return false !== filter_var( $cidr, FILTER_VALIDATE_IP );
		}

		[ $subnet, $bits ] = explode( '/', $cidr, 2 );

		return false !== filter_var( $subnet, FILTER_VALIDATE_IP )
			&& 1 === preg_match( '/^[0-9]{1,3}$/', $bits );
	}

	private function is_trusted( string $address ): bool {
		if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( $this->cidrs as $cidr ) {
			if ( $this->in_range( $address, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	private function in_range( string $address, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return $address === $cidr;
		}

		[ $subnet, $bits ] = explode( '/', $cidr, 2 );

		$address_bin = inet_pton( $address );
		$subnet_bin  = inet_pton( $subnet );

		if ( false === $address_bin || false === $subnet_bin
			|| strlen( $address_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( substr( $address_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( 0xFF << ( 8 - $rest ) & 0xFF );

		return ( $address_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter TrustedProxyTest`
Expected: PASS — 6 tests

- [ ] **Step 5: Commit**

```bash
git add src/Support/TrustedProxy.php tests/unit/Support/TrustedProxyTest.php
git commit -m "Honour forwarded host headers only from allowlisted proxies

Default is to ignore them entirely. A forwarded value is parsed on exactly the
same terms as a direct one."
```

---

### Task 9: Prefixed build, static analysis, and CI

**Files:**
- Create: `scoper.inc.php`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.github/workflows/ci.yml`
- Modify: `composer.json` (add the `build` script)
- Test: `tests/unit/BuildConfigTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: the `composer build`, `composer lint`, and `composer analyse` commands, and the CI workflow every later plan relies on.

Vendor code is prefixed into `PostDomain\Vendor\` so the shipped plugin cannot
collide with, or be hijacked by, another plugin's autoloader (spec §18).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/BuildConfigTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BuildConfigTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_scoper_prefixes_into_the_plugin_namespace(): void {
		$config = (string) file_get_contents( $this->root() . '/scoper.inc.php' );

		$this->assertStringContainsString( "'prefix' => 'PostDomain\\\\Vendor'", $config );
	}

	public function test_the_idn_polyfill_is_pinned_exactly(): void {
		/** @var array{require: array<string, string>} $composer */
		$composer = json_decode( (string) file_get_contents( $this->root() . '/composer.json' ), true );

		$this->assertSame( '1.38.1', $composer['require']['symfony/polyfill-intl-idn'] );
	}

	public function test_the_composer_lock_is_committed(): void {
		$this->assertFileExists( $this->root() . '/composer.lock' );
	}

	public function test_phpstan_runs_at_level_8(): void {
		$config = (string) file_get_contents( $this->root() . '/phpstan.neon.dist' );

		$this->assertStringContainsString( 'level: 8', $config );
	}

	public function test_ci_runs_lint_analyse_and_both_test_suites(): void {
		$workflow = (string) file_get_contents( $this->root() . '/.github/workflows/ci.yml' );

		$this->assertStringContainsString( 'composer lint', $workflow );
		$this->assertStringContainsString( 'composer analyse', $workflow );
		$this->assertStringContainsString( 'composer test', $workflow );
		$this->assertStringContainsString( 'composer audit', $workflow );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter BuildConfigTest`
Expected: FAIL — `Failed asserting that file "…/scoper.inc.php" exists`

- [ ] **Step 3: Write minimal implementation**

Create `scoper.inc.php`:

```php
<?php
declare( strict_types = 1 );

return array(
	'prefix'  => 'PostDomain\\Vendor',
	'finders' => array(),
	'exclude-namespaces' => array( 'PostDomain' ),
);
```

Create `phpcs.xml.dist`:

```xml
<?xml version="1.0"?>
<ruleset name="post-domain">
	<file>src</file>
	<file>post-domain.php</file>
	<file>tests</file>
	<exclude-pattern>*/vendor/*</exclude-pattern>
	<rule ref="WordPress-Extra"/>
	<config name="minimum_supported_wp_version" value="6.4"/>
	<config name="testVersion" value="8.1-"/>
</ruleset>
```

Create `phpstan.neon.dist`:

```neon
includes:
	- vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
	level: 8
	paths:
		- src
		- post-domain.php
```

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  checks:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer audit
      - run: composer lint
      - run: composer analyse
      - run: composer test
      - run: npm ci
      - run: npx wp-env start
      - run: composer test:integration
```

Add to the `scripts` block in `composer.json`:

```json
		"build": "php-scoper add-prefix --output-dir=build/post-domain --force"
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter BuildConfigTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Run the whole suite and the checks**

Run: `composer lint && composer analyse && composer test`
Expected: PASS — no PHPCS errors, no PHPStan errors, all unit tests green

- [ ] **Step 6: Commit**

```bash
git add scoper.inc.php phpcs.xml.dist phpstan.neon.dist .github/workflows/ci.yml composer.json tests/unit/BuildConfigTest.php
git commit -m "Prefix vendor code and gate every push on lint, analysis, and tests

The shipped artifact is the prefixed build. An unprefixed vendor tree can be
hijacked by whichever plugin autoloads its copy of the same library first."
```

---

## Gate for Plan 01

All of the following pass before Plan 02 starts:

```bash
composer lint
composer analyse
composer test
composer test:integration
```

Plus: activating the plugin on a wp-env single-site install succeeds, and
`Activation::refusal()` returns the multisite message when `is_multisite()` is
true.
