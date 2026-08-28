<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;

final class StatusMapGeneratorTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/** @return array{schema: array<string, string[]>, provenance: array<string, string>} */
	private function pinned(): array {
		/** @var array<string, string> $provenance */
		$provenance = json_decode(
			(string) file_get_contents( $this->root() . '/references/cloudflare-schema-provenance.json' ),
			true
		);

		/** @var array<string, string[]> $schema */
		$schema = json_decode(
			(string) file_get_contents( $this->root() . '/references/' . $provenance['file'] ),
			true
		);

		return array(
			'schema'     => $schema,
			'provenance' => $provenance,
		);
	}

	public function test_the_provenance_records_source_date_digest_and_extraction(): void {
		$provenance = $this->pinned()['provenance'];

		$this->assertSame(
			'https://raw.githubusercontent.com/cloudflare/api-schemas/main/openapi.json',
			$provenance['source_url']
		);
		$this->assertArrayHasKey( 'retrieved_at', $provenance );
		$this->assertArrayHasKey( 'api_version', $provenance );
		$this->assertStringContainsString( 'hostname_status', $provenance['extraction'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $provenance['sha256'] );
	}

	public function test_the_digest_matches_the_committed_snapshot(): void {
		$provenance = $this->pinned()['provenance'];

		$this->assertSame(
			$provenance['sha256'],
			hash_file( 'sha256', $this->root() . '/references/' . $provenance['file'] )
		);
	}

	public function test_the_schema_carries_the_expected_cardinality(): void {
		$schema = $this->pinned()['schema'];

		$this->assertCount( 16, $schema['hostname_status'] );
		$this->assertCount( 21, $schema['ssl_status'] );
	}

	public function test_the_snapshot_holds_the_exact_values_the_policy_was_written_against(): void {
		$schema = $this->pinned()['schema'];

		$this->assertSame(
			array(
				'active',
				'pending',
				'active_redeploying',
				'moved',
				'pending_deletion',
				'deleted',
				'pending_blocked',
				'pending_migration',
				'pending_provisioned',
				'test_pending',
				'test_active',
				'test_active_apex',
				'test_blocked',
				'test_failed',
				'provisioned',
				'blocked',
			),
			$schema['hostname_status']
		);

		$this->assertSame(
			array(
				'initializing',
				'pending_validation',
				'deleted',
				'pending_issuance',
				'pending_deployment',
				'pending_deletion',
				'pending_expiration',
				'expired',
				'active',
				'initializing_timed_out',
				'validation_timed_out',
				'issuance_timed_out',
				'deployment_timed_out',
				'deletion_timed_out',
				'pending_cleanup',
				'staging_deployment',
				'staging_active',
				'deactivating',
				'inactive',
				'backup_issued',
				'holding_deployment',
			),
			$schema['ssl_status']
		);
	}

	public function test_every_schema_value_has_a_classification(): void {
		$schema = $this->pinned()['schema'];

		/** @var array{hostname: array<string, string>, ssl: array<string, string>} $policy */
		$policy = require $this->root() . '/references/cloudflare-status-policy.php';

		foreach ( $schema['hostname_status'] as $value ) {
			$this->assertArrayHasKey( $value, $policy['hostname'], "unclassified hostname status {$value}" );
		}

		foreach ( $schema['ssl_status'] as $value ) {
			$this->assertArrayHasKey( $value, $policy['ssl'], "unclassified ssl status {$value}" );
		}
	}

	public function test_the_policy_classifies_nothing_the_schema_does_not_publish(): void {
		$schema = $this->pinned()['schema'];

		/** @var array{hostname: array<string, string>, ssl: array<string, string>} $policy */
		$policy = require $this->root() . '/references/cloudflare-status-policy.php';

		$this->assertSame( array(), array_diff( array_keys( $policy['hostname'] ), $schema['hostname_status'] ) );
		$this->assertSame( array(), array_diff( array_keys( $policy['ssl'] ), $schema['ssl_status'] ) );
	}

	public function test_every_classification_is_one_of_the_four_local_states(): void {
		/** @var array{hostname: array<string, string>, ssl: array<string, string>} $policy */
		$policy = require $this->root() . '/references/cloudflare-status-policy.php';

		foreach ( array_merge( $policy['hostname'], $policy['ssl'] ) as $value => $class ) {
			$this->assertContains(
				$class,
				array( 'active', 'pending_validation', 'failed', 'revoked' ),
				"{$value} maps to an unknown local state"
			);
		}
	}

	public function test_the_committed_map_equals_a_fresh_generation(): void {
		$generated = shell_exec( 'php ' . escapeshellarg( $this->root() . '/bin/generate-cloudflare-status-map.php' ) . ' --stdout' );

		$this->assertSame(
			trim( (string) file_get_contents( $this->root() . '/references/cloudflare-status-map.php' ) ),
			trim( (string) $generated ),
			'the committed map is a derived artifact and must match its inputs'
		);
	}

	public function test_generation_fails_when_a_value_is_unclassified(): void {
		$output = shell_exec(
			'php ' . escapeshellarg( $this->root() . '/bin/generate-cloudflare-status-map.php' )
			. ' --schema=' . escapeshellarg( $this->root() . '/tests/unit/fixtures/cloudflare-schema-extra-value.json' )
			. ' --stdout 2>&1; echo "EXIT:$?"'
		);

		$this->assertStringContainsString( 'unclassified', (string) $output );
		$this->assertStringContainsString( 'EXIT:1', (string) $output );
	}

	public function test_generation_fails_on_a_digest_mismatch(): void {
		$root  = $this->root();
		$path  = $root . '/references/cloudflare-api-schema.2026-08-27.json';
		$saved = (string) file_get_contents( $path );

		// The snapshot already ends in exactly one newline, so rtrim()+"\n" would
		// reproduce it byte for byte and the digest would still match. Append.
		file_put_contents( $path, $saved . "\n" );

		$output = shell_exec(
			'php ' . escapeshellarg( $root . '/bin/generate-cloudflare-status-map.php' ) . ' --stdout 2>&1; echo "EXIT:$?"'
		);

		file_put_contents( $path, $saved );

		$this->assertStringContainsString( 'digest mismatch', (string) $output );
		$this->assertStringContainsString( 'EXIT:1', (string) $output );
	}

	public function test_the_generator_makes_no_network_call(): void {
		$source = (string) file_get_contents( $this->root() . '/bin/generate-cloudflare-status-map.php' );

		foreach ( array( 'curl_', 'file_get_contents( \'http', 'fopen( \'http', 'wp_remote' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $source );
		}
	}
}
