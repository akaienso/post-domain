# post-domain 10 — Management REST API

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
| `src/Rest/Guard.php` | Capability check and precondition handling |
| `src/Rest/MappingSerializer.php` | Resource shape, `serving` computation, target links |
| `src/Rest/ManagementController.php` | Route registration and handlers |
| `src/Rest/Errors.php` | The error-code vocabulary |

---

### Task 1: Registration, guard, and discovery

**Files:**
- Create: `src/Rest/Guard.php`, `src/Rest/Errors.php`, `src/Rest/ManagementController.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Rest/RegistrationTest.php`

**Interfaces:**
- Consumes: `HostContext` (Plan 03), `MappingRepository` (Plan 02).
- Produces: `PostDomain\Rest\ManagementController::__construct( MappingRepository $repo )` with `::register(): void`; `PostDomain\Rest\Guard::may_manage( \WP_REST_Request $r ): bool|\WP_Error`; `PostDomain\Rest\Errors::NAMESPACE` and the code constants.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/RegistrationTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Rest\ManagementController;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class RegistrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		add_filter( 'rest_url', static fn( string $url ): string => $url );
		do_action( 'rest_api_init' );
	}

	private function register(): void {
		( new ManagementController( new DbRepository() ) )->register();
	}

	public function test_the_routes_exist_when_registered(): void {
		$this->register();

		$routes = rest_get_server()->get_routes();

		foreach (
			array(
				'/post-domain/v1/domains',
				'/post-domain/v1/domains/(?P<id>[\d]+)',
				'/post-domain/v1/domains/(?P<id>[\d]+)/verify',
				'/post-domain/v1/domains/(?P<id>[\d]+)/challenge',
				'/post-domain/v1/domains/(?P<id>[\d]+)/plan',
				'/post-domain/v1/domains/(?P<id>[\d]+)/ssl',
				'/post-domain/v1/domains/(?P<id>[\d]+)/ssl/adopt',
				'/post-domain/v1/environment',
				'/post-domain/v1/environment/resolve',
			) as $route
		) {
			$this->assertArrayHasKey( $route, $routes, "missing route {$route}" );
		}
	}

	public function test_the_namespace_is_absent_from_discovery_when_not_registered(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/' ) );
		$data     = $response->get_data();

		$this->assertNotContains(
			'post-domain/v1',
			$data['namespaces'] ?? array(),
			'on a mapped host the namespace must not be enumerable'
		);
	}

	public function test_an_unauthenticated_request_is_forbidden(): void {
		$this->register();
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_subscriber_is_forbidden(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_administrator_is_allowed(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_the_capability_is_filterable(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		add_filter( 'pd_rest_capability', static fn(): string => 'edit_posts' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) );

		$this->assertSame( 200, $response->get_status() );

		remove_all_filters( 'pd_rest_capability' );
	}

	public function test_an_empty_capability_falls_back_to_manage_options(): void {
		$this->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		add_filter( 'pd_rest_capability', static fn(): string => '' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/post-domain/v1/domains' ) );

		$this->assertSame( 403, $response->get_status() );

		remove_all_filters( 'pd_rest_capability' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\RegistrationTest`
Expected: FAIL — `Error: Class "PostDomain\Rest\ManagementController" not found`

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

Create `src/Rest/ManagementController.php` with registration only for now:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Contracts\MappingRepository;

final class ManagementController {

	public function __construct( private readonly MappingRepository $repo ) {}

	public function register(): void {
		$permission = array( Guard::class, 'may_manage' );

		register_rest_route(
			Errors::NS,
			'/domains',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $permission,
				),
			)
		);

		foreach (
			array(
				'/domains/(?P<id>[\d]+)/verify'    => 'verify',
				'/domains/(?P<id>[\d]+)/challenge' => 'rotate_challenge',
				'/domains/(?P<id>[\d]+)/ssl/adopt' => 'adopt_ssl',
			) as $route => $method
		) {
			register_rest_route(
				Errors::NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $method ),
					'permission_callback' => $permission,
				)
			);
		}

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'plan' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/ssl',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'provision_ssl' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'change_ssl_method' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_ssl' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			Errors::NS,
			'/environment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'environment' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			Errors::NS,
			'/environment/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve_environment' ),
				'permission_callback' => $permission,
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 201 );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 202 );
	}

	public function verify( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function rotate_challenge( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function plan( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function provision_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function change_ssl_method( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function remove_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 202 );
	}

	public function adopt_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function environment( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
	}

	public function resolve_environment( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( array(), 200 );
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

		( new \PostDomain\Rest\ManagementController( $this->repository ) )->register();
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\RegistrationTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Rest/Errors.php src/Rest/Guard.php src/Rest/ManagementController.php src/Plugin.php tests/integration/Rest/RegistrationTest.php
git commit -m "Register management routes only on the primary host

Registration rather than a guard: on a mapped host the namespace does not exist,
so it is not enumerable from a customer domain."
```

---

### Task 2: The resource shape

**Files:**
- Create: `src/Rest/MappingSerializer.php`
- Modify: `src/Rest/ManagementController.php` (`index`, `show`)
- Test: `tests/integration/Rest/SerializerTest.php`

**Interfaces:**
- Consumes: `Mapping`, `AliasResolver` (Plan 02), `ContentPolicy` (Plan 03), `IdnaNormalizer` (Plan 01).
- Produces: `PostDomain\Rest\MappingSerializer::row( Mapping $m ): array` (collection shape) and `::resource( Mapping $m ): array` (individual shape, with `serving`).

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
use PostDomain\Rest\MappingSerializer;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\SerializerTest`
Expected: FAIL — `Error: Class "PostDomain\Rest\MappingSerializer" not found`

- [ ] **Step 3: Write minimal implementation**

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

Replace `ManagementController::index()` and `::show()`:

```php
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

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return new \WP_REST_Response( array( 'code' => 'rest_no_route' ), 404 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), 200 );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\SerializerTest`
Expected: PASS — 13 tests

- [ ] **Step 5: Commit**

```bash
git add src/Rest/MappingSerializer.php src/Rest/ManagementController.php tests/integration/Rest/SerializerTest.php
git commit -m "Serialize mappings with a computed serving state

Target links come from the post type's own rest_base, so a private type simply
has no link. Collections omit serving because computing it runs a filter and a
post lookup per row."
```

---

### Task 3: Create, update, and preconditions

**Files:**
- Modify: `src/Rest/ManagementController.php`
- Test: `tests/integration/Rest/MutationTest.php`

**Interfaces:**
- Consumes: `HostNormalizer`, `AuthorityParser` (Plan 01), `Challenge` (Plan 06), `Guard` (Task 1).
- Produces: working `create`, `update`, `destroy`, `rotate_challenge`, and `verify` handlers.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Rest/MutationTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Rest\Guard;
use PostDomain\Rest\ManagementController;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class MutationTest extends WP_UnitTestCase {

	private DbRepository $repo;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo    = new DbRepository();
		$this->post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		( new ManagementController( $this->repo ) )->register();
	}

	private function post( string $route, array $body, array $headers = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $body );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	private function create_mapping( string $host = 'example.test' ): array {
		return $this->post( '/post-domain/v1/domains', array( 'host' => $host, 'post_id' => $this->post_id ) )->get_data();
	}

	public function test_creating_a_mapping_returns_201_with_a_pending_challenge(): void {
		$response = $this->post( '/post-domain/v1/domains', array( 'host' => 'example.test', 'post_id' => $this->post_id ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'unverified', $response->get_data()['verification']['state'] );
		$this->assertMatchesRegularExpression(
			'/^post-domain-verify=[0-9a-f]{32}$/',
			$response->get_data()['dns_challenge']['value']
		);
	}

	public function test_a_unicode_host_is_stored_as_punycode(): void {
		$data = $this->create_mapping( 'münchen.example' );

		$this->assertSame( 'xn--mnchen-3ya.example', $data['host'] );
	}

	public function test_a_malformed_authority_is_rejected(): void {
		$response = $this->post( '/post-domain/v1/domains', array( 'host' => 'bad host:', 'post_id' => $this->post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::HOST_MALFORMED_AUTHORITY, $response->get_data()['code'] );
	}

	public function test_a_wildcard_host_is_rejected(): void {
		$response = $this->post( '/post-domain/v1/domains', array( 'host' => '*.example.test', 'post_id' => $this->post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::HOST_WILDCARD, $response->get_data()['code'] );
	}

	public function test_a_duplicate_host_is_rejected(): void {
		$this->create_mapping();
		$response = $this->post( '/post-domain/v1/domains', array( 'host' => 'example.test', 'post_id' => $this->post_id ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Errors::HOST_EXISTS, $response->get_data()['code'] );
	}

	public function test_a_host_too_long_for_the_composed_txt_name_is_rejected(): void {
		$host = str_repeat( 'a', 60 ) . '.' . str_repeat( 'b', 60 ) . '.'
			. str_repeat( 'c', 60 ) . '.' . str_repeat( 'd', 55 ) . '.test';

		$response = $this->post( '/post-domain/v1/domains', array( 'host' => $host, 'post_id' => $this->post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertContains(
			$response->get_data()['code'],
			array( Errors::HOST_TOO_LONG, Errors::HOST_INVALID )
		);
	}

	public function test_an_invalid_post_is_rejected(): void {
		$response = $this->post( '/post-domain/v1/domains', array( 'host' => 'example.test', 'post_id' => 999999 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::POST_INVALID, $response->get_data()['code'] );
	}

	public function test_patch_without_if_match_is_428(): void {
		$created = $this->create_mapping();

		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $created['id'] );
		$request->set_body_params( array( 'activation_state' => 'active' ) );

		$this->assertSame( 428, rest_do_request( $request )->get_status() );
	}

	public function test_patch_with_a_stale_if_match_is_412(): void {
		$created = $this->create_mapping();

		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $created['id'] );
		$request->set_header( 'if_match', '"' . $created['id'] . '-99"' );
		$request->set_body_params( array( 'activation_state' => 'active' ) );

		$this->assertSame( 412, rest_do_request( $request )->get_status() );
	}

	public function test_patch_with_a_current_if_match_succeeds(): void {
		$created = $this->create_mapping();
		$mapping = $this->repo->by_id( $created['id'] );

		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $created['id'] );
		$request->set_header( 'if_match', Guard::etag( $mapping ) );
		$request->set_body_params( array( 'activation_state' => 'active' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['activation']['state'] );
	}

	public function test_patch_cannot_set_verification_state(): void {
		$created = $this->create_mapping();
		$mapping = $this->repo->by_id( $created['id'] );

		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $created['id'] );
		$request->set_header( 'if_match', Guard::etag( $mapping ) );
		$request->set_body_params( array( 'verification_state' => 'verified' ) );

		rest_do_request( $request );

		$this->assertSame(
			VerificationState::UNVERIFIED,
			$this->repo->by_id( $created['id'] )?->verification_state,
			'no request makes a mapping verified'
		);
	}

	public function test_patch_rejects_a_post_id_on_an_alias(): void {
		$canonical = $this->create_mapping( 'canonical.test' );
		$alias     = $this->post(
			'/post-domain/v1/domains',
			array( 'host' => 'alias.test', 'alias_of' => $canonical['id'] )
		)->get_data();

		$mapping = $this->repo->by_id( $alias['id'] );
		$request = new WP_REST_Request( 'PATCH', '/post-domain/v1/domains/' . $alias['id'] );
		$request->set_header( 'if_match', Guard::etag( $mapping ) );
		$request->set_body_params( array( 'post_id' => $this->post_id ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Errors::ALIAS_NO_TARGET, $response->get_data()['code'] );
	}

	public function test_rotating_the_challenge_resets_verification_and_says_so(): void {
		$created = $this->create_mapping();
		$mapping = $this->repo->by_id( $created['id'] );

		$response = $this->post(
			'/post-domain/v1/domains/' . $created['id'] . '/challenge',
			array(),
			array( 'if_match' => Guard::etag( $mapping ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( $mapping->challenge, $this->repo->by_id( $created['id'] )?->challenge );
		$this->assertStringContainsString( 'unverified', (string) $response->get_data()['note'] );
	}

	public function test_deleting_a_canonical_with_aliases_is_409(): void {
		$canonical = $this->create_mapping( 'canonical.test' );
		$this->post( '/post-domain/v1/domains', array( 'host' => 'alias.test', 'alias_of' => $canonical['id'] ) );

		$mapping = $this->repo->by_id( $canonical['id'] );
		$request = new WP_REST_Request( 'DELETE', '/post-domain/v1/domains/' . $canonical['id'] );
		$request->set_header( 'if_match', Guard::etag( $mapping ) );

		$this->assertSame( 409, rest_do_request( $request )->get_status() );
	}

	public function test_verify_is_rate_limited(): void {
		$created = $this->create_mapping();

		$this->post( '/post-domain/v1/domains/' . $created['id'] . '/verify', array() );
		$second = $this->post( '/post-domain/v1/domains/' . $created['id'] . '/verify', array() );

		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( Errors::RATE_LIMITED, $second->get_data()['code'] );
	}

	public function test_verify_does_not_require_if_match(): void {
		$created  = $this->create_mapping();
		$response = $this->post( '/post-domain/v1/domains/' . $created['id'] . '/verify', array() );

		$this->assertNotSame( 428, $response->get_status(), 'an idempotent probe needs no precondition' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter Rest\\MutationTest`
Expected: FAIL — `Failed asserting that 200 is identical to 201`

- [ ] **Step 3: Write minimal implementation**

Replace the placeholder handlers in `src/Rest/ManagementController.php`:

```php
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$raw   = (string) $request->get_param( 'host' );
		$alias = $request->get_param( 'alias_of' );

		$authority = ( new \PostDomain\Support\AuthorityParser() )->parse( $raw );

		if ( null === $authority ) {
			return self::error( Errors::HOST_MALFORMED_AUTHORITY, 'That host is not a valid authority.', 400 );
		}

		if ( str_contains( $raw, '*' ) ) {
			return self::error( Errors::HOST_WILDCARD, 'Wildcard hosts are out of scope.', 400 );
		}

		$host = ( new \PostDomain\Support\HostNormalizer( new \PostDomain\Support\IdnaNormalizer() ) )
			->normalize( $authority );

		if ( null === $host ) {
			return self::error( Errors::HOST_INVALID, 'That host cannot be normalized.', 400 );
		}

		if ( null !== $this->repo->by_host( $host ) ) {
			return self::error( Errors::HOST_EXISTS, 'That host is already mapped.', 409 );
		}

		$label = \PostDomain\Verification\Challenge::DEFAULT_LABEL;

		if ( strlen( $host ) > \PostDomain\Verification\Challenge::max_host_length( $label ) ) {
			return self::error(
				Errors::HOST_TOO_LONG,
				sprintf(
					'The composed TXT record name would exceed 253 bytes; this label permits %d bytes of host.',
					\PostDomain\Verification\Challenge::max_host_length( $label )
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
				new \PostDomain\Mapping\Mapping(
					0,
					$host,
					null === $alias ? null : (int) $alias,
					$post_id,
					1,
					\PostDomain\Mapping\VerificationState::UNVERIFIED,
					\PostDomain\Mapping\ActivationState::INACTIVE,
					\PostDomain\Mapping\SslState::NONE,
					null,
					\PostDomain\Verification\Challenge::token(),
					$label
				)
			);
		} catch ( \PostDomain\Mapping\InvalidMapping $e ) {
			return self::error( Errors::ALIAS_CHAIN, $e->getMessage(), 400 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), 201 );
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

		$updated = new \PostDomain\Mapping\Mapping(
			$mapping->id,
			$mapping->host,
			$mapping->alias_of,
			null === $post_id ? $mapping->post_id : (int) $post_id,
			$mapping->revision,
			$mapping->verification_state,
			null === $activation
				? $mapping->activation_state
				: \PostDomain\Mapping\ActivationState::from( (string) $activation ),
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

	public function rotate_challenge( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		if ( null !== $mapping->ssl_mutation_token ) {
			return self::error( Errors::MUTATION_IN_PROGRESS, 'A provider mutation is in progress.', 409 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		$rotated = $this->repo->save(
			new \PostDomain\Mapping\Mapping(
				$mapping->id,
				$mapping->host,
				$mapping->alias_of,
				$mapping->post_id,
				$mapping->revision,
				\PostDomain\Mapping\VerificationState::UNVERIFIED,
				$mapping->activation_state,
				$mapping->ssl_state,
				$mapping->integrity_error,
				\PostDomain\Verification\Challenge::token(),
				\PostDomain\Verification\Challenge::label_for( $mapping )
			)
		);

		$data         = MappingSerializer::resource( $rotated );
		$data['note'] = 'The challenge was rotated; verification is now unverified and the new record must be published.';

		return new \WP_REST_Response( $data, 200 );
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

		try {
			$this->repo->delete( $mapping->id );
		} catch ( \PostDomain\Mapping\AliasInUse $e ) {
			return self::error( Errors::ALIAS_IN_USE, $e->getMessage(), 409 );
		}

		return new \WP_REST_Response( null, 204 );
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

		return new \WP_REST_Response( MappingSerializer::resource( $mapping ), 202 );
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter Rest\\MutationTest`
Expected: PASS — 16 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Rest/ManagementController.php tests/integration/Rest/MutationTest.php
git commit -m "Create and update mappings behind ETag preconditions

Verification and SSL state are not writable here: there is no request that makes
a mapping verified. Rotating a challenge says plainly that verification resets."
```

---

## Gate for Plan 10

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `RegistrationTest` proves the namespace is absent from `/wp-json/`
discovery when not registered, and `MutationTest` proves 428 and 412 on missing
and stale preconditions.
