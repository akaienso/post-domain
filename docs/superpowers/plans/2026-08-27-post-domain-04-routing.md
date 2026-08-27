# post-domain 04 — Routing contract, resolution, and query scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A mapped host resolves its subtree in both directions with a proven
round-trip invariant, ambiguous paths stay ambiguous, and no feed or sitemap can
emit a post from outside the subtree.

**Architecture:** One contract owns path logic in both directions, so a URL the
plugin emits and a URL it resolves cannot disagree. `QueryScope` is an
optimization only — membership is re-checked on every returned post, including
posts an explicit `post__in` supplied.

**Tech Stack:** As Plans 01–03.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–03, and add:

- **The round-trip invariant:** for every post where `belongs_to_mapping()` is
  true and `path_for_post()` is non-null,
  `resolve_path( $m, path_for_post( $m, $p ) )->post_id === $p->ID`. A post that
  cannot round-trip must return `null` from `path_for_post()` (spec §6).
- **Round-trip verification is mandatory.** There is no filter to disable it
  (spec §6.3).
- **Collisions are ambiguous, not arbitrated.** The shipped default arbitrates
  nothing (spec §6.2).
- **`QueryScope` is optimization only.** Every returned post is validated with
  `belongs_to_mapping()` before output (spec §10).
- **An unbounded scope never executes.** An empty `post__in` or
  `post_parent__in` short-circuits to an empty result and the query never runs
  (spec §10).
- **`CteSubtreeAdapter` ships disabled** until its capability matrix is confirmed
  against real target environments (spec §20, Task 9 below).

---

## File map

| File | Responsibility |
|---|---|
| `src/Contracts/RoutingContract.php` | `resolve_path`, `path_for_post`, `belongs_to_mapping` |
| `src/Routing/Resolution.php` | Readonly result of a successful walk |
| `src/Routing/PathNormalizer.php` | Segment decoding and rejection rules |
| `src/Routing/Subtree.php` | The default `post_parent` implementation of the contract |
| `src/Routing/AmbiguousPath.php` | Marker for more than one candidate |
| `src/Routing/RoundTripVerifier.php` | Mandatory generation-time check, memoized per request |
| `src/Routing/Resolver.php` | `parse_request`: decompose, resolve, promote query vars |
| `src/Routing/UnmatchedPolicy.php` | Redirect / 404 / passthrough, method-aware |
| `src/Routing/QueryScope.php` | Bounded scope value object |
| `src/Contracts/QueryScopeProvider.php` | Scope provider interface |
| `src/Routing/EnumerationScopeProvider.php` | The default bounded provider |
| `src/Routing/CteSubtreeAdapter.php` | Capability-gated recursive-CTE provider, shipped disabled |
| `src/Routing/DatabaseCapability.php` | The probe that decides whether the adapter may run |
| `src/Routing/MembershipFilter.php` | Post-query membership enforcement for feeds and sitemaps |

---

### Task 1: Path normalization

**Files:**
- Create: `src/Routing/PathNormalizer.php`
- Test: `tests/unit/Routing/PathNormalizerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PostDomain\Routing\PathNormalizer::segments( string $base ): ?string[]` — decoded segments, or `null` when the path must not resolve.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Routing/PathNormalizerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PostDomain\Routing\PathNormalizer;

final class PathNormalizerTest extends TestCase {

	private function segments( string $path ): ?array {
		return ( new PathNormalizer() )->segments( $path );
	}

	public function test_a_simple_path_splits(): void {
		$this->assertSame( array( 'events', 'gala' ), $this->segments( 'events/gala' ) );
	}

	public function test_leading_and_trailing_slashes_are_stripped(): void {
		$this->assertSame( array( 'events', 'gala' ), $this->segments( '/events/gala/' ) );
	}

	public function test_repeated_slashes_collapse(): void {
		$this->assertSame( array( 'events', 'gala' ), $this->segments( 'events///gala' ) );
	}

	public function test_percent_encoded_segments_decode(): void {
		$this->assertSame( array( 'café' ), $this->segments( 'caf%C3%A9' ) );
	}

	public function test_an_encoded_slash_is_rejected(): void {
		$this->assertNull( $this->segments( 'events%2Fgala' ), 'an encoded separator is never a separator' );
	}

	public function test_an_encoded_backslash_is_rejected(): void {
		$this->assertNull( $this->segments( 'events%5Cgala' ) );
	}

	public function test_dot_segments_are_rejected(): void {
		$this->assertNull( $this->segments( 'events/./gala' ) );
		$this->assertNull( $this->segments( 'events/../gala' ) );
	}

	public function test_the_root_is_an_empty_segment_list(): void {
		$this->assertSame( array(), $this->segments( '' ) );
		$this->assertSame( array(), $this->segments( '/' ) );
	}

	public function test_trailing_empty_segments_collapse(): void {
		$this->assertSame( array( 'events' ), $this->segments( 'events//' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter PathNormalizerTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\PathNormalizer" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/PathNormalizer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class PathNormalizer {

	/**
	 * @return string[]|null Null means the path must not resolve.
	 */
	public function segments( string $base ): ?array {
		if ( str_contains( strtolower( $base ), '%2f' ) || str_contains( strtolower( $base ), '%5c' ) ) {
			return null;
		}

		$parts    = preg_split( '~/+~', trim( $base, '/' ) ) ?: array();
		$segments = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$decoded = rawurldecode( $part );

			if ( '.' === $decoded || '..' === $decoded ) {
				return null;
			}

			$segments[] = \Normalizer::isNormalized( $decoded )
				? $decoded
				: (string) \Normalizer::normalize( $decoded );
		}

		return $segments;
	}
}
```

If `ext-intl` is absent the `Normalizer` class does not exist, and the bundled
polyfill supplies it: add `symfony/polyfill-intl-normalizer` to `composer.json`
`require` and run `composer update symfony/polyfill-intl-normalizer`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter PathNormalizerTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/PathNormalizer.php composer.json composer.lock tests/unit/Routing/PathNormalizerTest.php
git commit -m "Normalize subtree paths and reject what must never resolve

An encoded separator is never a separator, and a dot segment has no meaning in a
subtree walk."
```

---

### Task 2: The routing contract and the default subtree walk

**Files:**
- Create: `src/Contracts/RoutingContract.php`, `src/Routing/Resolution.php`, `src/Routing/Subtree.php`
- Test: `tests/integration/Routing/SubtreeTest.php`

**Interfaces:**
- Consumes: `PathNormalizer` (Task 1), `ServingContext` (Plan 03).
- Produces:
  - `PostDomain\Contracts\RoutingContract` with `resolve_path( ServingContext $c, string $path ): ?Resolution`, `path_for_post( ServingContext $c, \WP_Post $post ): ?string`, `belongs_to_mapping( ServingContext $c, \WP_Post $post ): bool`.
  - `PostDomain\Routing\Resolution` — readonly `int $post_id`, `string $post_type`, `int $depth`, `string $canonical_path`.
  - `PostDomain\Routing\Subtree` implementing the contract over `post_parent`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/SubtreeTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class SubtreeTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Subtree $subtree;

	public function set_up(): void {
		parent::set_up();
		$this->subtree = new Subtree( new PathNormalizer() );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_path_segment_for_post' );
		remove_all_filters( 'pd_subtree_children' );
		parent::tear_down();
	}

	public function test_the_root_path_resolves_to_the_mapped_post(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );

		$resolution = $this->subtree->resolve_path( $context, '' );

		$this->assertSame( $root, $resolution?->post_id );
		$this->assertSame( 0, $resolution?->depth );
	}

	public function test_a_child_resolves_one_level_down(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );

		$this->assertSame( $child, $this->subtree->resolve_path( $context, 'events' )?->post_id );
	}

	public function test_a_grandchild_resolves_two_levels_down(): void {
		$root       = $this->make_page( 'club', 0 );
		$child      = $this->make_page( 'events', $root );
		$grandchild = $this->make_page( 'gala', $child );
		$context    = $this->serving_context( $root );

		$resolution = $this->subtree->resolve_path( $context, 'events/gala' );

		$this->assertSame( $grandchild, $resolution?->post_id );
		$this->assertSame( 2, $resolution?->depth );
	}

	public function test_a_post_outside_the_subtree_does_not_resolve(): void {
		$root    = $this->make_page( 'club', 0 );
		$this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->subtree->resolve_path( $context, 'about-us' ) );
	}

	public function test_an_unpublished_child_does_not_resolve(): void {
		$root    = $this->make_page( 'club', 0 );
		$this->make_page( 'draft-event', $root, 'draft' );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->subtree->resolve_path( $context, 'draft-event' ) );
	}

	public function test_the_depth_cap_stops_the_walk(): void {
		$root   = $this->make_page( 'club', 0 );
		$parent = $root;
		$path   = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$parent = $this->make_page( "level-{$i}", $parent );
			$path[] = "level-{$i}";
		}

		$context = $this->serving_context( $root, array( 'max_depth' => 3 ) );

		$this->assertNull( $this->subtree->resolve_path( $context, implode( '/', $path ) ) );
	}

	public function test_path_for_post_walks_back_up(): void {
		$root       = $this->make_page( 'club', 0 );
		$child      = $this->make_page( 'events', $root );
		$grandchild = $this->make_page( 'gala', $child );
		$context    = $this->serving_context( $root );

		$this->assertSame( 'events/gala', $this->subtree->path_for_post( $context, get_post( $grandchild ) ) );
		$this->assertSame( '', $this->subtree->path_for_post( $context, get_post( $root ) ) );
	}

	public function test_path_for_post_is_null_outside_the_subtree(): void {
		$root    = $this->make_page( 'club', 0 );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->subtree->path_for_post( $context, get_post( $outside ) ) );
	}

	public function test_belongs_to_mapping(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertTrue( $this->subtree->belongs_to_mapping( $context, get_post( $child ) ) );
		$this->assertFalse( $this->subtree->belongs_to_mapping( $context, get_post( $outside ) ) );
	}

	public function test_the_segment_filter_changes_both_directions(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );

		add_filter(
			'pd_path_segment_for_post',
			static fn( string $segment, \WP_Post $post ): string => 'x-' . $segment
		);

		$this->assertSame( 'x-events', $this->subtree->path_for_post( $context, get_post( $child ) ) );
		$this->assertSame( $child, $this->subtree->resolve_path( $context, 'x-events' )?->post_id );
	}

	public function test_the_round_trip_invariant_holds_over_a_generated_tree(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$ids     = array( $root );
		$parent  = $root;

		for ( $level = 0; $level < 4; $level++ ) {
			$parent = $this->make_page( "branch-{$level}", $parent );
			$ids[]  = $parent;

			for ( $leaf = 0; $leaf < 3; $leaf++ ) {
				$ids[] = $this->make_page( "leaf-{$level}-{$leaf}", $parent );
			}
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$path = $this->subtree->path_for_post( $context, $post );

			$this->assertNotNull( $path, "post {$id} belongs to the subtree and must have a path" );
			$this->assertSame(
				$id,
				$this->subtree->resolve_path( $context, $path )?->post_id,
				"round trip failed for post {$id} at path '{$path}'"
			);
		}
	}
}
```

Create the shared fixture trait `tests/integration/ServingContextFactory.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\Representation;
use PostDomain\Routing\ServingContext;

trait ServingContextFactory {

	protected function make_page( string $slug, int $parent, string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $status,
				'post_name'   => $slug,
				'post_parent' => $parent,
				'post_title'  => $slug,
			)
		);
	}

	/**
	 * @param array{max_depth?: int, host?: string} $overrides
	 */
	protected function serving_context( int $root_id, array $overrides = array() ): ServingContext {
		$host = $overrides['host'] ?? 'mapped.test';

		$mapping = new Mapping(
			1, $host, null, $root_id, 1,
			VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
			null, str_repeat( 'a', 32 ), '_post-domain-challenge'
		);

		return new ServingContext(
			$mapping,
			$host,
			$host,
			true,
			$root_id,
			array( 'page' ),
			array( 'publish' ),
			$overrides['max_depth'] ?? 10,
			array( 'paged', 'page', 'cpage', 'replytocom', 'feed', 'embed' ),
			null,
			Representation::HTML
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter SubtreeTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\Subtree" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/Resolution.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class Resolution {

	public function __construct(
		public readonly int $post_id,
		public readonly string $post_type,
		public readonly int $depth,
		public readonly string $canonical_path
	) {}
}
```

Create `src/Contracts/RoutingContract.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Routing\Resolution;
use PostDomain\Routing\ServingContext;

interface RoutingContract {

	public function resolve_path( ServingContext $context, string $path ): ?Resolution;

	public function path_for_post( ServingContext $context, \WP_Post $post ): ?string;

	public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool;
}
```

Create `src/Routing/Subtree.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

/**
 * Both directions live here against one filter set, so a URL the plugin emits
 * and a URL it resolves cannot disagree.
 */
final class Subtree implements RoutingContract {

	public function __construct( private readonly PathNormalizer $normalizer ) {}

	public function resolve_path( ServingContext $context, string $path ): ?Resolution {
		/** @var Resolution|null $short_circuit */
		$short_circuit = apply_filters( 'pd_resolve_path', null, $path, $context->mapping );

		if ( $short_circuit instanceof Resolution ) {
			return $short_circuit;
		}

		$segments = $this->normalizer->segments( $path );

		if ( null === $segments ) {
			return null;
		}

		if ( count( $segments ) > $context->max_depth ) {
			return null;
		}

		$current = get_post( $context->effective_post_id );

		if ( null === $current ) {
			return null;
		}

		$depth = 0;

		foreach ( $segments as $segment ) {
			$candidates = array();

			foreach ( $this->children_of( $context, $current ) as $child ) {
				if ( $this->segment_for( $context, $child ) === $segment ) {
					$candidates[] = $child;
				}
			}

			if ( 1 !== count( $candidates ) ) {
				// Zero is a miss; more than one is ambiguous, and ambiguous is a miss.
				return null;
			}

			$current = $candidates[0];
			++$depth;
		}

		return new Resolution(
			$current->ID,
			$current->post_type,
			$depth,
			implode( '/', $segments )
		);
	}

	public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
		/** @var string|null $short_circuit */
		$short_circuit = apply_filters( 'pd_path_for_post', null, $post, $context->mapping );

		if ( is_string( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( $post->ID === $context->effective_post_id ) {
			return '';
		}

		$segments = array();
		$current  = $post;

		for ( $i = 0; $i < $context->max_depth; $i++ ) {
			if ( ! in_array( $current->post_type, $context->subtree_post_types, true )
				|| ! in_array( $current->post_status, $context->post_statuses, true ) ) {
				return null;
			}

			array_unshift( $segments, $this->segment_for( $context, $current ) );

			$parent_id = (int) $current->post_parent;

			if ( $parent_id === $context->effective_post_id ) {
				return implode( '/', $segments );
			}

			if ( 0 === $parent_id ) {
				return null;
			}

			$parent = get_post( $parent_id );

			if ( null === $parent ) {
				return null;
			}

			$current = $parent;
		}

		return null;
	}

	public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
		/** @var bool|null $short_circuit */
		$short_circuit = apply_filters( 'pd_belongs_to_mapping', null, $post, $context->mapping );

		if ( is_bool( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( $post->ID === $context->effective_post_id ) {
			return true;
		}

		return null !== $this->path_for_post( $context, $post );
	}

	/** @return \WP_Post[] */
	private function children_of( ServingContext $context, \WP_Post $parent ): array {
		/** @var \WP_Post[]|null $supplied */
		$supplied = apply_filters( 'pd_subtree_children', null, $parent, $context->mapping );

		if ( is_array( $supplied ) ) {
			return $supplied;
		}

		return get_posts(
			array(
				'post_parent'      => $parent->ID,
				'post_type'        => $context->subtree_post_types,
				'post_status'      => $context->post_statuses,
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	private function segment_for( ServingContext $context, \WP_Post $post ): string {
		return (string) apply_filters(
			'pd_path_segment_for_post',
			(string) $post->post_name,
			$post,
			$context->mapping
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter SubtreeTest`
Expected: PASS — 11 tests, including the round-trip property over 17 posts

- [ ] **Step 5: Commit**

```bash
git add src/Contracts/RoutingContract.php src/Routing/Resolution.php src/Routing/Subtree.php tests/integration/ServingContextFactory.php tests/integration/Routing/SubtreeTest.php
git commit -m "Resolve and generate subtree paths behind one contract

The round-trip invariant is asserted as a property over a generated tree rather
than a handful of examples, because it is the one guarantee everything else
depends on."
```

---

### Task 3: Collisions stay ambiguous

**Files:**
- Modify: `src/Routing/Subtree.php` (ambiguity reporting and the arbitration filter)
- Create: `src/Routing/AmbiguousPath.php`
- Test: `tests/integration/Routing/CollisionTest.php`

**Interfaces:**
- Consumes: Task 2's `Subtree`.
- Produces: `PostDomain\Routing\AmbiguousPath::record( int $mapping_id, string $segment, int[] $candidates ): void` and `::all(): array`, plus the `pd_resolve_ambiguity` filter hook point.

When more than one candidate matches, `resolve_path()` returns `null`,
`path_for_post()` returns `null` for **every** colliding candidate, and
diagnostics list it (spec §6.2).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/CollisionTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\AmbiguousPath;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class CollisionTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Subtree $subtree;

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'pd_event', array( 'public' => true, 'hierarchical' => true ) );
		$this->subtree = new Subtree( new PathNormalizer() );
		AmbiguousPath::reset();
	}

	public function tear_down(): void {
		unregister_post_type( 'pd_event' );
		remove_all_filters( 'pd_resolve_ambiguity' );
		parent::tear_down();
	}

	private function collide(): array {
		$root = $this->make_page( 'club', 0 );

		$page = $this->make_page( 'gala', $root );

		$event = self::factory()->post->create(
			array(
				'post_type'   => 'pd_event',
				'post_status' => 'publish',
				'post_name'   => 'gala',
				'post_parent' => $root,
			)
		);

		$context = $this->serving_context( $root );
		$context = new \PostDomain\Routing\ServingContext(
			$context->mapping,
			$context->requested_host,
			$context->canonical_host,
			true,
			$root,
			array( 'page', 'pd_event' ),
			array( 'publish' ),
			10,
			$context->preserved_query_vars
		);

		return array( $context, $page, $event );
	}

	public function test_an_ambiguous_segment_does_not_resolve(): void {
		[ $context ] = $this->collide();

		$this->assertNull( $this->subtree->resolve_path( $context, 'gala' ) );
	}

	public function test_every_colliding_candidate_loses_its_path(): void {
		[ $context, $page, $event ] = $this->collide();

		$this->assertNull( $this->subtree->path_for_post( $context, get_post( $page ) ) );
		$this->assertNull( $this->subtree->path_for_post( $context, get_post( $event ) ) );
	}

	public function test_the_collision_is_recorded_for_diagnostics(): void {
		[ $context ] = $this->collide();
		$this->subtree->resolve_path( $context, 'gala' );

		$collisions = AmbiguousPath::all();

		$this->assertCount( 1, $collisions );
		$this->assertSame( 'gala', $collisions[0]['segment'] );
		$this->assertCount( 2, $collisions[0]['candidates'] );
	}

	public function test_an_integrator_may_arbitrate(): void {
		[ $context, $page ] = $this->collide();

		add_filter(
			'pd_resolve_ambiguity',
			static fn( $winner, $mapping, array $candidates ): \WP_Post => $candidates[0]
		);

		$this->assertSame( $page, $this->subtree->resolve_path( $context, 'gala' )?->post_id );
	}

	public function test_the_shipped_default_arbitrates_nothing(): void {
		[ $context ] = $this->collide();

		$this->assertNull(
			apply_filters( 'pd_resolve_ambiguity', null, $context->mapping, array(), 'gala' )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CollisionTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\AmbiguousPath" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/AmbiguousPath.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Per-request record of collisions, for diagnostics. Not persisted: a collision
 * is a property of the current content tree, not history.
 */
final class AmbiguousPath {

	/** @var array<int, array{mapping_id: int, segment: string, candidates: int[]}> */
	private static array $records = array();

	/** @param int[] $candidates */
	public static function record( int $mapping_id, string $segment, array $candidates ): void {
		self::$records[] = array(
			'mapping_id' => $mapping_id,
			'segment'    => $segment,
			'candidates' => $candidates,
		);
	}

	/** @return array<int, array{mapping_id: int, segment: string, candidates: int[]}> */
	public static function all(): array {
		return self::$records;
	}

	public static function reset(): void {
		self::$records = array();
	}
}
```

In `src/Routing/Subtree.php`, replace the candidate-count branch inside
`resolve_path()` with:

```php
			if ( count( $candidates ) > 1 ) {
				AmbiguousPath::record(
					$context->mapping->id,
					$segment,
					array_map( static fn( \WP_Post $p ): int => $p->ID, $candidates )
				);

				/** @var \WP_Post|null $winner */
				$winner = apply_filters( 'pd_resolve_ambiguity', null, $context->mapping, $candidates, $segment );

				if ( ! $winner instanceof \WP_Post ) {
					return null;
				}

				$candidates = array( $winner );
			}

			if ( array() === $candidates ) {
				return null;
			}
```

And in `path_for_post()`, immediately before `array_unshift(...)`, add the
sibling-collision check:

```php
			if ( $this->has_colliding_sibling( $context, $current ) ) {
				return null;
			}
```

with:

```php
	private function has_colliding_sibling( ServingContext $context, \WP_Post $post ): bool {
		$parent = 0 === (int) $post->post_parent ? null : get_post( (int) $post->post_parent );

		if ( null === $parent ) {
			return false;
		}

		$segment = $this->segment_for( $context, $post );
		$matches = 0;

		foreach ( $this->children_of( $context, $parent ) as $sibling ) {
			if ( $this->segment_for( $context, $sibling ) === $segment ) {
				++$matches;
			}
		}

		return $matches > 1;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CollisionTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/AmbiguousPath.php src/Routing/Subtree.php tests/integration/Routing/CollisionTest.php
git commit -m "Leave colliding segments unresolved rather than picking a winner

Every colliding candidate falls back to its primary-host permalink. A
wrong-but-consistent URL is worse than an honest fallback, and diagnostics list
the collision so it can be fixed."
```

---

### Task 4: Mandatory round-trip verification

**Files:**
- Create: `src/Routing/RoundTripVerifier.php`
- Test: `tests/integration/Routing/RoundTripVerifierTest.php`

**Interfaces:**
- Consumes: `RoutingContract` (Task 2).
- Produces: `PostDomain\Routing\RoundTripVerifier::__construct( RoutingContract $routing )` and `::verified_path( ServingContext $c, \WP_Post $post ): ?string` — the path only when it round-trips, memoized per request on `mapping_id : effective_root_id : post_id`.

There is no filter to disable this (spec §6.3). Plan 05's URL adapters consume it.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/RoundTripVerifierTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Contracts\RoutingContract;
use PostDomain\Routing\Resolution;
use PostDomain\Routing\RoundTripVerifier;
use PostDomain\Routing\ServingContext;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class RoundTripVerifierTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_a_path_that_round_trips_is_returned(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		$verifier = new RoundTripVerifier(
			new \PostDomain\Routing\Subtree( new \PostDomain\Routing\PathNormalizer() )
		);

		$this->assertSame(
			'events',
			$verifier->verified_path( $this->serving_context( $root ), get_post( $child ) )
		);
	}

	public function test_a_path_that_does_not_round_trip_is_rejected(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		$liar = new class() implements RoutingContract {
			public function resolve_path( ServingContext $context, string $path ): ?Resolution {
				return null;
			}

			public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
				return 'a-path-that-resolves-to-nothing';
			}

			public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
				return true;
			}
		};

		$this->assertNull(
			( new RoundTripVerifier( $liar ) )->verified_path( $this->serving_context( $root ), get_post( $child ) ),
			'a path the resolver would not accept must never be emitted'
		);
	}

	public function test_a_path_resolving_to_a_different_post_is_rejected(): void {
		$root  = $this->make_page( 'club', 0 );
		$one   = $this->make_page( 'one', $root );
		$two   = $this->make_page( 'two', $root );

		$crossed = new class( $one ) implements RoutingContract {
			public function __construct( private readonly int $always ) {}

			public function resolve_path( ServingContext $context, string $path ): ?Resolution {
				return new Resolution( $this->always, 'page', 1, $path );
			}

			public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
				return 'two';
			}

			public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
				return true;
			}
		};

		$this->assertNull(
			( new RoundTripVerifier( $crossed ) )->verified_path( $this->serving_context( $root ), get_post( $two ) )
		);
	}

	public function test_the_result_is_memoized_within_one_request(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		$counter = new class() implements RoutingContract {
			public int $calls = 0;

			public function resolve_path( ServingContext $context, string $path ): ?Resolution {
				return new Resolution( $context->effective_post_id, 'page', 0, $path );
			}

			public function path_for_post( ServingContext $context, \WP_Post $post ): ?string {
				++$this->calls;

				return '';
			}

			public function belongs_to_mapping( ServingContext $context, \WP_Post $post ): bool {
				return true;
			}
		};

		$verifier = new RoundTripVerifier( $counter );
		$context  = $this->serving_context( $root );
		$post     = get_post( $root );

		$verifier->verified_path( $context, $post );
		$verifier->verified_path( $context, $post );
		$verifier->verified_path( $context, $post );

		$this->assertSame( 1, $counter->calls, 'a pure function inside one request is memoized once' );
		unset( $child );
	}

	public function test_the_memo_key_separates_mappings(): void {
		$root_a = $this->make_page( 'club-a', 0 );
		$root_b = $this->make_page( 'club-b', 0 );
		$child  = $this->make_page( 'events', $root_a );

		$verifier = new RoundTripVerifier(
			new \PostDomain\Routing\Subtree( new \PostDomain\Routing\PathNormalizer() )
		);

		$this->assertSame(
			'events',
			$verifier->verified_path( $this->serving_context( $root_a, array( 'host' => 'a.test' ) ), get_post( $child ) )
		);
		$this->assertNull(
			$verifier->verified_path( $this->serving_context( $root_b, array( 'host' => 'b.test' ) ), get_post( $child ) ),
			'the same post has a different answer under a different mapping'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter RoundTripVerifierTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\RoundTripVerifier" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/RoundTripVerifier.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

/**
 * A memo of a pure function within one request, not a cache: there is no window
 * in which the inputs change and the stored answer persists.
 */
final class RoundTripVerifier {

	/** @var array<string, string|null> */
	private array $memo = array();

	public function __construct( private readonly RoutingContract $routing ) {}

	public function verified_path( ServingContext $context, \WP_Post $post ): ?string {
		$key = sprintf( '%d:%d:%d', $context->mapping->id, $context->effective_post_id, $post->ID );

		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$path = $this->routing->path_for_post( $context, $post );

		if ( null === $path ) {
			return $this->memo[ $key ] = null;
		}

		$resolved = $this->routing->resolve_path( $context, $path );

		return $this->memo[ $key ] = ( null !== $resolved && $resolved->post_id === $post->ID ) ? $path : null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter RoundTripVerifierTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/RoundTripVerifier.php tests/integration/Routing/RoundTripVerifierTest.php
git commit -m "Verify every generated path resolves back to its own post

Mandatory, with no opt-out. The memo is keyed on mapping, root, and post because
the same post has a different answer under a different mapping."
```

---

### Task 5: The resolver and the unmatched policy

**Files:**
- Create: `src/Routing/Resolver.php`, `src/Routing/UnmatchedPolicy.php`
- Test: `tests/integration/Routing/ResolverTest.php`

**Interfaces:**
- Consumes: `PathDecomposer` (Plan 03), `RoutingContract` (Task 2), `QueryVarPolicy` (Plan 03).
- Produces:
  - `PostDomain\Routing\Resolver::__construct( RoutingContract $routing, PathDecomposer $decomposer )` and `::resolve( ServingContext $c, \WP $wp ): ?Resolution` — mutates `$wp->query_vars` on a hit.
  - `PostDomain\Routing\UnmatchedPolicy::__construct( string $mode, string $primary_origin )` and `::response_for( string $method, string $request_uri ): ?array{url?: string, status: int}`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/ResolverTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\PathDecomposer;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Resolver;
use PostDomain\Routing\Subtree;
use PostDomain\Routing\UnmatchedPolicy;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class ResolverTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Resolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new Resolver(
			new Subtree( new PathNormalizer() ),
			new PathDecomposer()
		);
	}

	private function wp( string $request ): \WP {
		$wp          = new \WP();
		$wp->request = trim( $request, '/' );
		$wp->query_vars = array();

		return $wp;
	}

	public function test_the_root_sets_the_mapped_post(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/' );

		$this->assertSame( $root, $this->resolver->resolve( $context, $wp )?->post_id );
		$this->assertSame( $root, (int) $wp->query_vars['page_id'] );
	}

	public function test_a_descendant_sets_its_own_id(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/events/' );

		$this->assertSame( $child, $this->resolver->resolve( $context, $wp )?->post_id );
		$this->assertSame( $child, (int) $wp->query_vars['page_id'] );
	}

	public function test_a_descendant_feed_sets_the_feed_var(): void {
		$root    = $this->make_page( 'club', 0 );
		$child   = $this->make_page( 'events', $root );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/events/feed/atom/' );

		$this->assertSame( $child, $this->resolver->resolve( $context, $wp )?->post_id );
		$this->assertSame( 'atom', $wp->query_vars['feed'] );
	}

	public function test_pagination_is_promoted(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/page/3/' );

		$this->resolver->resolve( $context, $wp );

		$this->assertSame( 3, (int) $wp->query_vars['paged'] );
	}

	public function test_only_allowlisted_vars_are_promoted(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );
		$wp      = $this->wp( '/' );

		$wp->query_vars = array( 'preview' => 'true', 'name' => 'evil', 'paged' => '2' );

		$this->resolver->resolve( $context, $wp );

		$this->assertArrayNotHasKey( 'preview', $wp->query_vars );
		$this->assertArrayNotHasKey( 'name', $wp->query_vars );
		$this->assertSame( '2', $wp->query_vars['paged'] );
	}

	public function test_a_miss_returns_null(): void {
		$root    = $this->make_page( 'club', 0 );
		$this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$this->assertNull( $this->resolver->resolve( $context, $this->wp( '/about-us/' ) ) );
	}

	public function test_unmatched_get_redirects_temporarily_with_the_query_intact(): void {
		$policy   = new UnmatchedPolicy( 'redirect', 'https://primary.test' );
		$response = $policy->response_for( 'GET', '/about-us/?utm_source=news' );

		$this->assertSame( 302, $response['status'] );
		$this->assertSame( 'https://primary.test/about-us/?utm_source=news', $response['url'] );
	}

	public function test_unmatched_post_is_404_rather_than_a_cross_host_bounce(): void {
		$response = ( new UnmatchedPolicy( 'redirect', 'https://primary.test' ) )
			->response_for( 'POST', '/about-us/' );

		$this->assertSame( 404, $response['status'] );
		$this->assertArrayNotHasKey( 'url', $response );
	}

	public function test_the_404_mode_never_redirects(): void {
		$this->assertSame(
			404,
			( new UnmatchedPolicy( '404', 'https://primary.test' ) )->response_for( 'GET', '/x/' )['status']
		);
	}

	public function test_the_passthrough_mode_returns_null(): void {
		$this->assertNull(
			( new UnmatchedPolicy( 'passthrough', 'https://primary.test' ) )->response_for( 'GET', '/x/' )
		);
	}

	public function test_an_unrecognised_mode_falls_back_to_redirect(): void {
		$this->assertSame(
			302,
			( new UnmatchedPolicy( 'nonsense', 'https://primary.test' ) )->response_for( 'GET', '/x/' )['status']
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter ResolverTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\Resolver" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/UnmatchedPolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class UnmatchedPolicy {

	public const MODES = array( 'redirect', '404', 'passthrough' );

	private string $mode;

	public function __construct( string $mode, private readonly string $primary_origin ) {
		$this->mode = in_array( $mode, self::MODES, true ) ? $mode : 'redirect';
	}

	/**
	 * @return array{url?: string, status: int}|null
	 */
	public function response_for( string $method, string $request_uri ): ?array {
		if ( 'passthrough' === $this->mode ) {
			return null;
		}

		if ( '404' === $this->mode ) {
			return array( 'status' => 404 );
		}

		if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			// A POST is never bounced across hosts.
			return array( 'status' => 404 );
		}

		return array(
			'url'    => rtrim( $this->primary_origin, '/' ) . '/' . ltrim( $request_uri, '/' ),
			'status' => 302,
		);
	}
}
```

Create `src/Routing/Resolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

final class Resolver {

	public function __construct(
		private readonly RoutingContract $routing,
		private readonly PathDecomposer $decomposer
	) {}

	public function resolve( ServingContext $context, \WP $wp ): ?Resolution {
		$decomposition = $this->decomposer->decompose( (string) $wp->request );
		$resolution    = $this->routing->resolve_path( $context, $decomposition->base );

		if ( null === $resolution ) {
			return null;
		}

		$preserved = array();

		foreach ( $context->preserved_query_vars as $var ) {
			if ( isset( $wp->query_vars[ $var ] ) ) {
				$preserved[ $var ] = $wp->query_vars[ $var ];
			}
		}

		$vars = $preserved;

		if ( 'page' === $resolution->post_type ) {
			$vars['page_id'] = $resolution->post_id;
		} else {
			$vars['p']         = $resolution->post_id;
			$vars['post_type'] = $resolution->post_type;
		}

		if ( Representation::FEED === $decomposition->rep ) {
			$vars['feed'] = $decomposition->feed_type ?? 'feed';
		}

		if ( Representation::EMBED === $decomposition->rep ) {
			$vars['embed'] = true;
		}

		if ( null !== $decomposition->paged ) {
			$vars['paged'] = $decomposition->paged;
		}

		if ( null !== $decomposition->comment_page ) {
			$vars['cpage'] = $decomposition->comment_page;
		}

		$wp->query_vars = $vars;

		return $resolution;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter ResolverTest`
Expected: PASS — 11 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/Resolver.php src/Routing/UnmatchedPolicy.php tests/integration/Routing/ResolverTest.php
git commit -m "Resolve mapped requests and handle misses without bouncing a POST

Query vars are replaced with an allowlisted set plus the resolved post, so an
unrecognised var cannot turn a subtree page into an archive."
```

---

### Task 6: Bounded query scope with membership enforcement

**Files:**
- Create: `src/Routing/QueryScope.php`, `src/Contracts/QueryScopeProvider.php`, `src/Routing/EnumerationScopeProvider.php`, `src/Routing/MembershipFilter.php`
- Test: `tests/integration/Routing/QueryScopeTest.php`

**Interfaces:**
- Consumes: `RoutingContract` (Task 2).
- Produces:
  - `PostDomain\Routing\QueryScope` — readonly `bool $is_bounded`, `?int[] $post__in`, `?int[] $post_parent__in`, `array $query_args`; plus `::unbounded(): self` and `::of_ids( int[] $ids ): self`.
  - `PostDomain\Contracts\QueryScopeProvider::scope( ServingContext $c ): QueryScope`.
  - `PostDomain\Routing\EnumerationScopeProvider::__construct( RoutingContract $r, int $limit )`.
  - `PostDomain\Routing\MembershipFilter::__construct( RoutingContract $r )` and `::keep_members( \WP_Post[] $posts, ServingContext $c ): \WP_Post[]`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/QueryScopeTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\EnumerationScopeProvider;
use PostDomain\Routing\MembershipFilter;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\QueryScope;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class QueryScopeTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private Subtree $subtree;

	public function set_up(): void {
		parent::set_up();
		$this->subtree = new Subtree( new PathNormalizer() );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_query_scope' );
		parent::tear_down();
	}

	public function test_the_enumeration_provider_bounds_a_small_subtree(): void {
		$root  = $this->make_page( 'club', 0 );
		$one   = $this->make_page( 'one', $root );
		$two   = $this->make_page( 'two', $root );
		$scope = ( new EnumerationScopeProvider( $this->subtree, 500 ) )->scope( $this->serving_context( $root ) );

		$this->assertTrue( $scope->is_bounded );
		$this->assertEqualsCanonicalizing( array( $root, $one, $two ), $scope->post__in );
	}

	public function test_every_scope_ignores_sticky_posts(): void {
		$root  = $this->make_page( 'club', 0 );
		$scope = ( new EnumerationScopeProvider( $this->subtree, 500 ) )->scope( $this->serving_context( $root ) );

		$this->assertTrue( $scope->query_args['ignore_sticky_posts'] );
	}

	public function test_a_subtree_over_the_limit_is_unbounded_and_therefore_empty(): void {
		$root = $this->make_page( 'club', 0 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_page( "child-{$i}", $root );
		}

		$scope = ( new EnumerationScopeProvider( $this->subtree, 3 ) )->scope( $this->serving_context( $root ) );

		$this->assertFalse( $scope->is_bounded );
	}

	public function test_an_empty_inclusion_array_short_circuits(): void {
		$scope = QueryScope::of_ids( array() );

		$this->assertFalse(
			$scope->is_bounded,
			'an empty post__in is ignored by WP_Query, which turns nothing-matches into everything-matches'
		);
	}

	public function test_membership_is_enforced_over_supplied_ids(): void {
		$root    = $this->make_page( 'club', 0 );
		$inside  = $this->make_page( 'events', $root );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		$kept = ( new MembershipFilter( $this->subtree ) )->keep_members(
			array( get_post( $inside ), get_post( $outside ) ),
			$context
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( $inside, $kept[0]->ID );
	}

	public function test_a_filter_supplied_scope_is_still_membership_checked(): void {
		$root    = $this->make_page( 'club', 0 );
		$outside = $this->make_page( 'about-us', 0 );
		$context = $this->serving_context( $root );

		add_filter( 'pd_query_scope', static fn(): QueryScope => QueryScope::of_ids( array( $outside ) ) );

		$kept = ( new MembershipFilter( $this->subtree ) )->keep_members( array( get_post( $outside ) ), $context );

		$this->assertSame( array(), $kept, 'post__in scopes are validated too' );
	}

	public function test_a_non_scope_filter_return_is_replaced_with_unbounded(): void {
		$root = $this->make_page( 'club', 0 );

		add_filter( 'pd_query_scope', static fn(): string => 'nonsense' );

		$scope = ( new EnumerationScopeProvider( $this->subtree, 500 ) )->scope( $this->serving_context( $root ) );

		$this->assertFalse( $scope->is_bounded );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter QueryScopeTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\QueryScope" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Routing/QueryScope.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class QueryScope {

	/**
	 * @param int[]|null           $post__in
	 * @param int[]|null           $post_parent__in
	 * @param array<string, mixed> $query_args
	 */
	public function __construct(
		public readonly bool $is_bounded,
		public readonly ?array $post__in,
		public readonly ?array $post_parent__in,
		public readonly array $query_args
	) {}

	public static function unbounded(): self {
		return new self( false, null, null, array( 'ignore_sticky_posts' => true ) );
	}

	/** @param int[] $ids */
	public static function of_ids( array $ids ): self {
		if ( array() === $ids ) {
			// An empty inclusion array is silently ignored by WP_Query.
			return self::unbounded();
		}

		return new self( true, array_values( $ids ), null, array( 'ignore_sticky_posts' => true ) );
	}
}
```

Create `src/Contracts/QueryScopeProvider.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Routing\QueryScope;
use PostDomain\Routing\ServingContext;

interface QueryScopeProvider {

	public function scope( ServingContext $context ): QueryScope;
}
```

Create `src/Routing/EnumerationScopeProvider.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\QueryScopeProvider;
use PostDomain\Contracts\RoutingContract;

final class EnumerationScopeProvider implements QueryScopeProvider {

	public function __construct(
		private readonly RoutingContract $routing,
		private readonly int $limit
	) {}

	public function scope( ServingContext $context ): QueryScope {
		/** @var mixed $supplied */
		$supplied = apply_filters( 'pd_query_scope', null, $context->mapping, $context );

		if ( $supplied instanceof QueryScope ) {
			return $supplied;
		}

		if ( null !== $supplied ) {
			// A non-QueryScope return is rejected; unbounded is never reachable by mistake.
			return QueryScope::unbounded();
		}

		$ids     = array( $context->effective_post_id );
		$queue   = array( $context->effective_post_id );
		$limit   = max( 0, $this->limit );

		while ( array() !== $queue ) {
			$parent   = (int) array_shift( $queue );
			$children = get_posts(
				array(
					'post_parent'      => $parent,
					'post_type'        => $context->subtree_post_types,
					'post_status'      => $context->post_statuses,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);

			foreach ( $children as $child ) {
				$ids[]   = (int) $child;
				$queue[] = (int) $child;

				if ( count( $ids ) > $limit ) {
					return QueryScope::unbounded();
				}
			}
		}

		return QueryScope::of_ids( $ids );
	}
}
```

Create `src/Routing/MembershipFilter.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\RoutingContract;

/**
 * Scope is optimization only. This is the guarantee.
 */
final class MembershipFilter {

	public function __construct( private readonly RoutingContract $routing ) {}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public function keep_members( array $posts, ServingContext $context ): array {
		return array_values(
			array_filter(
				$posts,
				fn( \WP_Post $post ): bool => $this->routing->belongs_to_mapping( $context, $post )
			)
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter QueryScopeTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/QueryScope.php src/Contracts/QueryScopeProvider.php src/Routing/EnumerationScopeProvider.php src/Routing/MembershipFilter.php tests/integration/Routing/QueryScopeTest.php
git commit -m "Bound feed and sitemap scope, then check membership anyway

Scope is an optimization; membership is the guarantee. An empty inclusion array
short-circuits because WP_Query ignores it, turning nothing-matches into
everything-matches."
```

---

### Task 7: Feed and sitemap wiring

**Files:**
- Modify: `src/Plugin.php` (register the scope and membership hooks)
- Test: `tests/integration/Routing/FeedMembershipTest.php`

**Interfaces:**
- Consumes: Task 6.
- Produces: `Plugin::scope_feed_query( \WP_Query $query ): void` on `pre_get_posts` and `Plugin::enforce_membership( array $posts, \WP_Query $query ): array` on `the_posts`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/FeedMembershipTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class FeedMembershipTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_an_injected_non_member_is_removed_before_output(): void {
		Plugin::boot();

		$root    = $this->make_page( 'club', 0 );
		$inside  = $this->make_page( 'events', $root );
		$outside = $this->make_page( 'about-us', 0 );

		Plugin::instance()->context()->set_serving( $this->serving_context( $root ) );

		$query = new \WP_Query();
		$query->set( 'feed', 'rss2' );
		$query->is_feed = true;

		$kept = Plugin::instance()->enforce_membership(
			array( get_post( $inside ), get_post( $outside ) ),
			$query
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( $inside, $kept[0]->ID );
	}

	public function test_membership_is_not_applied_off_a_mapped_host(): void {
		Plugin::boot();

		$root    = $this->make_page( 'club', 0 );
		$outside = $this->make_page( 'about-us', 0 );

		Plugin::instance()->context()->set_serving( null );

		$query = new \WP_Query();
		$query->is_feed = true;

		$this->assertCount(
			1,
			Plugin::instance()->enforce_membership( array( get_post( $outside ) ), $query ),
			'the primary host keeps its own behaviour'
		);
		unset( $root );
	}

	public function test_an_unbounded_scope_yields_an_empty_feed_rather_than_everything(): void {
		Plugin::boot();

		$root = $this->make_page( 'club', 0 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_page( "child-{$i}", $root );
		}

		add_filter( 'pd_scope_enumeration_limit', static fn(): int => 2 );

		Plugin::instance()->context()->set_serving( $this->serving_context( $root ) );

		$query = new \WP_Query();
		$query->is_feed = true;

		Plugin::instance()->scope_feed_query( $query );

		$this->assertSame( array( 0 ), $query->get( 'post__in' ), 'an impossible id yields an empty result set' );

		remove_all_filters( 'pd_scope_enumeration_limit' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter FeedMembershipTest`
Expected: FAIL — `Error: Call to undefined method PostDomain\Plugin::enforce_membership()`

- [ ] **Step 3: Write minimal implementation**

Add to `src/Plugin.php`, inside `boot()`:

```php
		add_action( 'pre_get_posts', array( $plugin, 'scope_feed_query' ) );
		add_filter( 'the_posts', array( $plugin, 'enforce_membership' ), 10, 2 );
```

and add the methods:

```php
	public function scope_feed_query( \WP_Query $query ): void {
		$serving = $this->context->serving();

		if ( null === $serving || ! $query->is_feed() ) {
			return;
		}

		$limit = (int) apply_filters( 'pd_scope_enumeration_limit', 500 );
		$limit = max( 0, min( 5000, $limit ) );

		$scope = ( new \PostDomain\Routing\EnumerationScopeProvider( $this->routing(), $limit ) )->scope( $serving );

		if ( ! $scope->is_bounded ) {
			// Never unbounded: an id that cannot exist yields an empty result set.
			$query->set( 'post__in', array( 0 ) );

			return;
		}

		$query->set( 'post__in', $scope->post__in );

		foreach ( $scope->query_args as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public function enforce_membership( array $posts, \WP_Query $query ): array {
		$serving = $this->context->serving();

		if ( null === $serving || ! $query->is_feed() ) {
			return $posts;
		}

		return ( new \PostDomain\Routing\MembershipFilter( $this->routing() ) )->keep_members( $posts, $serving );
	}

	public function routing(): \PostDomain\Contracts\RoutingContract {
		return new \PostDomain\Routing\Subtree( new \PostDomain\Routing\PathNormalizer() );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter FeedMembershipTest`
Expected: PASS — 3 tests

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php tests/integration/Routing/FeedMembershipTest.php
git commit -m "Scope feeds to the subtree and validate every post before output

An unbounded scope sets an impossible id rather than running unfiltered. Under-
reporting a feed beats emitting a post from another domain's subtree."
```

---

### Task 8: Wire the resolver into parse_request

**Files:**
- Modify: `src/Plugin.php`
- Test: `tests/integration/Routing/ServedRequestTest.php`

**Interfaces:**
- Consumes: Tasks 5 and 7.
- Produces: `Plugin::resolve_request( \WP $wp ): void` registered on `parse_request` at priority 1.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/ServedRequestTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Plugin;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class ServedRequestTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_parse_request_is_registered_after_the_guard(): void {
		Plugin::boot();

		$this->assertSame( 0, has_action( 'parse_request', array( Plugin::instance(), 'enforce_disposition' ) ) );
		$this->assertSame( 1, has_action( 'parse_request', array( Plugin::instance(), 'resolve_request' ) ) );
	}

	public function test_a_mapped_root_request_sets_the_mapped_post(): void {
		Plugin::boot();

		$root = $this->make_page( 'club', 0 );
		Plugin::instance()->context()->set_serving( $this->serving_context( $root ) );

		$wp             = new \WP();
		$wp->request    = '';
		$wp->query_vars = array();

		Plugin::instance()->resolve_request( $wp );

		$this->assertSame( $root, (int) $wp->query_vars['page_id'] );
		$this->assertNotNull( Plugin::instance()->context()->serving()?->resolution );
	}

	public function test_a_request_off_a_mapped_host_is_untouched(): void {
		Plugin::boot();
		Plugin::instance()->context()->set_serving( null );

		$wp             = new \WP();
		$wp->request    = 'about-us';
		$wp->query_vars = array( 'pagename' => 'about-us' );

		Plugin::instance()->resolve_request( $wp );

		$this->assertSame( array( 'pagename' => 'about-us' ), $wp->query_vars );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter ServedRequestTest`
Expected: FAIL — `Error: Call to undefined method PostDomain\Plugin::resolve_request()`

- [ ] **Step 3: Write minimal implementation**

Add to `src/Plugin.php`, inside `boot()`:

```php
		add_action( 'parse_request', array( $plugin, 'resolve_request' ), 1 );
```

and add:

```php
	public function resolve_request( \WP $wp ): void {
		$serving = $this->context->serving();
		$host    = $this->context->host();

		if ( null === $serving
			|| null === $host
			|| \PostDomain\Routing\EndpointClass::ROUTED !== $host->endpoint ) {
			return;
		}

		$resolver   = new \PostDomain\Routing\Resolver( $this->routing(), new \PostDomain\Routing\PathDecomposer() );
		$resolution = $resolver->resolve( $serving, $wp );

		if ( null !== $resolution ) {
			$this->context->resolve(
				$resolution,
				( new \PostDomain\Routing\PathDecomposer() )->decompose( (string) $wp->request )->rep
			);

			return;
		}

		$mode     = (string) apply_filters( 'pd_unmatched_policy', 'redirect' );
		$policy   = new \PostDomain\Routing\UnmatchedPolicy( $mode, home_url() );
		$uri      = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '/';
		$response = $policy->response_for( $host->method, $uri );

		if ( null === $response ) {
			return;
		}

		if ( isset( $response['url'] ) ) {
			wp_redirect( $response['url'], $response['status'] ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		status_header( $response['status'] );
		nocache_headers();
		exit;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter ServedRequestTest`
Expected: PASS — 3 tests

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php tests/integration/Routing/ServedRequestTest.php
git commit -m "Resolve routed requests after the disposition guard has run"
```

---

### Task 9: The `CteSubtreeAdapter` capability gate

**Files:**
- Create: `src/Routing/DatabaseCapability.php`, `src/Routing/CteSubtreeAdapter.php`, `docs/cte-capability-evidence.md`
- Test: `tests/integration/Routing/CteCapabilityTest.php`

**Interfaces:**
- Consumes: `QueryScopeProvider` (Task 6).
- Produces: `PostDomain\Routing\DatabaseCapability::supports_recursive_cte(): bool`, `::server_description(): string`, and `PostDomain\Routing\CteSubtreeAdapter` implementing `QueryScopeProvider`.

**This is the specification's one deliberately deferred item (spec §20), and it
is a gate rather than a placeholder.** The adapter ships **disabled**. Enabling it
anywhere requires evidence this repository does not contain.

**Evidence still required, exactly:**

1. The list of target environments the plugin will run on — hosting provider and
   plan for each, or "self-hosted" plus the server identity.
2. For each, the output of `SELECT VERSION();` run against that site's database.
3. For each, whether the database is MySQL or MariaDB, since their recursive-CTE
   floors differ (nominally MySQL 8.0 and MariaDB 10.2.2).

Until `docs/cte-capability-evidence.md` records those three items for at least
one environment, `PD_ENABLE_CTE_SUBTREE` stays undefined and the adapter is never
constructed. The probe below is real code and runs regardless; it is the second
gate, not a substitute for the evidence.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Routing/CteCapabilityTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Routing;

use PostDomain\Routing\CteSubtreeAdapter;
use PostDomain\Routing\DatabaseCapability;
use PostDomain\Routing\EnumerationScopeProvider;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Subtree;
use PostDomain\Tests\Integration\ServingContextFactory;
use WP_UnitTestCase;

final class CteCapabilityTest extends WP_UnitTestCase {

	use ServingContextFactory;

	public function test_the_adapter_is_disabled_unless_explicitly_enabled(): void {
		$this->assertFalse(
			CteSubtreeAdapter::is_enabled(),
			'PD_ENABLE_CTE_SUBTREE is undefined until the capability matrix is evidenced'
		);
	}

	public function test_the_probe_reports_a_server_description(): void {
		$this->assertMatchesRegularExpression(
			'/\d+\.\d+/',
			DatabaseCapability::server_description(),
			'the probe must be able to name what it probed'
		);
	}

	public function test_the_probe_result_is_a_boolean_and_does_not_throw(): void {
		$this->assertIsBool( DatabaseCapability::supports_recursive_cte() );
	}

	public function test_the_evidence_document_exists_and_names_what_is_required(): void {
		$evidence = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/cte-capability-evidence.md' );

		$this->assertStringContainsString( 'SELECT VERSION()', $evidence );
		$this->assertStringContainsString( 'PD_ENABLE_CTE_SUBTREE', $evidence );
	}

	public function test_the_enumeration_fallback_produces_the_same_ids_as_the_adapter(): void {
		if ( ! DatabaseCapability::supports_recursive_cte() ) {
			$this->markTestSkipped( 'this database does not support recursive CTEs' );
		}

		$root  = $this->make_page( 'club', 0 );
		$one   = $this->make_page( 'one', $root );
		$two   = $this->make_page( 'two', $one );

		$context     = $this->serving_context( $root );
		$subtree     = new Subtree( new PathNormalizer() );
		$enumerated  = ( new EnumerationScopeProvider( $subtree, 500 ) )->scope( $context );
		$via_cte     = ( new CteSubtreeAdapter() )->scope( $context );

		$this->assertEqualsCanonicalizing( $enumerated->post__in, $via_cte->post__in );
		$this->assertEqualsCanonicalizing( array( $root, $one, $two ), $via_cte->post__in );
	}

	public function test_an_unsupported_database_yields_an_unbounded_scope_not_a_query(): void {
		$root    = $this->make_page( 'club', 0 );
		$context = $this->serving_context( $root );

		$adapter = new CteSubtreeAdapter( false );

		$this->assertFalse(
			$adapter->scope( $context )->is_bounded,
			'without capability the adapter must decline, never emit unbounded SQL'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CteCapabilityTest`
Expected: FAIL — `Error: Class "PostDomain\Routing\DatabaseCapability" not found`

- [ ] **Step 3: Write minimal implementation**

Create `docs/cte-capability-evidence.md`:

```markdown
# CteSubtreeAdapter — capability evidence

`CteSubtreeAdapter` is **disabled**. It is enabled only by defining
`PD_ENABLE_CTE_SUBTREE` in `wp-config.php`, and that constant must not be
defined for an environment until this document records all three items below
for that environment.

Recursive common table expressions require **MySQL 8.0** or **MariaDB 10.2.2**
at minimum. Those floors are nominal: they are the published minimums, not
evidence about any environment this plugin actually runs on.

## Required evidence, per target environment

| Item | How to obtain it |
|---|---|
| 1. Environment identity | Hosting provider and plan, or "self-hosted" plus the server identity |
| 2. Database version string | `SELECT VERSION();` run against that site's database |
| 3. Product | MySQL or MariaDB — the version string alone is ambiguous between them |

## Recorded environments

_None yet. No target environment has been established for this plugin, so no
row can honestly be filled in. Until at least one row exists, the enumeration
provider is the only scope provider in use._

| Environment | `SELECT VERSION()` | Product | Probe result | Enabled |
|---|---|---|---|---|
| | | | | |
```

Create `src/Routing/DatabaseCapability.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class DatabaseCapability {

	private const MIN_MYSQL   = '8.0.0';
	private const MIN_MARIADB = '10.2.2';

	public static function server_description(): string {
		global $wpdb;

		return (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB
	}

	public static function supports_recursive_cte(): bool {
		$version = self::server_description();

		if ( '' === $version ) {
			return false;
		}

		if ( false !== stripos( $version, 'mariadb' ) ) {
			$numeric = self::numeric_part( str_ireplace( 'mariadb', '', $version ) );

			return '' !== $numeric && version_compare( $numeric, self::MIN_MARIADB, '>=' );
		}

		$numeric = self::numeric_part( $version );

		return '' !== $numeric && version_compare( $numeric, self::MIN_MYSQL, '>=' );
	}

	private static function numeric_part( string $version ): string {
		return 1 === preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $version, $matches ) ? $matches[1] : '';
	}
}
```

Create `src/Routing/CteSubtreeAdapter.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\QueryScopeProvider;

/**
 * Capability-gated. Ships disabled: enabling it requires evidence recorded in
 * docs/cte-capability-evidence.md AND a positive probe. Returning an unbounded
 * scope is the only thing it does when either gate is closed — it never emits
 * SQL it is not sure the server understands.
 */
final class CteSubtreeAdapter implements QueryScopeProvider {

	private bool $capable;

	public function __construct( ?bool $capable = null ) {
		$this->capable = $capable ?? DatabaseCapability::supports_recursive_cte();
	}

	public static function is_enabled(): bool {
		return defined( 'PD_ENABLE_CTE_SUBTREE' ) && (bool) constant( 'PD_ENABLE_CTE_SUBTREE' );
	}

	public function scope( ServingContext $context ): QueryScope {
		if ( ! $this->capable ) {
			return QueryScope::unbounded();
		}

		global $wpdb;

		$types    = implode( ',', array_fill( 0, count( $context->subtree_post_types ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( $context->post_statuses ), '%s' ) );

		$sql = "WITH RECURSIVE pd_tree (id) AS (
					SELECT ID FROM {$wpdb->posts} WHERE ID = %d
					UNION ALL
					SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN pd_tree t ON p.post_parent = t.id
					WHERE p.post_type IN ({$types}) AND p.post_status IN ({$statuses})
				)
				SELECT id FROM pd_tree";

		$values = array_merge(
			array( $context->effective_post_id ),
			$context->subtree_post_types,
			$context->post_statuses
		);

		/** @var string[] $ids */
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB

		return QueryScope::of_ids( array_map( 'intval', $ids ) );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CteCapabilityTest`
Expected: PASS — 6 tests (one may report SKIPPED on a database without recursive
CTE support, which is itself the correct outcome)

- [ ] **Step 5: Verify the adapter is unreachable in production wiring**

Run: `grep -rn "CteSubtreeAdapter" src/ | grep -v "src/Routing/CteSubtreeAdapter.php"`
Expected: no output — nothing constructs the adapter, because
`EnumerationScopeProvider` is the only provider `Plugin` wires.

- [ ] **Step 6: Commit**

```bash
git add src/Routing/DatabaseCapability.php src/Routing/CteSubtreeAdapter.php docs/cte-capability-evidence.md tests/integration/Routing/CteCapabilityTest.php
git commit -m "Gate the recursive-CTE scope adapter behind evidence and a probe

The adapter is disabled and unwired. Enabling it needs a recorded SELECT
VERSION() per target environment plus a positive probe, and with either gate
closed it declines rather than emitting SQL the server may not understand."
```

---

## Gate for Plan 04

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: the round-trip property test passes over the generated fixture tree, the
collision tests prove no winner is picked, `FeedMembershipTest` proves an
injected non-member is removed, and
`grep -rn "CteSubtreeAdapter" src/ | grep -v CteSubtreeAdapter.php` returns
nothing.
