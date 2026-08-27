# post-domain 10 — Management REST API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A management API that exists only on the primary host, exposes a
computed serving state, and refuses any mutation that is not carrying a current
`If-Match`.

**Architecture:** Routes are **registered** conditionally rather than guarded, so
on a mapped host they do not exist at all — absent from dispatch and from
`/wp-json/` discovery. The serializer computes `serving` per resource but never
per collection row.

**Tech Stack:** As Plans 01–09, plus the WordPress REST API.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–09, and add:

- **Routes are registered only when `HostContext::kind === PRIMARY`** (spec §15).
- **`If-Match` is required** on `PATCH`, `DELETE`, `POST /challenge`, and every
  `…/ssl` mutation: missing ⇒ `428`, stale ⇒ `412` (spec §12.4).
- **Verification and SSL state are read-only over REST.** No request makes a
  mapping verified (spec §15.2).
- **Collections do not compute `serving` or `validation_plan`** (spec §15.1).
- **Credentials, lease tokens, and authorizations appear in no response**
  (spec §15.1).
- **`challenge` IS exposed** — it is a value the domain owner must publish in
  public DNS, not a credential (spec §15.1).

---

## File map

| File | Responsibility |
|---|---|
| `src/Rest/Errors.php` | The error-code vocabulary |
| `src/Rest/Guard.php` | Capability check, ETag, and precondition handling |
| `src/Rest/MappingSerializer.php` | Resource shape, `serving` computation, target links |
| `src/Rest/SslServices.php` | The SSL services a request handler is allowed to call |
| `src/Verification/ResolverFactory.php` | One place that builds the configured DNS resolver |
| `src/Rest/ManagementController.php` | Route registration and handlers |

**Route ownership.** Every route is registered together with the handler that
actually answers it, in the task that builds that handler. No task registers a
route it cannot answer, and no handler in this plan is a stub: `register()` grows
one `register_*()` call per task.

| Task | Routes it registers |
|---|---|
| 1 | none — `Errors`, `Guard`, `MappingSerializer` only |
| 2 | `GET/POST /domains` |
| 3 | `GET/PATCH/DELETE /domains/(?P<id>[\d]+)` |
| 4 | `POST …/verify`, `POST …/challenge` |
| 5 | `GET …/plan`, `POST/PATCH/DELETE …/ssl`, `POST …/ssl/adopt` |
| 6 | `GET /environment`, `POST /environment/resolve` |

---

### Task 1: The error vocabulary, the guard, and the resource shape

**Files:**
- Create: `src/Rest/Errors.php`, `src/Rest/Guard.php`, `src/Rest/MappingSerializer.php`
- Test: `tests/integration/Rest/SerializerTest.php`

**Interfaces:**
- Consumes: `Mapping`, `AliasResolver` (Plan 02), `Challenge` (Plan 06), `IdnaNormalizer` (Plan 01), `Environment` (Plan 07).
- Produces: `PostDomain\Rest\Errors::NS` plus the code constants; `PostDomain\Rest\Guard::may_manage( \WP_REST_Request $r ): true|\WP_Error`, `::etag( Mapping $m ): string`, `::check_precondition( \WP_REST_Request $r, Mapping $m ): true|\WP_Error`; `PostDomain\Rest\MappingSerializer::row( Mapping $m ): array` (collection shape) and `::resource( Mapping $m ): array` (individual shape, with `serving`).

No routes exist yet. This task is testable on its own because the serializer and
the guard are pure functions of a mapping and a request.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/SerializerTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Guard;
use PostDomain\Rest\MappingSerializer;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class SerializerTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
		register_post_type( 'club', array( 'public' => true, 'show_in_rest' => true, 'rest_base' => 'clubs' ) );
	}

	public function tear_down(): void {
		unregister_post_type( 'club' );
		remove_all_filters( 'pd_mapping_is_active' );
		remove_all_filters( 'pd_rest_capability' );
		parent::tear_down();
	}

	private function mapping( VerificationState $v, ActivationState $a, string $type = 'club' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'xn--mnchen-3ya.example',
				null,
				self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'publish' ) ),
				1,
				$v,
				$a,
				SslState::ACTIVE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'cloudflare-saas',
				'ref-1'
			)
		);
	}

	public function test_the_host_is_ascii_and_the_display_form_is_unicode(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'xn--mnchen-3ya.example', $resource['host'] );
		$this->assertSame( 'münchen.example', $resource['host_display'] );
	}

	public function test_the_challenge_is_exposed(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::PENDING, ActivationState::INACTIVE ) );

		$this->assertStringContainsString(
			'post-domain-verify=',
			$resource['dns_challenge']['value'],
			'the challenge is a public DNS value, not a credential'
		);
	}

	public function test_no_credential_or_lease_token_appears(): void {
		$encoded = (string) wp_json_encode(
			MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) )
		);

		foreach ( array( 'api_token', 'ssl_mutation_token', 'lease_token', 'owner_installation_id' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded );
		}
	}

	public function test_ownership_is_reported_as_a_boolean_not_an_installation_id(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertTrue( $resource['ssl']['owned_by_this_installation'] );
		$this->assertSame( 'created', $resource['ssl']['ownership_origin'] );
	}

	public function test_target_links_come_from_the_post_type(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'club', $resource['target']['post_type'] );
		$this->assertSame( 'clubs', $resource['target']['rest_base'] );
		$this->assertStringContainsString( '/wp/v2/clubs/', (string) $resource['target']['rest_link'] );
	}

	public function test_a_non_rest_post_type_has_no_rest_link(): void {
		register_post_type( 'private_thing', array( 'public' => false, 'show_in_rest' => false ) );

		$resource = MappingSerializer::resource(
			$this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, 'private_thing' )
		);

		$this->assertNull( $resource['target']['rest_link'] );

		unregister_post_type( 'private_thing' );
	}

	public function test_serving_reports_serving_for_a_healthy_mapping(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'serving', $resource['serving']['state'] );
	}

	public function test_serving_reports_unverified_first(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::PENDING, ActivationState::INACTIVE ) );

		$this->assertSame( 'unverified', $resource['serving']['state'], 'precedence: unverified before inactive' );
	}

	public function test_serving_reports_inactive(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::INACTIVE ) );

		$this->assertSame( 'inactive', $resource['serving']['state'] );
	}

	public function test_serving_reports_vetoed(): void {
		add_filter( 'pd_mapping_is_active', '__return_false' );

		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'vetoed', $resource['serving']['state'] );
	}

	public function test_serving_reports_broken_for_a_missing_target(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		wp_delete_post( (int) $mapping->post_id, true );

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertSame( 'broken', $resource['serving']['state'] );
	}

	public function test_a_collection_row_omits_serving_and_the_plan(): void {
		$row = MappingSerializer::row( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertArrayNotHasKey( 'serving', $row );
		$this->assertArrayNotHasKey( 'validation_plan', $row );
		$this->assertArrayHasKey( 'verification', $row );
	}

	public function test_a_mutation_in_progress_reports_kind_and_phase_but_not_the_token(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '6', 32 ),
				'ssl_mutation_kind'       => 'remove',
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			),
			array( 'id' => $mapping->id )
		);

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertSame( 'remove', $resource['ssl']['mutation_in_progress']['kind'] );
		$this->assertSame( 'in_flight', $resource['ssl']['mutation_in_progress']['phase'] );
		$this->assertArrayNotHasKey( 'token', $resource['ssl']['mutation_in_progress'] );
	}

	public function test_the_etag_carries_the_revision(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$this->assertSame( sprintf( '"%d-%d"', $mapping->id, $mapping->revision ), Guard::etag( $mapping ) );
	}

	public function test_a_missing_if_match_is_a_428_error(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		$error   = Guard::check_precondition( new WP_REST_Request( 'PATCH', '/x' ), $mapping );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 428, $error->get_error_data()['status'] );
	}

	public function test_a_stale_if_match_is_a_412_error(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		$request = new WP_REST_Request( 'PATCH', '/x' );
		$request->set_header( 'if_match', '"' . $mapping->id . '-99"' );

		$error = Guard::check_precondition( $request, $mapping );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 412, $error->get_error_data()['status'] );
	}

	public function test_a_current_if_match_passes(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		$request = new WP_REST_Request( 'PATCH', '/x' );
		$request->set_header( 'if_match', Guard::etag( $mapping ) );

		$this->assertTrue( Guard::check_precondition( $request, $mapping ) );
	}

	public function test_an_empty_filtered_capability_falls_back_to_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'pd_rest_capability', static fn(): string => '' );

		$this->assertInstanceOf(
			\WP_Error::class,
			Guard::may_manage( new WP_REST_Request( 'GET', '/x' ) )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\SerializerTest`
Expected: FAIL — `Error: Class "PostDomain\Rest\MappingSerializer" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Rest/Errors.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

final class Errors {

	public const NS = 'post-domain/v1';

	public const HOST_INVALID             = 'pd_host_invalid';
	public const HOST_MALFORMED_AUTHORITY = 'pd_host_malformed_authority';
	public const HOST_WILDCARD            = 'pd_host_wildcard';
	public const HOST_EXISTS              = 'pd_host_exists';
	public const HOST_TOO_LONG            = 'pd_host_too_long';
	public const LABEL_INVALID            = 'pd_label_invalid';
	public const ALIAS_CHAIN              = 'pd_alias_chain';
	public const ALIAS_NO_TARGET          = 'pd_alias_no_target';
	public const ALIAS_IN_USE             = 'pd_alias_in_use';
	public const POST_INVALID             = 'pd_post_invalid';
	public const CONFLICT                 = 'pd_conflict';
	public const PRECONDITION_REQUIRED    = 'pd_precondition_required';
	public const PRECONDITION_FAILED      = 'pd_precondition_failed';
	public const RATE_LIMITED             = 'pd_rate_limited';
	public const ENVIRONMENT_UNRESOLVED   = 'pd_environment_unresolved';
	public const MUTATION_IN_PROGRESS     = 'pd_mutation_in_progress';
	public const MUTATION_UNAUTHORIZED    = 'pd_mutation_unauthorized';
	public const UNOWNED_RESOURCE         = 'pd_unowned_resource';
	public const CREATE_AMBIGUOUS         = 'pd_provider_create_ambiguous';
	public const METHOD_UNSUPPORTED       = 'pd_method_unsupported';
	public const CONFIRMATION_REQUIRED    = 'pd_confirmation_required';
	public const NO_DRIVER                = 'pd_no_driver';
	public const SSL_NOT_CONFIGURED       = 'pd_ssl_not_configured';
	public const FENCED                   = 'pd_mutation_fenced';
	public const FINALIZATION_FAILED      = 'pd_finalization_failed';
	public const OUTCOME_AMBIGUOUS        = 'pd_provider_outcome_ambiguous';
	public const FORBIDDEN                = 'pd_forbidden';
}
```

Create `src/Rest/Guard.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Mapping\Mapping;

final class Guard {

	/** @return true|\WP_Error */
	public static function may_manage( \WP_REST_Request $request ) {
		$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', (string) $request->get_route() );
		$capability = '' === $capability ? 'manage_options' : $capability;

		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new \WP_Error(
			Errors::FORBIDDEN,
			__( 'You are not allowed to manage domain mappings.', 'post-domain' ),
			array( 'status' => 403 )
		);
	}

	public static function etag( Mapping $mapping ): string {
		return sprintf( '"%d-%d"', $mapping->id, $mapping->revision );
	}

	/** @return true|\WP_Error */
	public static function check_precondition( \WP_REST_Request $request, Mapping $mapping ) {
		$header = trim( (string) $request->get_header( 'if_match' ) );

		if ( '' === $header ) {
			return new \WP_Error(
				Errors::PRECONDITION_REQUIRED,
				__( 'This request requires an If-Match header carrying the current ETag.', 'post-domain' ),
				array( 'status' => 428 )
			);
		}

		if ( $header !== self::etag( $mapping ) ) {
			return new \WP_Error(
				Errors::PRECONDITION_FAILED,
				__( 'The mapping changed since you read it.', 'post-domain' ),
				array( 'status' => 412 )
			);
		}

		return true;
	}
}
```

Create `src/Rest/MappingSerializer.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;

final class MappingSerializer {

	/** @return array<string, mixed> */
	public static function row( Mapping $mapping ): array {
		return array(
			'id'           => $mapping->id,
			'revision'     => $mapping->revision,
			'host'         => $mapping->host,
			'host_display' => ( new IdnaNormalizer() )->to_display( $mapping->host ),
			'alias_of'     => $mapping->alias_of,
			'post_id'      => $mapping->post_id,
			'verification' => array(
				'state' => $mapping->verification_state->value,
			),
			'activation'   => array( 'state' => $mapping->activation_state->value ),
			'ssl'          => array( 'state' => $mapping->ssl_state->value ),
		);
	}

	/** @return array<string, mixed> */
	public static function resource( Mapping $mapping ): array {
		$resource = self::row( $mapping );

		$resource['target']        = self::target( $mapping );
		$resource['dns_challenge'] = array(
			'type'  => 'TXT',
			'name'  => Challenge::record_name( $mapping->challenge_label, $mapping->host ),
			'value' => Challenge::expected_value( $mapping->challenge ),
			'ttl'   => 300,
		);

		$resource['ssl'] = array(
			'state'                      => $mapping->ssl_state->value,
			'provider'                   => $mapping->ssl_provider,
			'ownership_origin'           => $mapping->ssl_ownership_origin?->value,
			'owned_by_this_installation' => null !== $mapping->ssl_ownership_origin
				&& $mapping->ssl_owner_installation_id === Environment::installation_id(),
			'method'                     => $mapping->ssl_method,
			'mutation_in_progress'       => null === $mapping->ssl_mutation_kind
				? null
				: array(
					'kind'       => $mapping->ssl_mutation_kind->value,
					'phase'      => $mapping->ssl_mutation_phase?->value,
					'expires_at' => $mapping->ssl_mutation_expires_at,
				),
		);

		$resource['serving'] = self::serving( $mapping );

		return $resource;
	}

	/** @return array{state: string, reason: string|null, blocked_by: array{id: int, host: string}|null} */
	private static function serving( Mapping $mapping ): array {
		$repo    = new DbRepository();
		$aliases = new AliasResolver( $repo );

		$own = self::blocker_for( $mapping );

		if ( null !== $own ) {
			return array( 'state' => $own, 'reason' => null, 'blocked_by' => null );
		}

		$canonical = $aliases->canonical_for( $mapping );

		if ( null !== $canonical && $canonical->id !== $mapping->id ) {
			$parent = self::blocker_for( $canonical );

			if ( null !== $parent ) {
				return array(
					'state'      => $parent,
					'reason'     => 'canonical mapping is not serving',
					'blocked_by' => array( 'id' => $canonical->id, 'host' => $canonical->host ),
				);
			}
		}

		return array( 'state' => 'serving', 'reason' => null, 'blocked_by' => null );
	}

	private static function blocker_for( Mapping $mapping ): ?string {
		if ( VerificationState::VERIFIED !== $mapping->verification_state ) {
			return 'unverified';
		}

		if ( ActivationState::ACTIVE !== $mapping->activation_state ) {
			return 'inactive';
		}

		if ( ! (bool) apply_filters( 'pd_mapping_is_active', true, $mapping, null ) ) {
			return 'vetoed';
		}

		if ( null !== $mapping->integrity_error ) {
			return 'broken';
		}

		$target = $mapping->is_alias() ? null : get_post( (int) $mapping->post_id );

		if ( ! $mapping->is_alias() && ( null === $target || 'publish' !== $target->post_status ) ) {
			return 'broken';
		}

		return null;
	}

	/** @return array<string, mixed> */
	private static function target( Mapping $mapping ): array {
		$repo      = new DbRepository();
		$aliases   = new AliasResolver( $repo );
		$target_id = $aliases->effective_post_id( $mapping );
		$post      = null === $target_id ? null : get_post( $target_id );

		if ( null === $post ) {
			return array(
				'id'        => $target_id,
				'post_type' => null,
				'rest_base' => null,
				'rest_link' => null,
				'edit_link' => null,
				'derived'   => $mapping->is_alias(),
			);
		}

		$type      = get_post_type_object( $post->post_type );
		$rest_base = null;
		$rest_link = null;

		if ( null !== $type && true === $type->show_in_rest ) {
			$rest_base = is_string( $type->rest_base ) && '' !== $type->rest_base
				? $type->rest_base
				: $post->post_type;
			$namespace = is_string( $type->rest_namespace ) && '' !== $type->rest_namespace
				? $type->rest_namespace
				: 'wp/v2';
			$rest_link = rest_url( $namespace . '/' . $rest_base . '/' . $post->ID );
		}

		return array(
			'id'        => $post->ID,
			'post_type' => $post->post_type,
			'rest_base' => $rest_base,
			'rest_link' => $rest_link,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'derived'   => $mapping->is_alias(),
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\SerializerTest`
Expected: PASS — 17 tests

- [ ] **Step 5: Commit**

```bash
git add src/Rest/Errors.php src/Rest/Guard.php src/Rest/MappingSerializer.php tests/integration/Rest/SerializerTest.php
git commit -m "Serialize mappings with a computed serving state

Target links come from the post type's own rest_base, so a private type simply
has no link. Collections omit serving because computing it runs a filter and a
post lookup per row."
```

---

### Task 2: The collection, registered only on the primary host

**Files:**
- Create: `src/Verification/ResolverFactory.php`, `src/Rest/SslServices.php`, `src/Rest/ManagementController.php`
- Modify: `src/Plugin.php` (route registration, and `dns_resolver()` delegates to the factory)
- Test: `tests/integration/Rest/CollectionTest.php`

**Interfaces:**
- Consumes: `HostContext` (Plan 03), `MappingRepository` (Plan 02), `AuthorityParser`, `HostNormalizer` (Plan 01), `Challenge` (Plan 06), the Plan 07–09 SSL services.
- Produces:
  - `PostDomain\Verification\ResolverFactory::from_filters(): DnsResolver` — the `pd_doh_endpoints` and `pd_dns_resolver` construction lifted out of `Plugin::dns_resolver()`, which now delegates to it.
  - `PostDomain\Rest\SslServices::__construct( CreateService $create, AdoptionService $adopt, MethodChangeService $method, DeletionService $delete )` with `::production(): self` and `::driver_for( Mapping $m ): SslDriver|DriverUnavailable`. It builds no registry: every service and the `/plan` route resolve through `DriverFactory`.
  - `PostDomain\Rest\ManagementController::__construct( MappingRepository $repo, SslServices $ssl )` with `::register(): void`, `::index()`, `::create()`.

`register()` registers exactly the routes whose handlers exist. Later tasks each
add one `register_*()` call and the handlers that answer it.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/CollectionTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Rest\Errors;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class CollectionTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_rest_capability' );
		parent::tear_down();
	}

	private function register(): void {
		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** @param array<string, mixed> $body */
	private function post( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	public function test_the_collection_routes_exist_when_registered(): void {
		$this->register();

		$this->assertArrayHasKey( '/post-domain/v1/domains', rest_get_server()->get_routes() );
	}

	public function test_the_namespace_is_absent_from_discovery_when_not_registered(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/' ) )->get_data();

		$this->assertNotContains(
			'post-domain/v1',
			$data['namespaces'] ?? array(),
			'on a mapped host the namespace must not be enumerable'
		);
	}

	public function test_an_unauthenticated_request_is_forbidden(): void {
		$this->register();
		wp_set_current_user( 0 );

		$this->assertSame(
			403,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_a_subscriber_is_forbidden(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame(
			403,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_an_administrator_is_allowed(): void {
		$this->register();
		$this->admin();

		$this->assertSame(
			200,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_the_capability_is_filterable(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pd_rest_capability', static fn(): string => 'edit_posts' );

		$this->assertSame(
			200,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_status()
		);
	}

	public function test_the_collection_returns_rows_without_serving(): void {
		$this->register();
		$this->admin();
		$this->post( array( 'host' => 'example.test', 'post_id' => $this->post_id ) );

		$rows = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) )->get_data();

		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'serving', $rows[0] );
	}

	public function test_creating_a_mapping_returns_201_with_a_pending_challenge(): void {
		$this->register();
		$this->admin();

		$response = $this->post( array( 'host' => 'example.test', 'post_id' => $this->post_id ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'unverified', $response->get_data()['verification']['state'] );
		$this->assertMatchesRegularExpression(
			'/^post-domain-verify=[0-9a-f]{32}$/',
			$response->get_data()['dns_challenge']['value']
		);
	}

	public function test_a_unicode_host_is_stored_as_punycode(): void {
		$this->register();
		$this->admin();

		$this->assertSame(
			'xn--mnchen-3ya.example',
			$this->post( array( 'host' => 'münchen.example', 'post_id' => $this->post_id ) )->get_data()['host']
		);
	}

	public function test_a_malformed_authority_is_rejected(): void {
		$this->register();
		$this->admin();

		$response = $this->post( array( 'host' => 'bad host:', 'post_id' => $this->post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::HOST_MALFORMED_AUTHORITY, $response->get_data()['code'] );
	}

	public function test_a_wildcard_host_is_rejected(): void {
		$this->register();
		$this->admin();

		$response = $this->post( array( 'host' => '*.example.test', 'post_id' => $this->post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::HOST_WILDCARD, $response->get_data()['code'] );
	}

	public function test_a_duplicate_host_is_rejected(): void {
		$this->register();
		$this->admin();
		$this->post( array( 'host' => 'example.test', 'post_id' => $this->post_id ) );

		$response = $this->post( array( 'host' => 'example.test', 'post_id' => $this->post_id ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::HOST_EXISTS, $response->get_data()['code'] );
	}

	public function test_a_host_too_long_for_the_composed_txt_name_is_rejected(): void {
		$this->register();
		$this->admin();

		$host = str_repeat( 'a', 60 ) . '.' . str_repeat( 'b', 60 ) . '.'
			. str_repeat( 'c', 60 ) . '.' . str_repeat( 'd', 55 ) . '.test';

		$response = $this->post( array( 'host' => $host, 'post_id' => $this->post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertContains( $response->get_data()['code'], array( Errors::HOST_TOO_LONG, Errors::HOST_INVALID ) );
	}

	public function test_an_invalid_post_is_rejected(): void {
		$this->register();
		$this->admin();

		$response = $this->post( array( 'host' => 'example.test', 'post_id' => 999999 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::POST_INVALID, $response->get_data()['code'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\CollectionTest`
Expected: FAIL — `Error: Class "PostDomain\Rest\ManagementController" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Rest/SslServices.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Ssl\AdoptionAuthorizer;
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\CreateAuthorizer;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\DeletionService;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Ssl\MethodChangeAuthorizer;
use PostDomain\Ssl\MethodChangeService;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationLease;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\FreshProof;
use PostDomain\Verification\ResolverFactory;

/**
 * The exact set of SSL operations a REST handler may reach. The controller never
 * constructs a driver, a lease, or an authorization of its own.
 */
final class SslServices {

	public function __construct(
		public readonly CreateService $create,
		public readonly AdoptionService $adopt,
		public readonly MethodChangeService $method,
		public readonly DeletionService $delete
	) {}

	public static function production(): self {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$gate  = new MutationGate( $lease, $clock );
		$repo  = new DbRepository();
		$proof = new FreshProof( ResolverFactory::from_filters() );

		// No registry is built here. Every service resolves its driver through
		// DriverFactory, which is also what cron uses, so the two cannot differ.
		return new self(
			new CreateService( $repo, new CreateAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate, $clock ),
			new AdoptionService( $repo, new AdoptionAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate, $clock ),
			new MethodChangeService( $repo, new MethodChangeAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate, $clock ),
			new DeletionService( $repo, new DeletionAuthorizer( $repo, $proof, $lease, $clock ), $lease, $gate, $clock )
		);
	}

	/** @return SslDriver|DriverUnavailable */
	public function driver_for( Mapping $mapping ) {
		return DriverFactory::for_mapping( $mapping );
	}
}
```

Create `src/Verification/ResolverFactory.php`, moving the body of
`Plugin::dns_resolver()` here so the REST layer and the cron layer resolve DNS
the same way:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Support\WpHttpClient;

final class ResolverFactory {

	public static function from_filters(): DnsResolver {
		/** @var string[] $endpoints */
		$endpoints = (array) apply_filters(
			'pd_doh_endpoints',
			array( 'https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve' )
		);

		$default = new DohResolver( new WpHttpClient(), $endpoints );

		/** @var DnsResolver $resolver */
		$resolver = apply_filters( 'pd_dns_resolver', $default );

		return $resolver instanceof DnsResolver ? $resolver : $default;
	}
}
```

Replace the body of `Plugin::dns_resolver()` with a single delegation, so there
is still exactly one place that reads those two filters:

```php
	public function dns_resolver(): \PostDomain\Contracts\DnsResolver {
		return \PostDomain\Verification\ResolverFactory::from_filters();
	}
```

Create `src/Rest/ManagementController.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\InvalidMapping;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;

final class ManagementController {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly SslServices $ssl
	) {}

	public function register(): void {
		$this->register_domains();
	}

	private function permission(): callable {
		return array( Guard::class, 'may_manage' );
	}

	private function register_domains(): void {
		register_rest_route(
			Errors::NS,
			'/domains',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->permission(),
				),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$compute = 'serving' === $request->get_param( '_compute' );
		$rows    = array();

		foreach ( $this->repo->all() as $mapping ) {
			$rows[] = $compute
				? MappingSerializer::resource( $mapping )
				: MappingSerializer::row( $mapping );
		}

		return new \WP_REST_Response( $rows, 200 );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$raw   = (string) $request->get_param( 'host' );
		$alias = $request->get_param( 'alias_of' );

		if ( str_contains( $raw, '*' ) ) {
			return self::error( Errors::HOST_WILDCARD, 'Wildcard hosts are out of scope.', 400 );
		}

		$authority = ( new AuthorityParser() )->parse( $raw );

		if ( null === $authority ) {
			return self::error( Errors::HOST_MALFORMED_AUTHORITY, 'That host is not a valid authority.', 400 );
		}

		$host = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $host ) {
			return self::error( Errors::HOST_INVALID, 'That host cannot be normalized.', 400 );
		}

		if ( null !== $this->repo->by_host( $host ) ) {
			return self::error( Errors::HOST_EXISTS, 'That host is already mapped.', 409 );
		}

		$label = Challenge::DEFAULT_LABEL;

		if ( strlen( $host ) > Challenge::max_host_length( $label ) ) {
			return self::error(
				Errors::HOST_TOO_LONG,
				sprintf(
					'The composed TXT record name would exceed 253 bytes; this label permits %d bytes of host.',
					Challenge::max_host_length( $label )
				),
				400
			);
		}

		$post_id = null;

		if ( null === $alias ) {
			$post_id = (int) $request->get_param( 'post_id' );

			if ( null === get_post( $post_id ) ) {
				return self::error( Errors::POST_INVALID, 'That post does not exist.', 400 );
			}
		}

		try {
			$mapping = $this->repo->save(
				new Mapping(
					0,
					$host,
					null === $alias ? null : (int) $alias,
					$post_id,
					1,
					VerificationState::UNVERIFIED,
					ActivationState::INACTIVE,
					SslState::NONE,
					null,
					Challenge::token(),
					$label
				)
			);
		} catch ( InvalidMapping $e ) {
			return self::error( Errors::ALIAS_CHAIN, $e->getMessage(), 400 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), 201 );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array( 'code' => $code, 'message' => $message, 'data' => array( 'status' => $status ) ),
			$status
		);
	}

	private static function from_wp_error( \WP_Error $error ): \WP_REST_Response {
		$status = (int) ( $error->get_error_data()['status'] ?? 400 );

		return self::error( $error->get_error_code(), $error->get_error_message(), $status );
	}
}
```

Add to `src/Plugin.php`, inside `boot()`:

```php
		add_action( 'rest_api_init', array( $plugin, 'register_rest_routes' ) );
```

and:

```php
	public function register_rest_routes(): void {
		$host = $this->context->host();

		// Registered, not guarded: on any other host the routes do not exist.
		if ( null === $host || \PostDomain\Routing\HostKind::PRIMARY !== $host->kind ) {
			return;
		}

		( new \PostDomain\Rest\ManagementController(
			$this->repository,
			\PostDomain\Rest\SslServices::production()
		) )->register();
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\CollectionTest`
Expected: PASS — 14 tests

Then re-run Plan 06's resolver tests, which now exercise the factory through
`Plugin::dns_resolver()`:

Run: `composer test:integration -- --filter ScheduleTest && vendor/bin/phpunit --testsuite unit --filter DohResolverTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Verification/ResolverFactory.php src/Rest/SslServices.php src/Rest/ManagementController.php src/Plugin.php tests/integration/Rest/CollectionTest.php
git commit -m "Register the domains collection only on the primary host

Registration rather than a guard: on a mapped host the namespace does not exist,
so it is not enumerable from a customer domain. Both collection methods answer
for real."
```

---

### Task 3: The individual resource

**Files:**
- Modify: `src/Rest/ManagementController.php`
- Test: `tests/integration/Rest/ResourceTest.php`

**Interfaces:**
- Consumes: Task 1 and Task 2.
- Produces: the `/domains/(?P<id>[\d]+)` route with working `show`, `update`, and `destroy`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/ResourceTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class ResourceTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		( new ManagementController( $this->repo, SslServices::production() ) )->register();
	}

	/** @param array<string, mixed> $body */
	private function create_mapping( string $host = 'example.test', array $body = array() ): array {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params( array_merge( array( 'host' => $host, 'post_id' => $this->post_id ), $body ) );

		return rest_do_request( $request )->get_data();
	}

	/** @param array<string, mixed> $body */
	private function patch( int $id, array $body, ?string $etag ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $id );
		$request->set_body_params( $body );

		if ( null !== $etag ) {
			$request->set_header( 'if_match', $etag );
		}

		return rest_do_request( $request );
	}

	private function etag( int $id ): string {
		return Guard::etag( $this->repo->by_id( $id ) );
	}

	public function test_show_returns_the_resource_with_an_etag(): void {
		$created  = $this->create_mapping();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/' . $created['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->etag( $created['id'] ), $response->get_headers()['ETag'] );
		$this->assertArrayHasKey( 'serving', $response->get_data() );
	}

	public function test_show_returns_404_for_an_unknown_id(): void {
		$this->assertSame(
			404,
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/424242' ) )->get_status()
		);
	}

	public function test_patch_without_if_match_is_428(): void {
		$created = $this->create_mapping();

		$this->assertSame( 428, $this->patch( $created['id'], array( 'activation_state' => 'active' ), null )->get_status() );
	}

	public function test_patch_with_a_stale_if_match_is_412(): void {
		$created = $this->create_mapping();

		$this->assertSame(
			412,
			$this->patch( $created['id'], array( 'activation_state' => 'active' ), '"' . $created['id'] . '-99"' )->get_status()
		);
	}

	public function test_patch_with_a_current_if_match_succeeds(): void {
		$created  = $this->create_mapping();
		$response = $this->patch( $created['id'], array( 'activation_state' => 'active' ), $this->etag( $created['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['activation']['state'] );
	}

	public function test_patch_cannot_set_verification_state(): void {
		$created = $this->create_mapping();

		$this->patch( $created['id'], array( 'verification_state' => 'verified' ), $this->etag( $created['id'] ) );

		$this->assertSame(
			VerificationState::UNVERIFIED,
			$this->repo->by_id( $created['id'] )?->verification_state,
			'no request makes a mapping verified'
		);
	}

	public function test_patch_cannot_set_ssl_state(): void {
		$created = $this->create_mapping();

		$this->patch( $created['id'], array( 'ssl_state' => 'active' ), $this->etag( $created['id'] ) );

		$this->assertSame( 'none', $this->repo->by_id( $created['id'] )?->ssl_state->value );
	}

	public function test_patch_rejects_a_post_id_on_an_alias(): void {
		$canonical = $this->create_mapping( 'canonical.test' );
		$alias     = $this->create_mapping( 'alias.test', array( 'alias_of' => $canonical['id'], 'post_id' => null ) );

		$response = $this->patch( $alias['id'], array( 'post_id' => $this->post_id ), $this->etag( $alias['id'] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::ALIAS_NO_TARGET, $response->get_data()['code'] );
	}

	public function test_deleting_a_canonical_with_aliases_is_409(): void {
		$canonical = $this->create_mapping( 'canonical.test' );
		$this->create_mapping( 'alias.test', array( 'alias_of' => $canonical['id'], 'post_id' => null ) );

		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $canonical['id'] );
		$request->set_header( 'if_match', $this->etag( $canonical['id'] ) );

		$this->assertSame( 409, rest_do_request( $request )->get_status() );
	}

	public function test_delete_without_if_match_is_428(): void {
		$created = $this->create_mapping();

		$this->assertSame(
			428,
			rest_do_request( new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $created['id'] ) )->get_status()
		);
	}

	public function test_deleting_a_mapping_with_no_provider_resource_removes_it(): void {
		$created = $this->create_mapping();

		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $created['id'] );
		$request->set_header( 'if_match', $this->etag( $created['id'] ) );

		$this->assertSame( 204, rest_do_request( $request )->get_status() );
		$this->assertNull( $this->repo->by_id( $created['id'] ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\ResourceTest`
Expected: FAIL — `Failed asserting that 404 is identical to 200`

- [ ] **Step 3: Write minimal implementation**

Add to `ManagementController::register()`:

```php
		$this->register_domain();
```

and add these methods:

```php
	private function register_domain(): void {
		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->permission(),
				),
			)
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), 200 );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		$post_id = $request->get_param( 'post_id' );

		if ( null !== $post_id && $mapping->is_alias() ) {
			return self::error( Errors::ALIAS_NO_TARGET, 'An alias derives its target from its canonical row.', 400 );
		}

		$activation = $request->get_param( 'activation_state' );

		// verification_state and ssl_state are copied from the stored row, never
		// from the request: they are outcomes, not settings.
		$updated = new Mapping(
			$mapping->id,
			$mapping->host,
			$mapping->alias_of,
			null === $post_id ? $mapping->post_id : (int) $post_id,
			$mapping->revision,
			$mapping->verification_state,
			null === $activation ? $mapping->activation_state : ActivationState::from( (string) $activation ),
			$mapping->ssl_state,
			$mapping->integrity_error,
			$mapping->challenge,
			$mapping->challenge_label,
			$mapping->ssl_ownership_origin,
			$mapping->ssl_owner_installation_id,
			$mapping->ssl_provider,
			$mapping->ssl_ref,
			$mapping->ssl_method
		);

		$saved    = $this->repo->save( $updated );
		$response = new \WP_REST_Response( MappingSerializer::resource( $saved ), 200 );
		$response->header( 'ETag', Guard::etag( $saved ) );

		return $response;
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		if ( array() !== ( new AliasResolver( $this->repo ) )->aliases_of( $mapping->id ) ) {
			return self::error( Errors::ALIAS_IN_USE, 'Other mappings alias this one.', 409 );
		}

		// The durable workflow decides: local delete now, or pending_removal.
		if ( ! $this->ssl->delete->request( $mapping ) ) {
			return self::error( Errors::CONFLICT, 'The mapping changed or a mutation is in progress.', 409 );
		}

		return null === $this->repo->by_id( $mapping->id )
			? new \WP_REST_Response( null, 204 )
			: new \WP_REST_Response( MappingSerializer::resource( $this->repo->by_id( $mapping->id ) ), 202 );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\ResourceTest`
Expected: PASS — 11 tests

- [ ] **Step 5: Commit**

```bash
git add src/Rest/ManagementController.php tests/integration/Rest/ResourceTest.php
git commit -m "Read, update, and delete a mapping behind ETag preconditions

Verification and SSL state are copied from the stored row rather than the
request, so no PATCH can make a mapping verified. Deletion hands off to the
durable workflow, which returns 202 while external cleanup is pending."
```

---

### Task 4: Verification probe and challenge rotation

**Files:**
- Modify: `src/Rest/ManagementController.php`
- Test: `tests/integration/Rest/VerificationRoutesTest.php`

**Interfaces:**
- Consumes: `Challenge`, `VerificationService` (Plan 06).
- Produces: the `…/verify` and `…/challenge` routes with working `verify` and `rotate_challenge`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/VerificationRoutesTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Ssl\MutationKind;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class VerificationRoutesTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private int $mapping_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		( new ManagementController( $this->repo, SslServices::production() ) )->register();

		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains' );
		$request->set_body_params(
			array( 'host' => 'example.test', 'post_id' => self::factory()->post->create( array( 'post_status' => 'publish' ) ) )
		);

		$this->mapping_id = (int) rest_do_request( $request )->get_data()['id'];
	}

	/** @param array<string, string> $headers */
	private function post( string $suffix, array $headers = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/domains/' . $this->mapping_id . $suffix );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	private function etag(): string {
		return Guard::etag( $this->repo->by_id( $this->mapping_id ) );
	}

	public function test_verify_does_not_require_if_match(): void {
		$this->assertNotSame( 428, $this->post( '/verify' )->get_status(), 'an idempotent probe needs no precondition' );
	}

	public function test_verify_is_rate_limited(): void {
		$this->post( '/verify' );
		$second = $this->post( '/verify' );

		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( Errors::RATE_LIMITED, $second->get_data()['code'] );
	}

	public function test_verify_reports_the_current_state_without_asserting_success(): void {
		$data = $this->post( '/verify' )->get_data();

		$this->assertSame( 'unverified', $data['verification']['state'] );
	}

	public function test_rotating_the_challenge_requires_if_match(): void {
		$this->assertSame( 428, $this->post( '/challenge' )->get_status() );
	}

	public function test_rotating_the_challenge_resets_verification_and_says_so(): void {
		$before   = $this->repo->by_id( $this->mapping_id );
		$response = $this->post( '/challenge', array( 'if_match' => $this->etag() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( $before->challenge, $this->repo->by_id( $this->mapping_id )?->challenge );
		$this->assertStringContainsString( 'unverified', (string) $response->get_data()['note'] );
	}

	public function test_rotating_the_challenge_is_refused_while_a_mutation_is_in_progress(): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '3', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			),
			array( 'id' => $this->mapping_id )
		);

		$response = $this->post( '/challenge', array( 'if_match' => $this->etag() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::MUTATION_IN_PROGRESS, $response->get_data()['code'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\VerificationRoutesTest`
Expected: FAIL — `Failed asserting that 404 is not identical to 428` (the route does not exist yet)

- [ ] **Step 3: Write minimal implementation**

Add to `ManagementController::register()`:

```php
		$this->register_verification();
```

and:

```php
	private function register_verification(): void {
		foreach (
			array(
				'/domains/(?P<id>[\d]+)/verify'    => 'verify',
				'/domains/(?P<id>[\d]+)/challenge' => 'rotate_challenge',
			) as $route => $handler
		) {
			register_rest_route(
				Errors::NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $handler ),
					'permission_callback' => $this->permission(),
				)
			);
		}
	}

	public function verify( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$key = 'pd_verify_rate_' . $mapping->id;

		if ( false !== get_transient( $key ) ) {
			return self::error( Errors::RATE_LIMITED, 'Verification was requested less than a minute ago.', 429 );
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS );

		// The probe is scheduled; the response reports state, it does not claim it.
		wp_schedule_single_event( time(), 'pd_verify_now', array( $mapping->id ) );

		return new \WP_REST_Response( MappingSerializer::resource( $mapping ), 202 );
	}

	public function rotate_challenge( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		if ( null !== $mapping->ssl_mutation_token ) {
			return self::error( Errors::MUTATION_IN_PROGRESS, 'A provider mutation is in progress.', 409 );
		}

		$rotated = $this->repo->save(
			new Mapping(
				$mapping->id,
				$mapping->host,
				$mapping->alias_of,
				$mapping->post_id,
				$mapping->revision,
				VerificationState::UNVERIFIED,
				$mapping->activation_state,
				$mapping->ssl_state,
				$mapping->integrity_error,
				Challenge::token(),
				$mapping->challenge_label
			)
		);

		$data         = MappingSerializer::resource( $rotated );
		$data['note'] = 'The challenge was rotated; verification is now unverified and the new record must be published.';

		return new \WP_REST_Response( $data, 200 );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\VerificationRoutesTest`
Expected: PASS — 6 tests

- [ ] **Step 5: Commit**

```bash
git add src/Rest/ManagementController.php tests/integration/Rest/VerificationRoutesTest.php
git commit -m "Expose an idempotent verification probe and challenge rotation

The probe is rate limited and reports state rather than asserting it. Rotation
requires a precondition, refuses while a provider mutation is in progress, and
says plainly that verification has reset."
```

---

### Task 5: The validation plan and the SSL operations

**Files:**
- Modify: `src/Rest/ManagementController.php`
- Test: `tests/integration/Rest/SslRoutesTest.php`

**Interfaces:**
- Consumes: `SslServices` (Task 2), `MutationRefusal`, `ValidationPlan` (Plans 07–09).
- Produces: the `…/plan`, `…/ssl`, and `…/ssl/adopt` routes with working `plan`, `provision_ssl`, `change_ssl_method`, `remove_ssl`, and `adopt_ssl`.

Each handler delegates to a service. The controller never opens a lease, never
constructs an authorization, and never touches a driver's mutating methods.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/SslRoutesTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\AdoptionAuthorizer;
use PostDomain\Ssl\AdoptionService;
use PostDomain\Ssl\CreateAuthorizer;
use PostDomain\Ssl\CreateService;
use PostDomain\Ssl\DeletionAuthorizer;
use PostDomain\Ssl\DeletionService;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MethodChangeAuthorizer;
use PostDomain\Ssl\MethodChangeService;
use PostDomain\Ssl\MutationGate;
use PostDomain\Ssl\MutationLease;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_REST_Request;
use WP_UnitTestCase;

final class SslRoutesTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private RecordingDriver $driver;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();
		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_actions( 'pd_test_after_provider_call' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	private function boot( RecordingDriver $driver, DnsOutcome $outcome = DnsOutcome::MATCH ): void {
		$this->driver = $driver;

		$clock = new SystemClock();
		$lease = new MutationLease( $clock );
		$gate  = new MutationGate( $lease, $clock );

		// Installed the way a site would install it, through the one factory.
		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		$proof = new FreshProof(
			new class( $outcome ) implements DnsResolver {
				public function __construct( private readonly DnsOutcome $outcome ) {}

				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( $this->outcome );
				}
			}
		);

		$services = new SslServices(
			new CreateService( $this->repo, new CreateAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate, $clock ),
			new AdoptionService( $this->repo, new AdoptionAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate, $clock ),
			new MethodChangeService( $this->repo, new MethodChangeAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate, $clock ),
			new DeletionService( $this->repo, new DeletionAuthorizer( $this->repo, $proof, $lease, $clock ), $lease, $gate, $clock )
		);

		( new ManagementController( $this->repo, $services ) )->register();
	}

	private function mapping( bool $owned = false ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create( array( 'post_status' => 'publish' ) ), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE,
				$owned ? SslState::ACTIVE : SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge',
				$owned ? OwnershipOrigin::CREATED : null,
				$owned ? Environment::installation_id() : null,
				// A mapping that has never provisioned has no provider at all,
				// which is exactly the case the selection has to answer.
				$owned ? 'recording' : null,
				$owned ? 'ref-1' : null,
				$owned ? 'txt' : null
			)
		);
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $suffix, Mapping $m, array $body = array(), bool $etag = true ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/post-domain/v1/domains/' . $m->id . $suffix );
		$request->set_body_params( $body );

		if ( $etag ) {
			$request->set_header( 'if_match', Guard::etag( $this->repo->by_id( $m->id ) ) );
		}

		return rest_do_request( $request );
	}

	public function test_the_plan_is_read_only_and_needs_no_precondition(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping( true );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/' . $m->id . '/plan' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'dns', $response->get_data() );
		$this->assertArrayHasKey( 'blockers', $response->get_data() );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_provisioning_without_a_precondition_is_428(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		$this->assertSame( 428, $this->request( 'POST', '/ssl', $m, array(), false )->get_status() );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_provisioning_succeeds_and_reports_the_new_state(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 1, $this->driver->create_calls );
		$this->assertSame( 'ref-1', $this->repo->by_id( $m->id )?->ssl_ref );
	}

	public function test_a_refused_provision_is_reported_with_its_precondition(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ), DnsOutcome::NO_RECORD );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::MUTATION_UNAUTHORIZED, $response->get_data()['code'] );
		$this->assertSame( 'fresh_proof_failed', $response->get_data()['data']['precondition'] );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_a_transient_refusal_is_503_with_no_provider_call(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ), DnsOutcome::TRANSIENT );
		$m = $this->mapping();

		$this->assertSame( 503, $this->request( 'POST', '/ssl', $m )->get_status() );
		$this->assertSame( 0, $this->driver->create_calls );
	}

	public function test_changing_the_method_requires_a_supported_method(): void {
		$this->boot( RecordingDriver::confirming_method( 'http' ) );
		$m = $this->mapping( true );

		$response = $this->request( 'PATCH', '/ssl', $m, array( 'method' => 'email' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 0, $this->driver->method_calls );
	}

	public function test_a_confirmed_method_change_is_persisted(): void {
		$this->boot( RecordingDriver::confirming_method( 'http' ) );
		$m = $this->mapping( true );

		$this->assertSame( 200, $this->request( 'PATCH', '/ssl', $m, array( 'method' => 'http' ) )->get_status() );
		$this->assertSame( 'http', $this->repo->by_id( $m->id )?->ssl_method );
	}

	public function test_adoption_requires_confirmation(): void {
		$this->boot( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl/adopt', $m );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::CONFIRMATION_REQUIRED, $response->get_data()['code'] );
	}

	public function test_a_confirmed_adoption_records_adopted_provenance(): void {
		$this->boot( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) );
		$m = $this->mapping();

		$this->assertSame( 200, $this->request( 'POST', '/ssl/adopt', $m, array( 'confirm' => true ) )->get_status() );
		$this->assertSame( OwnershipOrigin::ADOPTED, $this->repo->by_id( $m->id )?->ssl_ownership_origin );
	}

	public function test_removing_ssl_returns_202_and_keeps_the_row(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping( true );

		$this->assertSame( 202, $this->request( 'DELETE', '/ssl', $m )->get_status() );
		$this->assertNotNull( $this->repo->by_id( $m->id ), 'the mapping outlives its certificate' );
	}

	public function test_a_fenced_provision_is_never_reported_as_success(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'ssl_mutation_token' => str_repeat( '7', 32 ),
						'ssl_mutation_phase' => 'recovering',
					),
					array( 'id' => $m->id )
				);
			}
		);

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::FENCED, $response->get_data()['code'] );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_ref );
	}

	public function test_a_fenced_adoption_is_never_reported_as_success(): void {
		$this->boot( RecordingDriver::ambiguous_then_unmarked( 'ref-9' ) );
		$m = $this->mapping();

		add_action(
			'pd_test_after_provider_call',
			static function () use ( $m ): void {
				global $wpdb;

				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array(
						'ssl_mutation_token' => str_repeat( '7', 32 ),
						'ssl_mutation_phase' => 'recovering',
					),
					array( 'id' => $m->id )
				);
			}
		);

		$response = $this->request( 'POST', '/ssl/adopt', $m, array( 'confirm' => true ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::FENCED, $response->get_data()['code'] );
		$this->assertNull( $this->repo->by_id( $m->id )?->ssl_ownership_origin );
	}

	public function test_an_ambiguous_create_answers_202_rather_than_200(): void {
		$this->boot( RecordingDriver::ambiguous_then_absent() );
		$m = $this->mapping();

		$response = $this->request( 'POST', '/ssl', $m );

		$this->assertSame( 202, $response->get_status(), 'the truth is still with the provider' );
	}

	public function test_provisioning_without_a_configured_driver_says_so(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		delete_option( 'pd_settings' );
		DriverFactory::reset();

		$response = $this->request( 'POST', '/ssl', $this->mapping() );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::SSL_NOT_CONFIGURED, $response->get_data()['code'] );
		$this->assertSame( 0, $this->driver->create_calls, 'a NullDriver no-op would have looked like success' );
	}

	public function test_the_plan_reports_a_missing_driver_as_configuration(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();
		delete_option( 'pd_settings' );
		DriverFactory::reset();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains/' . $m->id . '/plan' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::SSL_NOT_CONFIGURED, $response->get_data()['code'] );
	}

	public function test_no_response_carries_a_lease_token_or_a_credential(): void {
		$this->boot( RecordingDriver::succeeding( 'ref-1' ) );
		$m = $this->mapping();

		$encoded = (string) wp_json_encode( $this->request( 'POST', '/ssl', $m )->get_data() );

		foreach ( array( 'api_token', 'ssl_mutation_token', 'lease_token', 'permit' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\SslRoutesTest`
Expected: FAIL — `Failed asserting that 404 is identical to 200`

- [ ] **Step 3: Write minimal implementation**

Add to `ManagementController::register()`:

```php
		$this->register_ssl();
```

and:

```php
	private function register_ssl(): void {
		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'plan' ),
				'permission_callback' => $this->permission(),
			)
		);

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/ssl',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'provision_ssl' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'change_ssl_method' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_ssl' ),
					'permission_callback' => $this->permission(),
				),
			)
		);

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/ssl/adopt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'adopt_ssl' ),
				'permission_callback' => $this->permission(),
			)
		);
	}

	public function plan( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$driver = $this->ssl->driver_for( $mapping );

		if ( $driver instanceof \PostDomain\Ssl\DriverUnavailable ) {
			return self::error(
				'ssl_not_configured' === $driver->reason ? Errors::SSL_NOT_CONFIGURED : Errors::NO_DRIVER,
				sprintf( 'No SSL driver is available for this mapping (%s).', $driver->reason ),
				409
			);
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return self::error( Errors::HOST_TOO_LONG, 'The composed TXT record name is invalid.', 400 );
		}

		$context = \PostDomain\Ssl\SslResourceContext::from_mapping(
			$mapping,
			\PostDomain\Ssl\Environment::installation_id(),
			$name
		);

		$plan = $driver->validation_plan( $context, null );

		return new \WP_REST_Response(
			array(
				'dns'      => $plan->dns,
				'http'     => $plan->http,
				'manual'   => $plan->manual,
				'pending'  => $plan->pending,
				'blockers' => $plan->blockers,
			),
			200
		);
	}

	public function provision_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		return self::ssl_outcome( $this->ssl->create->provision( $mapping ), $mapping->id, 202 );
	}

	public function change_ssl_method( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		return self::ssl_outcome(
			$this->ssl->method->change( $mapping, (string) $request->get_param( 'method' ) ),
			$mapping->id,
			200
		);
	}

	public function adopt_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		$result = $this->ssl->adopt->take_ownership(
			$mapping,
			array(
				'confirm'                 => true === $request->get_param( 'confirm' ),
				'override_foreign_marker' => true === $request->get_param( 'override_foreign_marker' ),
			)
		);

		return self::ssl_outcome( $result, $mapping->id, 200 );
	}

	public function remove_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		// Deletion reports its own vocabulary because a removal can legitimately
		// remain pending at the provider for a long time.
		$outcome = $this->ssl->delete->process( $mapping );

		if ( 'fenced' === $outcome ) {
			return self::error(
				Errors::FENCED,
				'Another worker took over this removal; re-read the mapping before retrying.',
				409
			);
		}

		if ( 'refused' === $outcome ) {
			return self::error( Errors::MUTATION_UNAUTHORIZED, 'The removal was refused before any provider call.', 409 );
		}

		$after = $this->repo->by_id( $mapping->id );

		return null === $after
			? new \WP_REST_Response( null, 204 )
			: new \WP_REST_Response( MappingSerializer::resource( $after ) + array( 'removal' => $outcome ), 202 );
	}

	/**
	 * One translation for every SSL operation. The five dispositions are five
	 * different answers, and collapsing them would let a discarded mutation be
	 * reported as a successful one.
	 */
	private function ssl_outcome(
		\PostDomain\Ssl\MutationResult $result,
		int $mapping_id,
		int $success_status
	): \WP_REST_Response {
		if ( \PostDomain\Ssl\MutationDisposition::REFUSED === $result->disposition ) {
			$refusal = $result->refusal;
			$code    = match ( $refusal?->precondition ) {
				'confirmation_required'            => Errors::CONFIRMATION_REQUIRED,
				'method_unsupported'               => Errors::METHOD_UNSUPPORTED,
				'environment_unresolved'           => Errors::ENVIRONMENT_UNRESOLVED,
				'lease_unavailable'                => Errors::MUTATION_IN_PROGRESS,
				'ssl_not_configured'               => Errors::SSL_NOT_CONFIGURED,
				'driver_not_registered'            => Errors::NO_DRIVER,
				'unowned_resource',
				'foreign_marker_override_required' => Errors::UNOWNED_RESOURCE,
				default                            => Errors::MUTATION_UNAUTHORIZED,
			};

			$response = self::error(
				$code,
				'The operation was refused before any provider call.',
				true === $refusal?->transient ? 503 : 409
			);
			$data     = $response->get_data();

			$data['data']['precondition'] = $refusal?->precondition;
			$response->set_data( $data );

			return $response;
		}

		// Recovery took the row while the provider call was outstanding. Nothing
		// was written and nothing will be retried here, so this is not a success.
		if ( \PostDomain\Ssl\MutationDisposition::FENCED === $result->disposition ) {
			return self::error(
				Errors::FENCED,
				'Another worker took over this mutation; re-read the mapping before retrying.',
				409
			);
		}

		if ( \PostDomain\Ssl\MutationDisposition::CONFIRMED_NOT_PERSISTED === $result->disposition ) {
			return self::error(
				Errors::FINALIZATION_FAILED,
				'The provider confirmed the change but it could not be recorded locally; reconciliation will settle it.',
				409
			);
		}

		if ( \PostDomain\Ssl\MutationDisposition::AMBIGUOUS_RETAINED === $result->disposition ) {
			$mapping = $this->repo->by_id( $mapping_id );

			$response = new \WP_REST_Response(
				null === $mapping
					? array( 'code' => Errors::OUTCOME_AMBIGUOUS, 'message' => $result->note )
					: MappingSerializer::resource( $mapping ) + array( 'note' => $result->note ),
				202
			);

			return $response;
		}

		$mapping = $this->repo->by_id( $mapping_id );

		if ( null === $mapping ) {
			return new \WP_REST_Response( null, 204 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), $success_status );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\SslRoutesTest`
Expected: PASS — 17 tests

- [ ] **Step 5: Commit**

```bash
git add src/Rest/ManagementController.php tests/integration/Rest/SslRoutesTest.php
git commit -m "Drive SSL operations from REST through the services, never the driver

Every handler delegates to a service and re-reads the row for its response. A
refusal names the precondition that stopped it, which is exactly the fact an
operator needs, and carries no token."
```

---

### Task 6: Environment status and resolution

**Files:**
- Modify: `src/Rest/ManagementController.php`
- Test: `tests/integration/Rest/EnvironmentRoutesTest.php`

**Interfaces:**
- Consumes: `Environment` (Plan 07).
- Produces: the `/environment` and `/environment/resolve` routes with working `environment` and `resolve_environment`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/EnvironmentRoutesTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Rest\ManagementController;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class EnvironmentRoutesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		( new ManagementController( new DbRepository(), SslServices::production() ) )->register();
	}

	private function mismatch(): void {
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();
	}

	/** @param array<string, mixed> $body */
	private function resolve( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/post-domain/v1/environment/resolve' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	public function test_a_healthy_environment_reports_not_blocked(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/environment' ) )->get_data();

		$this->assertFalse( $data['blocked'] );
		$this->assertNull( $data['mismatch'] );
	}

	public function test_the_installation_id_is_not_exposed(): void {
		$encoded = (string) wp_json_encode(
			rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/environment' ) )->get_data()
		);

		$this->assertStringNotContainsString( Environment::installation_id(), $encoded );
	}

	public function test_a_mismatch_is_reported_with_both_hosts(): void {
		$this->mismatch();

		$data = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/environment' ) )->get_data();

		$this->assertTrue( $data['blocked'] );
		$this->assertSame( 'old-host.test', $data['mismatch']['stored'] );
	}

	public function test_resolving_as_a_restore_unblocks_and_keeps_identity(): void {
		$this->mismatch();
		$id = Environment::installation_id();

		$this->assertSame( 200, $this->resolve( array( 'resolution' => 'restore' ) )->get_status() );
		$this->assertFalse( Environment::is_blocked() );
		$this->assertSame( $id, Environment::installation_id() );
	}

	public function test_resolving_as_a_clone_replaces_identity(): void {
		$this->mismatch();
		$id = Environment::installation_id();

		$this->assertSame( 200, $this->resolve( array( 'resolution' => 'clone' ) )->get_status() );
		$this->assertNotSame( $id, Environment::installation_id() );
	}

	public function test_an_unknown_resolution_is_rejected(): void {
		$this->mismatch();

		$this->assertSame( 400, $this->resolve( array( 'resolution' => 'whatever' ) )->get_status() );
		$this->assertTrue( Environment::is_blocked(), 'an unrecognized answer resolves nothing' );
	}

	public function test_resolving_a_healthy_environment_is_rejected(): void {
		$this->assertSame( 409, $this->resolve( array( 'resolution' => 'clone' ) )->get_status() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\EnvironmentRoutesTest`
Expected: FAIL — `Failed asserting that 404 is identical to 200`

- [ ] **Step 3: Write minimal implementation**

Add to `ManagementController::register()`:

```php
		$this->register_environment();
```

and:

```php
	private function register_environment(): void {
		register_rest_route(
			Errors::NS,
			'/environment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'environment' ),
				'permission_callback' => $this->permission(),
			)
		);

		register_rest_route(
			Errors::NS,
			'/environment/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve_environment' ),
				'permission_callback' => $this->permission(),
			)
		);
	}

	public function environment( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		$mismatch = get_option( 'pd_environment_mismatch', null );

		// The installation id is an ownership secret in all but name: it is the
		// value a provider marker is matched against. It is never published.
		return new \WP_REST_Response(
			array(
				'blocked'      => \PostDomain\Ssl\Environment::is_blocked(),
				'mismatch'     => is_array( $mismatch ) ? $mismatch : null,
				'primary_host' => \PostDomain\Ssl\Environment::primary_host(),
			),
			200
		);
	}

	public function resolve_environment( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! \PostDomain\Ssl\Environment::is_blocked() ) {
			return self::error( Errors::CONFLICT, 'There is nothing to resolve.', 409 );
		}

		$resolution = (string) $request->get_param( 'resolution' );

		if ( 'restore' === $resolution ) {
			\PostDomain\Ssl\Environment::resolve_as_restore();
		} elseif ( 'clone' === $resolution ) {
			\PostDomain\Ssl\Environment::resolve_as_clone();
		} else {
			return self::error(
				Errors::ENVIRONMENT_UNRESOLVED,
				'Answer with "restore" (same site, new address) or "clone" (a copy).',
				400
			);
		}

		return $this->environment( $request );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\EnvironmentRoutesTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Rest/ManagementController.php tests/integration/Rest/EnvironmentRoutesTest.php
git commit -m "Ask the operator whether a moved site is a restore or a clone

Only those two answers resolve the block; anything else leaves it standing. The
installation id stays out of the response."
```

---

## Gate for Plan 10

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `CollectionTest` proves the namespace is absent from `/wp-json/` discovery
when not registered; `ResourceTest` proves 428 and 412 on missing and stale
preconditions; `SslRoutesTest` proves a refused mutation issues zero provider
calls and leaks no token; and every route this plan registers is answered by a
real handler introduced in the same task, so no stub survives to the gate.
