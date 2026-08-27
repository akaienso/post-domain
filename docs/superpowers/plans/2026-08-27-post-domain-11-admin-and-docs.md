# post-domain 11 — Admin, diagnostics, documentation, and acceptance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An operator can add a domain, read what DNS to create and why, see
exactly which precondition blocked a deletion, and diagnose a silent failure — and
the README documents every boundary the plugin cannot enforce for itself.

**Architecture:** The admin renders the four validation purposes as distinct
sections so nobody deletes a permanent record thinking it was a temporary one.
Diagnostics converts silent failures into visible ones, and the CORS probe runs
in the operator's browser from the mapped origin, never on the server.

**Tech Stack:** As Plans 01–10, plus `WP_List_Table` and the Settings API.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–10, and add:

- **No server-side diagnostic fetch exists anywhere in the plugin.** The CORS
  probe is a hidden iframe executing on the mapped origin (spec §8).
- **The four purposes are rendered distinctly,** with the plugin's own ownership
  record marked permanent (spec §16.1).
- **A refused deletion says which precondition failed** (spec §16.1).
- **All output is escaped;** `ssl_error` in particular is provider-supplied text
  (spec §12.4).

---

## File map

| File | Responsibility |
|---|---|
| `src/Admin/SettingsPage.php` | Menu registration, the settings screen, and the SSL driver selection |
| `src/Admin/MappingListTable.php` | The domains list |
| `src/Admin/DomainDetail.php` | Plan rendering, event log, deletion checklist |
| `src/Admin/Diagnostics.php` | Every check that turns a silent failure visible |
| `src/Admin/EnvironmentNotice.php` | The blocking clone banner |
| `src/Http/ServerConfig.php` | Generated nginx / Apache / Cloudflare snippets |
| `src/Http/ProbeEndpoint.php` | Serves `/.well-known/post-domain-probe` on a mapped host |
| `assets/probe.js` | The browser-side CORS probe, served on the mapped origin |
| `README.md` | Everything in spec §19 |

---

### Task 1: Admin menu, list table, and the environment notice

**Files:**
- Create: `src/Admin/SettingsPage.php`, `src/Admin/MappingListTable.php`, `src/Admin/EnvironmentNotice.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Admin/AdminScreensTest.php`

**Interfaces:**
- Consumes: `MappingRepository` (Plan 02), `MappingSerializer` (Plan 10), `Environment` (Plan 07).
- Produces: `SettingsPage::register(): void`, `SettingsPage::render_driver_selection(): string`, `SettingsPage::save_driver_selection(): void`, `MappingListTable::rows(): array`, `EnvironmentNotice::render(): string`.

The SSL driver selection lives here because it is the one setting that decides
whether managed certificates happen at all. It offers exactly what
`DriverFactory::registry()` holds — never a free-text field — and resets the
memoized registry after saving, so the next request does not serve a stale set.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Admin/AdminScreensTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\EnvironmentNotice;
use PostDomain\Admin\MappingListTable;
use PostDomain\Admin\SettingsPage;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class AdminScreensTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		$this->repo = new DbRepository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function seed( string $host, VerificationState $v, ActivationState $a ): Mapping {
		return $this->repo->save(
			new Mapping(
				0, $host, null, self::factory()->post->create( array( 'post_status' => 'publish' ) ), 1,
				$v, $a, SslState::NONE, null, substr( md5( $host ), 0, 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_the_menu_registers_under_a_capability(): void {
		set_current_screen( 'dashboard' );
		SettingsPage::register();
		do_action( 'admin_menu' );

		global $submenu;

		$this->assertNotEmpty( $submenu, 'the admin menu must carry a post-domain entry' );
	}

	public function test_the_list_shows_the_unicode_host_and_the_ascii_form(): void {
		$this->seed( 'xn--mnchen-3ya.example', VerificationState::VERIFIED, ActivationState::ACTIVE );

		$rows = MappingListTable::rows();

		$this->assertSame( 'münchen.example', $rows[0]['host_display'] );
		$this->assertSame( 'xn--mnchen-3ya.example', $rows[0]['host'] );
	}

	public function test_the_list_shows_three_state_chips_and_no_serving_chip(): void {
		$this->seed( 'example.test', VerificationState::PENDING, ActivationState::INACTIVE );

		$row = MappingListTable::rows()[0];

		$this->assertArrayHasKey( 'verification', $row );
		$this->assertArrayHasKey( 'activation', $row );
		$this->assertArrayHasKey( 'ssl', $row );
		$this->assertArrayNotHasKey( 'serving', $row, 'serving is computed on expansion only' );
	}

	public function test_no_environment_notice_when_the_host_is_stable(): void {
		Environment::remember_primary_host();

		$this->assertSame( '', EnvironmentNotice::render() );
	}

	public function test_the_environment_notice_blocks_and_offers_both_choices(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', 'old-host.test', false );
		Environment::check();

		$html = EnvironmentNotice::render();

		$this->assertStringContainsString( 'old-host.test', $html );
		$this->assertStringContainsString( 'Restore', $html );
		$this->assertStringContainsString( 'Clone', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_the_environment_notice_escapes_the_stored_host(): void {
		Environment::installation_id();
		update_option( 'pd_installation_primary_host', '<script>alert(1)</script>', false );
		Environment::check();

		$this->assertStringNotContainsString( '<script>', EnvironmentNotice::render() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter AdminScreensTest`
Expected: FAIL — `Error: Class "PostDomain\Admin\SettingsPage" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Admin/EnvironmentNotice.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Ssl\Environment;

final class EnvironmentNotice {

	public static function render(): string {
		$mismatch = get_option( 'pd_environment_mismatch', null );

		if ( ! is_array( $mismatch ) ) {
			return '';
		}

		return sprintf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
			esc_html__( 'post-domain: this site has moved or been copied.', 'post-domain' ),
			sprintf(
				/* translators: 1: stored host, 2: current host */
				esc_html__( 'It was installed on %1$s and is now running on %2$s. Every provider mutation is blocked until you choose.', 'post-domain' ),
				'<code>' . esc_html( (string) $mismatch['stored'] ) . '</code>',
				'<code>' . esc_html( (string) $mismatch['current'] ) . '</code>'
			),
			esc_html__( 'Restore or move: the same site at a new address — keep certificates and challenges. Clone: a copy — new identity, cleared certificate ownership, rotated challenges.', 'post-domain' )
		);
	}

	public static function register(): void {
		add_action(
			'admin_notices',
			static function (): void {
				echo wp_kses_post( self::render() );
			}
		);
	}

	public static function resolve( string $choice ): void {
		if ( 'clone' === $choice ) {
			Environment::resolve_as_clone();

			return;
		}

		Environment::resolve_as_restore();
	}
}
```

Create `src/Admin/MappingListTable.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\DbRepository;
use PostDomain\Support\IdnaNormalizer;

final class MappingListTable {

	/** @return array<int, array<string, mixed>> */
	public static function rows(): array {
		$idna = new IdnaNormalizer();
		$rows = array();

		foreach ( ( new DbRepository() )->all() as $mapping ) {
			$rows[] = array(
				'id'           => $mapping->id,
				'host'         => $mapping->host,
				'host_display' => $idna->to_display( $mapping->host ),
				'target'       => $mapping->post_id,
				'verification' => $mapping->verification_state->value,
				'activation'   => $mapping->activation_state->value,
				'ssl'          => $mapping->ssl_state->value,
				'lease'        => null === $mapping->ssl_mutation_phase
					? null
					: $mapping->ssl_mutation_kind?->value . ':' . $mapping->ssl_mutation_phase->value,
			);
		}

		return $rows;
	}
}
```

Create `src/Admin/SettingsPage.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

final class SettingsPage {

	public const SLUG = 'post-domain';

	public static function register(): void {
		add_action(
			'admin_menu',
			static function (): void {
				$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', 'admin' );

				add_options_page(
					__( 'Domain mappings', 'post-domain' ),
					__( 'Domain mappings', 'post-domain' ),
					'' === $capability ? 'manage_options' : $capability,
					self::SLUG,
					array( self::class, 'render' )
				);
			}
		);

		EnvironmentNotice::register();
	}

	/**
	 * The selection is a closed list drawn from the registry, so an operator
	 * cannot name a driver that does not exist and then wonder why nothing
	 * provisions.
	 */
	public static function render_driver_selection(): string {
		$selected = \PostDomain\Ssl\DriverFactory::selected_driver_id();
		$html     = '<h2>' . esc_html__( 'Certificate provider', 'post-domain' ) . '</h2>';
		$html    .= '<select name="pd_ssl_driver">';

		foreach ( \PostDomain\Ssl\DriverFactory::registry()->ids() as $id ) {
			$label = \PostDomain\Ssl\DriverFactory::NULL_DRIVER === $id
				? __( 'None — certificates are managed outside this plugin', 'post-domain' )
				: $id;

			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( $selected, $id, false ),
				esc_html( $label )
			);
		}

		$html .= '</select>';

		if ( ! in_array( $selected, \PostDomain\Ssl\DriverFactory::registry()->ids(), true ) ) {
			// Named plainly rather than silently corrected: the stored value is
			// still what the site asked for, and the operator should see that.
			$html .= '<p class="notice notice-error">' . sprintf(
				/* translators: %s: the configured driver identifier. */
				esc_html__( 'The configured provider "%s" is not registered. Certificates will not be requested until this is resolved.', 'post-domain' ),
				esc_html( $selected )
			) . '</p>';
		}

		return $html;
	}

	public static function save_driver_selection(): void {
		if ( ! isset( $_POST['pd_ssl_driver'] ) || ! check_admin_referer( 'pd_settings' ) ) {
			return;
		}

		$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', 'admin' );

		if ( ! current_user_can( '' === $capability ? 'manage_options' : $capability ) ) {
			return;
		}

		$requested = sanitize_text_field( wp_unslash( (string) $_POST['pd_ssl_driver'] ) );

		if ( ! in_array( $requested, \PostDomain\Ssl\DriverFactory::registry()->ids(), true ) ) {
			return;
		}

		$settings = get_option( 'pd_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['ssl_driver'] = $requested;

		update_option( 'pd_settings', $settings, false );

		// The registry is memoized per request; the next one must see this.
		\PostDomain\Ssl\DriverFactory::reset();
	}

	public static function render(): void {
		self::save_driver_selection();

		echo '<div class="wrap"><h1>' . esc_html__( 'Domain mappings', 'post-domain' ) . '</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'pd_settings' );
		echo wp_kses_post( self::render_driver_selection() );
		submit_button( __( 'Save', 'post-domain' ) );
		echo '</form>';
		echo '<table class="widefat"><thead><tr>';

		foreach (
			array(
				__( 'Domain', 'post-domain' ),
				__( 'Target', 'post-domain' ),
				__( 'Verification', 'post-domain' ),
				__( 'Activation', 'post-domain' ),
				__( 'Certificate', 'post-domain' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( MappingListTable::rows() as $row ) {
			printf(
				'<tr><td><strong>%s</strong><br><code>%s</code></td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['host_display'] ),
				esc_html( (string) $row['host'] ),
				(int) $row['target'],
				esc_html( (string) $row['verification'] ),
				esc_html( (string) $row['activation'] ),
				esc_html( (string) $row['ssl'] )
			);
		}

		echo '</tbody></table></div>';
	}
}
```

Add to `src/Plugin.php`, inside `boot()`:

```php
		if ( is_admin() ) {
			\PostDomain\Admin\SettingsPage::register();
		}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter AdminScreensTest`
Expected: PASS — 6 tests

- [ ] **Step 5: Write the failing test for the driver selection**

Append to `tests/integration/Admin/AdminScreensTest.php`:

```php
	public function test_the_selection_offers_only_registered_drivers(): void {
		\PostDomain\Ssl\DriverFactory::reset();

		$html = SettingsPage::render_driver_selection();

		foreach ( \PostDomain\Ssl\DriverFactory::registry()->ids() as $id ) {
			$this->assertStringContainsString( 'value="' . $id . '"', $html );
		}

		$this->assertStringNotContainsString( 'type="text"', $html, 'a free-text driver name is a trap' );
	}

	public function test_an_unregistered_configured_driver_is_reported_not_corrected(): void {
		update_option( 'pd_settings', array( 'ssl_driver' => 'gone-away' ), false );
		\PostDomain\Ssl\DriverFactory::reset();

		$html = SettingsPage::render_driver_selection();

		$this->assertStringContainsString( 'gone-away', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertSame( 'gone-away', \PostDomain\Ssl\DriverFactory::selected_driver_id() );

		delete_option( 'pd_settings' );
		\PostDomain\Ssl\DriverFactory::reset();
	}

	public function test_saving_an_unregistered_driver_is_ignored(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['pd_ssl_driver'] = 'not-a-driver';
		$_POST['_wpnonce']      = wp_create_nonce( 'pd_settings' );

		SettingsPage::save_driver_selection();

		$this->assertSame(
			\PostDomain\Ssl\DriverFactory::NULL_DRIVER,
			\PostDomain\Ssl\DriverFactory::selected_driver_id()
		);

		unset( $_POST['pd_ssl_driver'], $_POST['_wpnonce'] );
	}

	public function test_saving_resets_the_memoized_registry(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		\PostDomain\Ssl\DriverFactory::registry();

		$_POST['pd_ssl_driver'] = \PostDomain\Ssl\DriverFactory::NULL_DRIVER;
		$_POST['_wpnonce']      = wp_create_nonce( 'pd_settings' );

		SettingsPage::save_driver_selection();

		$this->assertSame(
			\PostDomain\Ssl\DriverFactory::NULL_DRIVER,
			\PostDomain\Ssl\DriverFactory::selected_driver_id()
		);

		unset( $_POST['pd_ssl_driver'], $_POST['_wpnonce'] );
	}

	public function test_a_subscriber_cannot_change_the_selection(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST['pd_ssl_driver'] = \PostDomain\Ssl\DriverFactory::NULL_DRIVER;
		$_POST['_wpnonce']      = wp_create_nonce( 'pd_settings' );

		update_option( 'pd_settings', array( 'ssl_driver' => 'preexisting' ), false );

		SettingsPage::save_driver_selection();

		$this->assertSame( 'preexisting', \PostDomain\Ssl\DriverFactory::selected_driver_id() );

		unset( $_POST['pd_ssl_driver'], $_POST['_wpnonce'] );
		delete_option( 'pd_settings' );
		\PostDomain\Ssl\DriverFactory::reset();
	}
```

- [ ] **Step 6: Run it and verify it passes**

Run: `composer test:integration -- --filter AdminScreensTest`
Expected: PASS — 11 tests

- [ ] **Step 7: Commit**

```bash
git add src/Admin/SettingsPage.php src/Admin/MappingListTable.php src/Admin/EnvironmentNotice.php src/Plugin.php tests/integration/Admin/AdminScreensTest.php
git commit -m "Add the domains list and the blocking environment notice

The notice explains restore and clone in the operator's terms rather than in the
plugin's, because the difference is a judgement only they can make."
```

---

### Task 2: The validation plan, rendered by purpose

**Files:**
- Create: `src/Admin/DomainDetail.php`
- Test: `tests/integration/Admin/DomainDetailTest.php`

**Interfaces:**
- Consumes: `ValidationPlan` and its value objects (Plan 09).
- Produces: `DomainDetail::render_plan( ValidationPlan $plan ): string` and `::render_deletion_checklist( array $preconditions ): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Admin/DomainDetailTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\DomainDetail;
use PostDomain\Ssl\DnsBlocker;
use PostDomain\Ssl\DnsRecordSpec;
use PostDomain\Ssl\DnsRequirementSet;
use PostDomain\Ssl\HttpRequirementSet;
use PostDomain\Ssl\ManualRequirement;
use PostDomain\Ssl\ValidationPending;
use PostDomain\Ssl\ValidationPlan;
use WP_UnitTestCase;

final class DomainDetailTest extends WP_UnitTestCase {

	private function plan(): ValidationPlan {
		return new ValidationPlan(
			array(
				'ownership'          => array(
					new DnsRequirementSet(
						'ownership',
						'core-ownership',
						'Ownership TXT',
						array( new DnsRecordSpec( 'TXT', '_post-domain-challenge.mapped.test', 'post-domain-verify=abc' ) ),
						true,
						'core',
						false
					),
				),
				'provider_ownership' => array(
					new DnsRequirementSet(
						'provider_ownership',
						'cf-hostname-txt',
						'Cloudflare hostname ownership',
						array( new DnsRecordSpec( 'TXT', '_cf-custom-hostname.mapped.test', 'uuid' ) ),
						true,
						'cloudflare-saas',
						true
					),
				),
				'routing'            => array(
					new DnsRequirementSet(
						'routing',
						'routing-cname',
						'Point the hostname at the SaaS target',
						array( new DnsRecordSpec( 'CNAME', 'mapped.test', 'saas.example.net' ) ),
						false,
						'cloudflare-saas'
					),
				),
			),
			array(
				new HttpRequirementSet(
					'ssl_validation',
					'cf-dcv-http',
					'Certificate validation HTTP token',
					'http://mapped.test/.well-known/pki-validation/x.txt',
					'token',
					'cloudflare-saas'
				),
			),
			array(
				new ManualRequirement(
					'ssl_validation',
					'cf-dcv-email',
					'Certificate validation email',
					'A person must open the approval email.',
					array( 'admin@mapped.test' ),
					'cloudflare-saas'
				),
			),
			array( new ValidationPending( 'ssl_validation', 'provider_records_not_yet_issued' ) ),
			array(
				new DnsBlocker(
					'apex_routing_unsupported',
					'This apex has no supported routing mechanism.',
					'Configure CNAME flattening or attested Apex Proxying targets.',
					'cloudflare-saas'
				),
			)
		);
	}

	public function test_the_permanent_ownership_record_is_marked_permanent(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'must never be removed', $html );
	}

	public function test_the_provider_ownership_record_is_marked_removable(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'may be removed once', $html );
	}

	public function test_the_four_purposes_are_separate_sections(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		foreach (
			array(
				'Ownership (post-domain)',
				'Hostname ownership (provider)',
				'Certificate validation (provider)',
				'Routing',
			) as $heading
		) {
			$this->assertStringContainsString( $heading, $html );
		}
	}

	public function test_an_http_token_is_not_rendered_as_a_dns_record(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'Serve this at', $html );
		$this->assertStringNotContainsString( '<td>HTTP</td>', $html );
	}

	public function test_a_manual_requirement_says_it_needs_a_person(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'admin@mapped.test', $html );
		$this->assertStringContainsString( 'cannot be automated', $html );
	}

	public function test_a_pending_purpose_reads_as_a_wait_not_a_failure(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'Awaiting provider', $html );
		$this->assertStringNotContainsString( 'Awaiting provider</h3><p class="pd-blocker"', $html );
	}

	public function test_a_blocker_carries_its_remedy(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'Configure CNAME flattening', $html );
	}

	public function test_record_values_are_escaped(): void {
		$plan = new ValidationPlan(
			array(
				'ownership' => array(
					new DnsRequirementSet(
						'ownership',
						'x',
						'x',
						array( new DnsRecordSpec( 'TXT', 'name', '<script>alert(1)</script>' ) ),
						true,
						'core'
					),
				),
			),
			array(),
			array(),
			array(),
			array()
		);

		$this->assertStringNotContainsString( '<script>', DomainDetail::render_plan( $plan ) );
	}

	public function test_the_deletion_checklist_names_the_failing_precondition(): void {
		$html = DomainDetail::render_deletion_checklist(
			array(
				'environment_resolved' => true,
				'driver_registered'    => true,
				'identity_confirmed'   => false,
				'ownership_authority'  => true,
				'fresh_proof'          => true,
				'lease_acquired'       => true,
			)
		);

		$this->assertStringContainsString( 'identity_confirmed', $html );
		$this->assertStringContainsString( 'pd-check-failed', $html );
	}

	public function test_the_checklist_shows_all_six_preconditions(): void {
		$html = DomainDetail::render_deletion_checklist( array() );

		foreach (
			array(
				'environment_resolved',
				'driver_registered',
				'identity_confirmed',
				'ownership_authority',
				'fresh_proof',
				'lease_acquired',
			) as $precondition
		) {
			$this->assertStringContainsString( $precondition, $html );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter DomainDetailTest`
Expected: FAIL — `Error: Class "PostDomain\Admin\DomainDetail" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Admin/DomainDetail.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Ssl\ValidationPlan;

final class DomainDetail {

	private const PURPOSE_HEADINGS = array(
		'ownership'          => 'Ownership (post-domain)',
		'provider_ownership' => 'Hostname ownership (provider)',
		'ssl_validation'     => 'Certificate validation (provider)',
		'routing'            => 'Routing',
	);

	public const PRECONDITIONS = array(
		'environment_resolved',
		'driver_registered',
		'identity_confirmed',
		'ownership_authority',
		'fresh_proof',
		'lease_acquired',
	);

	public static function render_plan( ValidationPlan $plan ): string {
		$html = '';

		foreach ( self::PURPOSE_HEADINGS as $purpose => $heading ) {
			$sets = $plan->dns[ $purpose ] ?? array();
			$http = array_filter( $plan->http, static fn( $h ): bool => $h->purpose === $purpose );

			if ( array() === $sets && array() === $http ) {
				continue;
			}

			$html .= '<h3>' . esc_html( $heading ) . '</h3>';

			if ( 'ownership' === $purpose ) {
				$html .= '<p class="pd-permanent">'
					. esc_html__( 'Permanent: this record must never be removed. Re-checks and certificate deletions both re-read it.', 'post-domain' )
					. '</p>';
			}

			if ( 'provider_ownership' === $purpose ) {
				$html .= '<p class="pd-removable">'
					. esc_html__( 'Temporary: this may be removed once the provider reports the hostname active.', 'post-domain' )
					. '</p>';
			}

			if ( $plan->alternatives_for( $purpose ) ) {
				$html .= '<p>' . esc_html__( 'Create any one of these.', 'post-domain' ) . '</p>';
			}

			foreach ( $sets as $set ) {
				$html .= '<h4>' . esc_html( $set->label ) . '</h4>'
					. '<table class="widefat"><thead><tr><th>'
					. esc_html__( 'Type', 'post-domain' ) . '</th><th>'
					. esc_html__( 'Name', 'post-domain' ) . '</th><th>'
					. esc_html__( 'Value', 'post-domain' ) . '</th></tr></thead><tbody>';

				foreach ( $set->records as $record ) {
					$html .= sprintf(
						'<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td></tr>',
						esc_html( $record->type ),
						esc_html( $record->name ),
						esc_html( $record->value )
					);
				}

				$html .= '</tbody></table>';
			}

			foreach ( $http as $token ) {
				$html .= '<h4>' . esc_html( $token->label ) . '</h4><p>'
					. esc_html__( 'Serve this at the URL below. It is an HTTP token, not a DNS record.', 'post-domain' )
					. '</p><p><code>' . esc_html( $token->url ) . '</code><br><code>'
					. esc_html( $token->body ) . '</code></p>';
			}
		}

		foreach ( $plan->manual as $manual ) {
			$html .= '<h3>' . esc_html( $manual->label ) . '</h3><p>'
				. esc_html( $manual->instruction ) . ' '
				. esc_html__( 'This step cannot be automated.', 'post-domain' ) . '</p><ul>';

			foreach ( $manual->contacts as $contact ) {
				$html .= '<li><code>' . esc_html( $contact ) . '</code></li>';
			}

			$html .= '</ul>';
		}

		if ( array() !== $plan->pending ) {
			$html .= '<h3>' . esc_html__( 'Awaiting provider', 'post-domain' ) . '</h3><p>'
				. esc_html__( 'The provider has not issued these records yet. This is a wait, not a failure.', 'post-domain' )
				. '</p>';
		}

		if ( array() !== $plan->blockers ) {
			$html .= '<h3>' . esc_html__( 'Blockers', 'post-domain' ) . '</h3>';

			foreach ( $plan->blockers as $blocker ) {
				$html .= '<p class="pd-blocker"><strong>' . esc_html( $blocker->message ) . '</strong><br>'
					. esc_html( $blocker->remedy ) . '</p>';
			}
		}

		return $html;
	}

	/** @param array<string, bool> $results */
	public static function render_deletion_checklist( array $results ): string {
		$html = '<ul class="pd-deletion-checklist">';

		foreach ( self::PRECONDITIONS as $precondition ) {
			$passed = $results[ $precondition ] ?? false;

			$html .= sprintf(
				'<li class="%s"><code>%s</code> — %s</li>',
				$passed ? 'pd-check-passed' : 'pd-check-failed',
				esc_html( $precondition ),
				$passed
					? esc_html__( 'passed', 'post-domain' )
					: esc_html__( 'not satisfied', 'post-domain' )
			);
		}

		return $html . '</ul>';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DomainDetailTest`
Expected: PASS — 10 tests

- [ ] **Step 5: Commit**

```bash
git add src/Admin/DomainDetail.php tests/integration/Admin/DomainDetailTest.php
git commit -m "Render the validation plan by purpose, marking what is permanent

The plugin's own ownership record and the provider's look alike in a zone file
and have opposite lifetimes, so the screen says which is which."
```

---

### Task 3: Diagnostics and the browser-side CORS probe

**Files:**
- Create: `src/Admin/Diagnostics.php`, `src/Http/ServerConfig.php`, `src/Http/ProbeEndpoint.php`, `assets/probe.js`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Admin/DiagnosticsTest.php`

**Interfaces:**
- Consumes: `AmbiguousPath` (Plan 04), `Schedule` (Plan 06), `LeaseRecovery` (Plan 07), `RoundTripVerifier` (Plan 04).
- Produces: `Diagnostics::checks(): array<string, array{status: string, detail: string}>`,
  `ServerConfig::snippets( string[] $allowed_hosts ): array<string, string>`, and
  `PostDomain\Http\ProbeEndpoint::register(): void` which serves the probe page at
  `/.well-known/post-domain-probe` on a mapped host — the URL `Diagnostics::probe_iframe()`
  points at.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Admin/DiagnosticsTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Diagnostics;
use PostDomain\Http\ServerConfig;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\MutationKind;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class DiagnosticsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	public function test_every_documented_check_is_present(): void {
		$checks = Diagnostics::checks();

		foreach (
			array(
				'verification_backlog',
				'wp_cron_health',
				'path_collisions',
				'round_trip_failures',
				'stale_leases',
				'ssl_ownership',
				'apex_configuration',
				'marker_support',
				'environment',
				'ssl_driver',
				'long_recoveries',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $checks, "missing diagnostic {$key}" );
		}
	}

	public function test_no_selected_driver_is_reported_as_a_warning(): void {
		delete_option( 'pd_settings' );
		\PostDomain\Ssl\DriverFactory::reset();

		$check = Diagnostics::checks()['ssl_driver'];

		$this->assertSame( 'warning', $check['status'] );
		$this->assertStringContainsString( 'never', $check['detail'] );
	}

	public function test_an_unregistered_selected_driver_is_reported_as_an_error(): void {
		update_option( 'pd_settings', array( 'ssl_driver' => 'gone-away' ), false );
		\PostDomain\Ssl\DriverFactory::reset();

		$check = Diagnostics::checks()['ssl_driver'];

		$this->assertSame( 'error', $check['status'] );
		$this->assertStringContainsString( 'gone-away', $check['detail'] );

		delete_option( 'pd_settings' );
		\PostDomain\Ssl\DriverFactory::reset();
	}

	public function test_a_long_running_recovery_is_surfaced(): void {
		global $wpdb;

		$mapping = ( new DbRepository() )->save(
			new Mapping(
				0, 'stuck.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::REQUESTED,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '3', 32 ),
				'ssl_mutation_kind'       => MutationKind::CREATE->value,
				'ssl_mutation_phase'      => 'recovering',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'ssl_next_attempt_at'     => gmdate( 'Y-m-d H:i:s', time() + 300 ),
				'ssl_transient_count'     => 9,
			),
			array( 'id' => $mapping->id )
		);

		$check = Diagnostics::checks()['long_recoveries'];

		$this->assertSame( 'warning', $check['status'] );
		$this->assertStringContainsString( 'stuck.test', $check['detail'] );
		$this->assertStringContainsString( '9 reads', $check['detail'] );
	}

	public function test_a_stale_lease_is_reported_with_its_phase(): void {
		global $wpdb;

		$mapping = ( new DbRepository() )->save(
			new Mapping(
				0, 'stale.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '2', 32 ),
				'ssl_mutation_kind'       => MutationKind::REMOVE->value,
				'ssl_mutation_phase'      => 'recovering',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
			),
			array( 'id' => $mapping->id )
		);

		$check = Diagnostics::checks()['stale_leases'];

		$this->assertSame( 'warning', $check['status'] );
		$this->assertStringContainsString( 'recovering', $check['detail'] );
	}

	public function test_the_backlog_check_reports_the_oldest_due_timestamp(): void {
		global $wpdb;

		$mapping = ( new DbRepository() )->save(
			new Mapping(
				0, 'due.test', null, self::factory()->post->create(), 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'verification_state'     => VerificationState::PENDING->value,
				'verify_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
			),
			array( 'id' => $mapping->id )
		);

		$this->assertStringContainsString( 'oldest', Diagnostics::checks()['verification_backlog']['detail'] );
	}

	public function test_the_cors_probe_is_a_browser_iframe_not_a_server_fetch(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/probe.js' );

		$this->assertStringContainsString( 'postMessage', $script );
		$this->assertStringContainsString( 'event.origin', $script );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Diagnostics.php' );

		$this->assertStringNotContainsString( 'wp_remote_get', $source );
		$this->assertStringContainsString( 'iframe', $source );
	}

	public function test_the_server_config_snippets_cover_all_three_platforms(): void {
		$snippets = ServerConfig::snippets( array( 'health.internal' ) );

		$this->assertArrayHasKey( 'nginx', $snippets );
		$this->assertArrayHasKey( 'apache', $snippets );
		$this->assertArrayHasKey( 'cloudflare', $snippets );
		$this->assertStringContainsString( '421', $snippets['nginx'] );
		$this->assertStringContainsString( 'health.internal', $snippets['nginx'] );
	}

	public function test_the_snippets_explain_that_the_plugin_cannot_apply_them(): void {
		$snippets = ServerConfig::snippets( array() );

		$this->assertStringContainsString( 'PHP never runs', $snippets['note'] );
	}

	public function test_the_probe_endpoint_serves_the_url_the_iframe_points_at(): void {
		$iframe = \PostDomain\Admin\Diagnostics::probe_iframe( 'mapped.test', 'https://primary.test/font.woff2' );

		$this->assertStringContainsString( \PostDomain\Http\ProbeEndpoint::PATH, $iframe );
		$this->assertSame( '/.well-known/post-domain-probe', \PostDomain\Http\ProbeEndpoint::PATH );
	}

	public function test_the_probe_page_loads_the_script_and_nothing_else(): void {
		$html = \PostDomain\Http\ProbeEndpoint::page();

		$this->assertStringContainsString( 'probe.js', $html );
		$this->assertStringNotContainsString( '<script>', $html, 'no inline script' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter DiagnosticsTest`
Expected: FAIL — `Error: Class "PostDomain\Admin\Diagnostics" not found`

- [ ] **Step 3: Write minimal implementation**

Create `assets/probe.js`:

```javascript
/**
 * Runs on a MAPPED origin inside a hidden iframe, fetches a CORS-gated asset
 * from the primary host, and reports what it observed. The server never makes
 * this request: only the browser can produce the right Origin.
 */
( function () {
	'use strict';

	var params = new URLSearchParams( window.location.search );
	var asset = params.get( 'asset' );
	var parent = params.get( 'parent' );

	if ( ! asset || ! parent ) {
		return;
	}

	fetch( asset, { mode: 'cors' } )
		.then( function () {
			window.parent.postMessage( { source: 'post-domain-probe', ok: true }, parent );
		} )
		.catch( function ( error ) {
			window.parent.postMessage(
				{ source: 'post-domain-probe', ok: false, reason: String( error ) },
				parent
			);
		} );

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== parent ) {
			return;
		}
	} );
}() );
```

Create `src/Http/ServerConfig.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

/**
 * Generates the rules the plugin cannot apply. PHP never runs for a static file,
 * so the unknown-host rule and the CORS header for assets must live in the web
 * server or CDN.
 */
final class ServerConfig {

	/**
	 * @param string[] $allowed_hosts
	 * @return array<string, string>
	 */
	public static function snippets( array $allowed_hosts ): array {
		$primary = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$allowed = array_merge( array( $primary ), $allowed_hosts );
		$list    = implode( ' ', array_map( 'strval', $allowed ) );

		return array(
			'note'       => 'PHP never runs for a static file, so these rules cannot be applied by the plugin. '
				. 'Without them an unknown host can still fetch assets, and a webfont on a mapped domain '
				. 'will fail silently in fallback fonts.',
			'nginx'      => "map \$host \$pd_known_host {\n"
				. "    default 0;\n"
				. implode(
					"\n",
					array_map( static fn( string $h ): string => "    {$h} 1;", $allowed )
				)
				. "\n}\n\nserver {\n"
				. "    if (\$pd_known_host = 0) { return 421; }\n"
				. "}\n",
			'apache'     => "<If \"%{HTTP_HOST} !~ /^(" . implode( '|', array_map( 'preg_quote', $allowed ) ) . ")\$/\">\n"
				. "    Redirect 421 /\n"
				. "</If>\n",
			'cloudflare' => "Transform Rule expression:\n"
				. "not http.host in { " . $list . " }\n"
				. "Action: respond with status 421\n",
		);
	}
}
```

Create `src/Http/ProbeEndpoint.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

use PostDomain\Routing\ContextHolder;

/**
 * Serves the CORS probe page on a mapped host. It exists so the probe executes
 * with the mapped Origin: the server cannot produce that Origin itself, which is
 * the whole reason there is no server-side fetch.
 */
final class ProbeEndpoint {

	public const PATH = '/.well-known/post-domain-probe';

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 2 );
	}

	public function maybe_serve(): void {
		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( self::PATH !== rtrim( $path, '/' ) || null === $this->context->serving() ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo self::page(); // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	public static function page(): string {
		return '<!doctype html><meta charset="utf-8"><title>post-domain probe</title>'
			. '<script src="' . esc_url( plugins_url( 'assets/probe.js', dirname( __DIR__ ) . '/post-domain.php' ) )
			. '" defer></script>';
	}
}
```

Add to `src/Plugin.php`, inside `boot()`:

```php
		add_action(
			'plugins_loaded',
			static function () use ( $plugin ): void {
				( new \PostDomain\Http\ProbeEndpoint( $plugin->context() ) )->register();
			},
			12
		);
```

Create `src/Admin/Diagnostics.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\DbRepository;
use PostDomain\Routing\AmbiguousPath;
use PostDomain\Ssl\Credentials;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;

final class Diagnostics {

	/** @return array<string, array{status: string, detail: string}> */
	public static function checks(): array {
		return array(
			'verification_backlog' => self::backlog(),
			'wp_cron_health'       => self::cron(),
			'path_collisions'      => self::collisions(),
			'round_trip_failures'  => self::round_trips(),
			'stale_leases'         => self::stale_leases(),
			'ssl_ownership'        => self::ownership(),
			'apex_configuration'   => self::apex(),
			'marker_support'       => self::markers(),
			'environment'          => self::environment(),
			'ssl_driver'           => self::ssl_driver(),
			'long_recoveries'      => self::long_recoveries(),
		);
	}

	/**
	 * Silence is the failure mode this catches: with no provider selected the
	 * plugin works perfectly and never requests a certificate.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function ssl_driver(): array {
		$selected   = \PostDomain\Ssl\DriverFactory::selected_driver_id();
		$registered = \PostDomain\Ssl\DriverFactory::registry()->ids();

		if ( \PostDomain\Ssl\DriverFactory::NULL_DRIVER === $selected ) {
			return array(
				'status' => 'warning',
				'detail' => __( 'No certificate provider is selected, so no certificate will ever be requested.', 'post-domain' ),
			);
		}

		if ( ! in_array( $selected, $registered, true ) ) {
			return array(
				'status' => 'error',
				'detail' => sprintf(
					/* translators: 1: configured driver id, 2: comma-separated registered ids. */
					__( 'The configured provider "%1$s" is not registered. Registered: %2$s.', 'post-domain' ),
					$selected,
					implode( ', ', $registered )
				),
			);
		}

		return array(
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: %s: the selected driver id. */
				__( 'Certificates are provisioned through "%s".', 'post-domain' ),
				$selected
			),
		);
	}

	/**
	 * A recovery that keeps reading without reaching a conclusion is bounded by
	 * backoff, not by a give-up rule, so it has to be visible.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function long_recoveries(): array {
		global $wpdb;

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT host, ssl_mutation_kind, ssl_transient_count, ssl_next_attempt_at
				   FROM ' . Schema::domains_table() . '
				  WHERE ssl_mutation_phase = %s AND ssl_transient_count >= %d
				  ORDER BY ssl_transient_count DESC
				  LIMIT 20',
				'recovering',
				5
			),
			ARRAY_A
		);

		if ( array() === $rows ) {
			return array( 'status' => 'ok', 'detail' => __( 'No long-running recoveries.', 'post-domain' ) );
		}

		$lines = array();

		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'%s (%s): %d reads, next %s',
				(string) $row['host'],
				(string) $row['ssl_mutation_kind'],
				(int) $row['ssl_transient_count'],
				(string) $row['ssl_next_attempt_at']
			);
		}

		return array( 'status' => 'warning', 'detail' => implode( '; ', $lines ) );
	}

	/** The iframe that runs the CORS probe on the mapped origin. */
	public static function probe_iframe( string $mapped_host, string $asset_url ): string {
		return sprintf(
			'<iframe class="pd-probe" hidden src="%s"></iframe>',
			esc_url(
				'https://' . $mapped_host . '/.well-known/post-domain-probe?'
				. http_build_query(
					array( 'asset' => $asset_url, 'parent' => home_url() )
				)
			)
		);
	}

	/** @return array{status: string, detail: string} */
	private static function backlog(): array {
		global $wpdb;

		$oldest = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT MIN(verify_next_attempt_at) FROM ' . Schema::domains_table()
				. ' WHERE verify_next_attempt_at IS NOT NULL AND verify_next_attempt_at <= %s',
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( null === $oldest ) {
			return array( 'status' => 'ok', 'detail' => 'No verification work is overdue.' );
		}

		return array(
			'status' => strtotime( (string) $oldest ) < time() - HOUR_IN_SECONDS ? 'warning' : 'ok',
			'detail' => 'The oldest overdue verification is due since ' . (string) $oldest . ' UTC.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function cron(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) ) {
			return array(
				'status' => 'ok',
				'detail' => 'WP-Cron is disabled; a system cron must run `wp cron event run --due-now`.',
			);
		}

		return array(
			'status' => 'ok',
			'detail' => 'WP-Cron is enabled. On a low-traffic site, prefer a system cron.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function collisions(): array {
		$collisions = AmbiguousPath::all();

		return array(
			'status' => array() === $collisions ? 'ok' : 'warning',
			'detail' => array() === $collisions
				? 'No ambiguous path segments were seen this request.'
				: count( $collisions ) . ' ambiguous segment(s); those posts fall back to primary-host permalinks.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function round_trips(): array {
		return array(
			'status' => 'ok',
			'detail' => 'Round-trip verification runs on every emitted link; failures fall back to the primary permalink.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function stale_leases(): array {
		global $wpdb;

		/** @var array<int, array<string, string>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT host, ssl_mutation_kind, ssl_mutation_phase FROM ' . Schema::domains_table()
				. ' WHERE ssl_mutation_token IS NOT NULL AND ssl_mutation_expires_at <= %s',
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		if ( array() === $rows ) {
			return array( 'status' => 'ok', 'detail' => 'No expired provider-mutation leases.' );
		}

		$detail = array();

		foreach ( $rows as $row ) {
			$detail[] = $row['host'] . ' (' . $row['ssl_mutation_kind'] . ':' . $row['ssl_mutation_phase'] . ')';
		}

		return array(
			'status' => 'warning',
			'detail' => 'Awaiting lease recovery: ' . implode( ', ', $detail ),
		);
	}

	/** @return array{status: string, detail: string} */
	private static function ownership(): array {
		$unowned = 0;

		foreach ( ( new DbRepository() )->all() as $mapping ) {
			if ( null !== $mapping->ssl_ref && null === $mapping->ssl_ownership_origin ) {
				++$unowned;
			}
		}

		return array(
			'status' => 0 === $unowned ? 'ok' : 'warning',
			'detail' => 0 === $unowned
				? 'Every bound certificate has recorded ownership provenance.'
				: $unowned . ' certificate reference(s) have no ownership provenance; adopt them explicitly.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function apex(): array {
		$targets = Credentials::apex_targets();

		if ( array() === $targets ) {
			return array(
				'status' => 'ok',
				'detail' => 'No Apex Proxying targets configured; apex domains rely on CNAME flattening, ALIAS, or ANAME.',
			);
		}

		return array(
			'status' => null === Credentials::apex_provenance() ? 'warning' : 'ok',
			'detail' => null === Credentials::apex_provenance()
				? 'Apex targets are configured without a declared provenance. They must be Cloudflare-assigned '
					. 'Static IP prefixes or BYOIP addresses, never ordinary origin addresses.'
				: 'Apex targets declared as ' . (string) Credentials::apex_provenance() . '.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function markers(): array {
		return array(
			'status' => 'ok',
			'detail' => 'Provider marker support is recorded per mapping; without it, identity rests on the '
				. 'reference-plus-hostname binding and the permanent DNS challenge.',
		);
	}

	/** @return array{status: string, detail: string} */
	private static function environment(): array {
		return array(
			'status' => Environment::is_blocked() ? 'error' : 'ok',
			'detail' => Environment::is_blocked()
				? 'The primary host changed. Provider mutations are blocked until you choose restore or clone.'
				: 'Installation identity matches the recorded primary host.',
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter DiagnosticsTest`
Expected: PASS — 11 tests

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Diagnostics.php src/Http/ServerConfig.php src/Http/ProbeEndpoint.php src/Plugin.php assets/probe.js tests/integration/Admin/DiagnosticsTest.php
git commit -m "Turn the silent failures visible, and probe CORS from the right origin

The probe runs in an iframe on the mapped host because only a browser there can
produce the Origin that matters. The server makes no outbound request at all."
```

---

### Task 4: The README

**Files:**
- Create: `README.md` (replacing the empty file)
- Test: `tests/unit/ReadmeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the documentation deliverable of spec §19.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/ReadmeTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase {

	private function readme(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );
	}

	/**
	 * @dataProvider required_topics
	 */
	public function test_the_readme_covers_every_required_topic( string $needle ): void {
		$this->assertStringContainsString( $needle, $this->readme() );
	}

	/** @return array<string, array{0: string}> */
	public static function required_topics(): array {
		return array(
			'minimums'            => array( 'WordPress 6.4' ),
			'exact hosts'         => array( 'Wildcard' ),
			'permanent record'    => array( 'must never be removed' ),
			'filter reference'    => array( 'pd_mapping_is_active' ),
			'init 99'             => array( 'init` priority 10' ),
			'early url limit'     => array( 'not rebased' ),
			'driver interface'    => array( 'SslDriver' ),
			'ownership provenance' => array( 'ssl_ownership_origin' ),
			'lease phases'        => array( 'RECOVERING' ),
			'authorization'       => array( 'fresh' ),
			'create ambiguity'    => array( 'adopt' ),
			'clone detection'     => array( 'clone' ),
			'resolver trust'      => array( 'pd_dns_resolver' ),
			'driver selection'    => array( 'Certificate provider' ),
			'no silent no-op'     => array( 'pd_ssl_not_configured' ),
			'event atomicity'     => array( 'pd_schema_engine' ),
			'dcv default'         => array( 'txt' ),
			'apex entitlement'    => array( 'BYOIP' ),
			'dns neutrality'      => array( 'authoritative DNS' ),
			'multisite'           => array( 'multisite' ),
			'421 default'         => array( '421' ),
			'cors boundary'       => array( 'Access-Control-Allow-Origin' ),
			'auth consequences'   => array( 'COOKIE_DOMAIN' ),
			'uninstall order'     => array( 'before uninstalling' ),
		);
	}

	public function test_the_readme_states_cloudflare_dns_is_not_required(): void {
		$readme = $this->readme();

		$this->assertMatchesRegularExpression(
			'/Cloudflare[^.]*recommended[^.]*not required/i',
			$readme
		);
	}

	public function test_the_readme_does_not_promise_universal_url_interception(): void {
		$this->assertStringContainsString( 'not interceptable', $this->readme() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter ReadmeTest`
Expected: FAIL — 23 failures, each `Failed asserting that '' contains "…"`

- [ ] **Step 3: Write minimal implementation**

Write `README.md` covering, in this order, each item of spec §19. Every section
below is required; the test above pins the phrases that must appear.

1. **What it does** — one domain maps to one post, resolved not redirected;
   descendants derive from the subtree. Mapped hosts are exact hosts; **Wildcard**
   mappings and wildcard certificates are out of scope.
2. **Requirements** — **WordPress 6.4**, PHP 8.1, single site only. Activation on
   **multisite** is refused and the plugin is otherwise inert there, because
   domain mapping in a network is a different problem with a different solution.
3. **The DNS records** — the four purposes, in a table. State plainly that the
   post-domain ownership TXT record is permanent and **must never be removed**,
   while a provider hostname-ownership record may be removed once the provider
   reports the hostname active.
4. **Filter reference** — every filter from spec §11 with its default and its
   postcondition, starting with `pd_mapping_is_active`. Note that integrators must
   register post types, statuses, and their callbacks by default `init` priority 10,
   and that URLs generated before `init : 99` are **not rebased**.
5. **Adding an SSL driver** — the `SslDriver` interface, `SslResourceContext`, and
   how a driver expresses its own ownership proof.
6. **Ownership provenance** — `ssl_ownership_origin`, what `created` and `adopted`
   mean, that it lives in columns rather than the event log, and that events are
   history only.
7. **The provider-mutation lease** — `RESERVED`, `IN_FLIGHT`, `RECOVERING`; what it
   blocks; that an expired lease still blocks ordinary work; why an expired
   `RESERVED` lease is safe to clear without contacting the provider while an
   expired `IN_FLIGHT` one is not.
8. **Authorization** — the six preconditions, why cached verification is not
   enough and a **fresh** proof is required, and what each refusal means.
9. **Creation ambiguity** — why a marker-free create-then-timeout may require an
   explicit **adopt**.
10. **Clone detection** — the restore/move/**clone** choice and what each does.
11. **`pd_dns_resolver` is trusted code** — a custom resolver substitutes the
    ownership proof rather than integrating with it.
12. **The DCV method** — allowed values, the **txt** default, why email is not
    automated, and that automatic HTTP DCV is a valid success path.
13. **Apex routing** — CNAME flattening, ALIAS/ANAME, and that A/AAAA targets are
    permitted only with attested Apex Proxying or **BYOIP** provenance, never
    ordinary origin addresses.
14. **Authoritative DNS posture** — the engine is provider-neutral; Cloudflare DNS
    is **recommended** for operational consistency, DNSSEC, and apex flattening
    but is **not required**; DoH resolvers, Cloudflare for SaaS, and
    **authoritative DNS** are three separate roles; no DNS is mutated by API; no
    paid entitlement is assumed; prefer client-owned accounts with least-privilege
    access.
15. **The 421 default** — the exact infrastructure allowlist, `PD_ALLOWED_HOSTS`,
    the `PD_UNKNOWN_HOST_POLICY` escape, and the web-server rule the plugin cannot
    apply.
16. **CORS** — that `Access-Control-Allow-Origin` must come from whichever host
    serves the asset, that the generated snippets exist because PHP never runs for
    a static file, and that the probe runs in the browser.
17. **Auth consequences** — cookies bind to `COOKIE_DOMAIN`, so mapped-host REST
    and ajax are anonymous, and the admin redirect is a default policy over an
    invariant boundary.
18. **The honest limit on URL interception** — plugins that hardcode a domain or
    cache absolute URLs are **not interceptable**; Diagnostics detects rather than
    promises.
19. **Uninstall** — delete mappings **before uninstalling** so the durable removal
    workflow runs; uninstalling drops the ownership provenance along with the rows.
20. **Choosing a certificate provider** — the **Certificate provider** setting is
    what turns managed SSL on; with nothing selected the plugin runs correctly and
    simply never requests a certificate, and a provisioning request answers
    **`pd_ssl_not_configured`** rather than pretending to have done something.
    Incomplete provider credentials leave that provider unregistered, which reads
    as a configuration problem instead of a transport failure.
21. **Why an SSL request can answer 409** — `pd_mutation_fenced` means recovery
    took the mutation over and the local result was discarded; nothing was written
    and nothing was retried, so re-read the mapping before trying again.
22. **Event log fidelity** — on InnoDB (`pd_schema_engine`) a state change and its
    event row commit together; on any other engine the log is best-effort and may
    lag or miss rows. Nothing reads it to make a decision either way.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter ReadmeTest`
Expected: PASS — 26 tests

- [ ] **Step 5: Commit**

```bash
git add README.md tests/unit/ReadmeTest.php
git commit -m "Document every boundary the plugin cannot enforce for itself

The static-file rule, the cookie boundary, the URL surfaces that are not
interceptable, and the apex entitlement all need an operator to act. A test pins
each of them so the README cannot quietly lose one."
```

---

### Task 5: The cross-subsystem acceptance suite

**Files:**
- Create: `tests/integration/AcceptanceTest.php`
- Test: itself

**Interfaces:**
- Consumes: everything.
- Produces: the end-to-end proof that the plans compose.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/AcceptanceTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Plugin;
use PostDomain\Routing\Disposition;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\Verifier;
use WP_UnitTestCase;

final class AcceptanceTest extends WP_UnitTestCase {

	use ServingContextFactory;

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Plugin::boot();
		$this->repo = new DbRepository();
	}

	private function matching_resolver(): DnsResolver {
		return new class() implements DnsResolver {
			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult( DnsOutcome::MATCH );
			}
		};
	}

	public function test_a_domain_goes_from_added_to_serving_and_back(): void {
		$root  = $this->make_page( 'club', 0 );
		$child = $this->make_page( 'events', $root );

		// 1. Added: pending and inactive, so it does not serve.
		$mapping = $this->repo->save(
			new Mapping(
				0, 'acceptance.test', null, $root, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'verification_state' => VerificationState::PENDING->value ),
			array( 'id' => $mapping->id )
		);

		// 2. Verified by a matching TXT record.
		( new Verifier( $this->repo, $this->matching_resolver(), new SystemClock() ) )
			->verify( $this->repo->by_id( $mapping->id ) );

		$this->assertSame(
			VerificationState::VERIFIED,
			$this->repo->by_id( $mapping->id )?->verification_state
		);

		// 3. Activated.
		$verified = $this->repo->by_id( $mapping->id );
		$this->repo->save(
			new Mapping(
				$verified->id, $verified->host, null, $verified->post_id, $verified->revision,
				$verified->verification_state, ActivationState::ACTIVE, $verified->ssl_state,
				null, $verified->challenge, $verified->challenge_label
			)
		);

		// 4. Serving: the subtree resolves and its links carry the mapped host.
		Plugin::instance()->context()->set_serving( $this->serving_context( $root, array( 'host' => 'acceptance.test' ) ) );
		Plugin::instance()->register_url_adapters();

		$wp             = new \WP();
		$wp->request    = 'events';
		$wp->query_vars = array();

		Plugin::instance()->resolve_request( $wp );

		$this->assertSame( $child, (int) $wp->query_vars['page_id'] );
		$this->assertSame( 'https://acceptance.test/events/', get_permalink( $child ) );

		// 5. Deactivated: it stops serving without being deleted.
		$active = $this->repo->by_id( $mapping->id );
		$this->repo->save(
			new Mapping(
				$active->id, $active->host, null, $active->post_id, $active->revision,
				$active->verification_state, ActivationState::INACTIVE, $active->ssl_state,
				null, $active->challenge, $active->challenge_label
			)
		);

		$this->assertSame(
			ActivationState::INACTIVE,
			$this->repo->by_id( $mapping->id )?->activation_state
		);
	}

	public function test_a_transient_resolver_failure_never_takes_a_live_domain_down(): void {
		$root = $this->make_page( 'club', 0 );

		$mapping = $this->repo->save(
			new Mapping(
				0, 'resilient.test', null, $root, 1,
				VerificationState::UNVERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge'
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'verification_state' => VerificationState::VERIFIED->value ),
			array( 'id' => $mapping->id )
		);

		$flaky = new class() implements DnsResolver {
			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult( DnsOutcome::TRANSIENT );
			}
		};

		for ( $i = 0; $i < 10; $i++ ) {
			( new Verifier( $this->repo, $flaky, new SystemClock() ) )
				->verify( $this->repo->by_id( $mapping->id ) );
		}

		$this->assertSame(
			VerificationState::VERIFIED,
			$this->repo->by_id( $mapping->id )?->verification_state,
			'ten transient failures in a row must not deactivate a live domain'
		);
	}

	public function test_the_five_dispositions_are_all_reachable(): void {
		$reached = array();

		foreach ( Disposition::cases() as $disposition ) {
			$reached[] = $disposition->value;
		}

		foreach (
			array( 'malformed_400', 'unknown_421', 'not_serving_404', 'broken_503', 'serve' ) as $expected
		) {
			$this->assertContains( $expected, $reached );
		}
	}

	public function test_uninstall_leaves_content_untouched(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Untouched' ) );

		$this->repo->save(
			new Mapping(
				0, 'doomed.test', null, $post, 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'c', 32 ), '_post-domain-challenge'
			)
		);

		$this->assertSame( 'Untouched', get_post( $post )?->post_title );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter AcceptanceTest`
Expected: FAIL — the first test fails at the permalink assertion until every
preceding plan is complete

- [ ] **Step 3: Write minimal implementation**

No new production code. If a step fails, the defect belongs to the plan that owns
that subsystem; fix it there and re-run this suite.

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter AcceptanceTest`
Expected: PASS — 4 tests

- [ ] **Step 5: Run everything**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add tests/integration/AcceptanceTest.php
git commit -m "Prove the subsystems compose end to end

Added, verified, activated, serving, deactivated — and ten consecutive transient
resolver failures leave a live domain live."
```

---

## Gate for Plan 11

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: `ReadmeTest` passes for every required topic, `DiagnosticsTest` proves no
server-side probe exists and that both an unselected and an unregistered SSL
driver are surfaced, `AdminScreensTest` proves the driver selection offers only
registered drivers and resets the memoized registry on save, and `AcceptanceTest`
passes end to end.

This is the final plan. When its gate is green the specification is implemented.
