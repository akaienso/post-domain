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
use PostDomain\Ssl\Environment;
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
				'blocked_recoveries',
				'drifted_resources',
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

	public function test_a_recovery_blocked_on_configuration_names_what_to_restore(): void {
		global $wpdb;

		$mapping = ( new DbRepository() )->save(
			new Mapping(
				0,
				'orphaned.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::REQUESTED,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => str_repeat( '5', 32 ),
				'ssl_mutation_kind'        => MutationKind::CREATE->value,
				'ssl_mutation_phase'       => 'recovering',
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'ssl_mutation_driver'      => 'cloudflare-saas',
				'ssl_mutation_environment' => 'cf-zone:the-old-zone',
			),
			array( 'id' => $mapping->id )
		);

		\PostDomain\Ssl\DriverFactory::reset();

		$check = Diagnostics::checks()['blocked_recoveries'];

		$this->assertSame( 'error', $check['status'] );
		$this->assertStringContainsString( 'orphaned.test', $check['detail'] );
		$this->assertStringContainsString( 'cf-zone:the-old-zone', $check['detail'] );
	}

	public function test_a_certificate_in_an_unconfigured_environment_is_surfaced(): void {
		global $wpdb;

		$mapping = ( new DbRepository() )->save(
			new Mapping(
				0,
				'moved.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'd', 32 ),
				'_post-domain-challenge'
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			// A complete binding: the repository invariant forbids a partial one,
			// so a fixture that wrote half of it would be testing an impossible row.
			array(
				'ssl_provider'              => 'cloudflare-saas',
				'ssl_provider_environment'  => 'cf-zone:the-old-zone',
				'ssl_ref'                   => 'ref-1',
				'ssl_ownership_origin'      => 'created',
				'ssl_owner_installation_id' => Environment::installation_id(),
			),
			array( 'id' => $mapping->id )
		);

		\PostDomain\Ssl\DriverFactory::reset();

		$check = Diagnostics::checks()['drifted_resources'];

		$this->assertSame( 'warning', $check['status'] );
		$this->assertStringContainsString( 'moved.test', $check['detail'] );
		$this->assertStringContainsString( 'cf-zone:the-old-zone', $check['detail'] );
		$this->assertStringContainsString( 'cloudflare-saas', $check['detail'], 'the driver is named as a driver' );
	}

	public function test_no_credential_appears_in_any_diagnostic(): void {
		update_option( 'pd_ssl_credentials', array( 'api_token' => 'cf-token-value' ), false );
		\PostDomain\Ssl\DriverFactory::reset();

		$this->assertStringNotContainsString( 'cf-token-value', (string) wp_json_encode( Diagnostics::checks() ) );

		delete_option( 'pd_ssl_credentials' );
		\PostDomain\Ssl\DriverFactory::reset();
	}

	public function test_a_long_running_recovery_is_surfaced(): void {
		global $wpdb;

		$mapping = ( new DbRepository() )->save(
			new Mapping(
				0,
				'stuck.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::REQUESTED,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
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
				0,
				'stale.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge'
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
				0,
				'due.test',
				null,
				self::factory()->post->create(),
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'b', 32 ),
				'_post-domain-challenge'
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
