<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Actions;
use PostDomain\Admin\Notices;
use PostDomain\Admin\RedirectedAway;
use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\RemovalOutcome;
use PostDomain\Ssl\RemovalScope;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;

/**
 * What the operator is told when a removal has started but not finished.
 *
 * Both removals are durable workflows that legitimately answer "accepted" with
 * the work still outstanding: §14.15 deletion leaves the row in place while
 * provider cleanup is scheduled, and an SSL-resource removal answers the same
 * way for a pending or transient provider outcome. Announcing either as
 * finished is worse than saying nothing — the operator stops watching, and the
 * row is still there.
 */
final class AdminRemovalReportingTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_ssl_drivers' );
		remove_all_filters( 'pd_dns_resolver' );
		remove_all_filters( 'pd_admin_redirect_should_exit' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/** Installs a driver whose removals stay outstanding, the way a real one does. */
	private function driver_returning( RemovalOutcome $outcome ): RecordingDriver {
		$driver = RecordingDriver::removing( $outcome );

		add_filter(
			'pd_ssl_drivers',
			static function ( array $drivers ) use ( $driver ): array {
				$drivers[] = $driver;

				return $drivers;
			}
		);
		update_option( 'pd_settings', array( 'ssl_driver' => $driver->id() ), false );
		DriverFactory::reset();

		add_filter(
			'pd_dns_resolver',
			static fn(): DnsResolver => new class() implements DnsResolver {
				public function txt( string $name, string $expected ): DnsResult {
					return new DnsResult( DnsOutcome::MATCH );
				}
			}
		);

		return $driver;
	}

	private function bound(): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"removal-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::ACTIVE,
				null,
				str_pad( (string) $this->seq, 32, 'b', STR_PAD_LEFT ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	/** @return array{url: ?string, notice: ?array{type: string, message: string}} */
	private function post( string $action, Mapping $mapping ): array {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'pd_action'   => $action,
			'pd_mapping'  => $mapping->id,
			'pd_revision' => $mapping->revision,
			'_wpnonce'    => wp_create_nonce( Actions::nonce_action( $action, $mapping->id ) ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- assembling the request the handler verifies.

		add_filter( 'pd_admin_redirect_should_exit', '__return_false' );

		$url = null;

		try {
			Actions::handle();
		} catch ( RedirectedAway $e ) {
			$url = $e->url;
		} finally {
			remove_filter( 'pd_admin_redirect_should_exit', '__return_false' );
			$_POST                     = array();
			$_REQUEST                  = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		return array(
			'url'    => $url,
			'notice' => Notices::take(),
		);
	}

	// -- durable mapping deletion -------------------------------------------

	public function test_a_scheduled_deletion_is_not_announced_as_finished(): void {
		$this->driver_returning( RemovalOutcome::PENDING );
		$mapping = $this->bound();

		$outcome = $this->post( 'pd_delete_mapping', $mapping );
		$after   = $this->repo->by_id( $mapping->id );

		// The row survives on purpose: §14.15 never deletes before cleanup.
		$this->assertNotNull( $after, 'a provider-backed deletion is scheduled, not immediate' );
		$this->assertSame( SslState::PENDING_REMOVAL, $after->ssl_state );
		$this->assertSame( RemovalScope::MAPPING->value, $this->scope( $mapping->id ) );

		$this->assertSame( 'success', $outcome['notice']['type'] ?? null );
		$this->assertStringContainsString( 'started', (string) ( $outcome['notice']['message'] ?? '' ) );
		$this->assertStringNotContainsString(
			'The domain was removed',
			(string) ( $outcome['notice']['message'] ?? '' ),
			'the domain is still here; saying otherwise sends the operator away'
		);
	}

	public function test_a_scheduled_deletion_returns_to_the_mapping_not_the_list(): void {
		$this->driver_returning( RemovalOutcome::PENDING );
		$mapping = $this->bound();

		$outcome = $this->post( 'pd_delete_mapping', $mapping );

		$this->assertStringContainsString(
			'mapping=' . $mapping->id,
			(string) $outcome['url'],
			'a row that still exists must not send the operator to a list that still shows it'
		);
	}

	public function test_a_local_deletion_is_announced_as_finished_and_returns_to_the_list(): void {
		// No provider resource: §14.15 step 4 deletes immediately.
		$mapping = $this->repo->save(
			new Mapping(
				0,
				'local-delete.test',
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( 'c', 32 ),
				'_post-domain-challenge'
			)
		);

		$outcome = $this->post( 'pd_delete_mapping', $mapping );

		$this->assertNull( $this->repo->by_id( $mapping->id ) );
		$this->assertStringContainsString( 'The domain was removed', (string) ( $outcome['notice']['message'] ?? '' ) );
		$this->assertStringNotContainsString( 'mapping=', (string) $outcome['url'] );
	}

	// -- SSL-resource removal ------------------------------------------------

	/** @dataProvider outstanding_outcomes */
	public function test_an_outstanding_certificate_removal_is_not_announced_as_finished(
		RemovalOutcome $outcome
	): void {
		$this->driver_returning( $outcome );
		$mapping = $this->bound();

		$result = $this->post( 'pd_remove_ssl', $mapping );
		$after  = $this->repo->by_id( $mapping->id );

		$this->assertNotNull( $after );
		$this->assertSame( 'ref-1', $after->ssl_ref, 'the binding survives until the provider confirms' );
		$this->assertSame( RemovalScope::RESOURCE->value, $this->scope( $mapping->id ) );

		$this->assertStringNotContainsString(
			'The certificate was removed',
			(string) ( $result['notice']['message'] ?? '' ),
			'the provider has not confirmed; the certificate is still there'
		);
		$this->assertStringContainsString( 'started', (string) ( $result['notice']['message'] ?? '' ) );
	}

	/** @return array<string, array{0: RemovalOutcome}> */
	public static function outstanding_outcomes(): array {
		return array(
			'pending'   => array( RemovalOutcome::PENDING ),
			'transient' => array( RemovalOutcome::TRANSIENT ),
			'failed'    => array( RemovalOutcome::FAILED ),
		);
	}

	public function test_a_confirmed_certificate_removal_is_announced_as_finished(): void {
		$this->driver_returning( RemovalOutcome::REMOVED );
		$mapping = $this->bound();

		$result = $this->post( 'pd_remove_ssl', $mapping );
		$after  = $this->repo->by_id( $mapping->id );

		$this->assertNotNull( $after, 'removing a certificate never deletes the mapping' );
		$this->assertNull( $after->ssl_ref );
		$this->assertStringContainsString(
			'The certificate was removed',
			(string) ( $result['notice']['message'] ?? '' )
		);
		$this->assertStringContainsString( 'mapping=' . $mapping->id, (string) $result['url'] );
	}

	public function test_a_refused_removal_is_an_error_notice(): void {
		// No driver configured at all, so authorization refuses before any call.
		$mapping = $this->bound();

		$result = $this->post( 'pd_remove_ssl', $mapping );

		$this->assertSame( 'error', $result['notice']['type'] ?? null );
		$this->assertNotNull( $this->repo->by_id( $mapping->id ) );
	}

	private function scope( int $id ): ?string {
		global $wpdb;

		$table = Schema::domains_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::domains_table().
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT ssl_removal_scope FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB

		return null === $value ? null : (string) $value;
	}
}
