# post-domain 09 — Cloudflare for SaaS driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A working Cloudflare for SaaS driver whose status map is generated from
a pinned schema snapshot, whose validation plan distinguishes four record
purposes, and which operates fully without any paid entitlement.

**Architecture:** The status map is a derived artifact: a pinned schema says which
enum values exist, a human-authored policy says what each means, and the generator
joins them. `custom_metadata` is optional defence in depth; identity rests on the
reference-plus-hostname binding and the plugin's own DNS proof.

**Tech Stack:** As Plans 01–08, plus the Cloudflare v4 API over the injected
`HttpClient`.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–08, and add:

- **Cloudflare authoritative DNS is recommended, never required.** The engine is
  authoritative-DNS-provider-neutral, and no DNS is mutated by any API
  (spec §14.14).
- **`custom_metadata` is optional.** Absence establishes nothing; the driver works
  without it (spec §14.11).
- **API error 1413 is a definitive, non-mutating rejection,** not a transient
  failure. It permits exactly one retry without `custom_metadata`, inside the same
  execution (spec §14.11).
- **Local `ACTIVE` requires `status: active` AND `ssl.status: active`**
  (spec §14.11).
- **Unknown provider values are non-destructive and alerting** — never `FAILED`,
  never `REVOKED` (spec §14.18).
- **`caa_error` is not a status-axis value.** It comes from the error arrays
  (spec §14.18).
- **A/AAAA routing records only under attested Apex Proxying or BYOIP**
  (spec §14.13).
- **No wildcard is ever requested** (spec §14.16).

---

## File map

| File | Responsibility |
|---|---|
| `references/cloudflare-api-schema.2026-08-27.json` | Pinned upstream snapshot |
| `references/cloudflare-schema-provenance.json` | Source URL, retrieval date, SHA-256 |
| `references/cloudflare-status-policy.php` | Human-authored classification |
| `references/cloudflare-status-map.php` | Generated from schema × policy |
| `bin/generate-cloudflare-status-map.php` | The generator, offline |
| `src/Ssl/CloudflareStatusMap.php` | Loads the generated map, combines the two axes |
| `src/Ssl/ApexRouting.php`, `src/Ssl/ApexCapability.php` | Typed apex capability |
| `src/Ssl/DnsRecordSpec.php`, `DnsRequirementSet.php`, `HttpRequirementSet.php`, `ManualRequirement.php`, `ValidationPending.php`, `DnsBlocker.php` | Plan value objects |
| `src/Ssl/CloudflareValidationPlan.php` | Translation from provider payloads |
| `src/Ssl/Credentials.php` | Constants and option, never persisted per mapping |
| `src/Ssl/CloudflareSaasDriver.php` | The driver itself |
| `src/Ssl/DriverFactory.php` (modified) | Where the driver becomes reachable in production |

---

### Task 1: Pinned schema, policy, and the generator

**Files:**
- Create: `bin/generate-cloudflare-status-map.php`, `bin/extract-cloudflare-status-schema.sh`, `references/cloudflare-status-policy.php`, `references/cloudflare-schema-provenance.json`, `references/cloudflare-api-schema.2026-08-27.json`
- Modify: `composer.json` (add the `generate:status-map` script), `.github/workflows/ci.yml`
- Test: `tests/unit/Ssl/StatusMapGeneratorTest.php`

**Interfaces:**
- Consumes: nothing at runtime.
- Produces: `references/cloudflare-status-map.php` returning
  `array{hostname: array<string,string>, ssl: array<string,string>}`, and the
  `composer generate:status-map` command.

#### The authoritative source

The prose API reference does not publish either enum in full. The machine-readable
OpenAPI document does:

- URL: `https://raw.githubusercontent.com/cloudflare/api-schemas/main/openapi.json`
- Format: OpenAPI 3.0.3, `info.version` `4.0.0`
- Size: roughly 24 MB, so download it to a temporary path rather than piping it
  through anything that buffers in memory.

Both enums live on the **list** operation's query parameters, keyed by parameter
`name`. Select by name, never by array index — the parameter order is not part of
the contract.

#### Extracting the snapshot

`bin/extract-cloudflare-status-schema.sh` performs the one-time retrieval. It is a
developer tool, run by hand and committed for reproducibility; the plugin never
executes it and never reaches the network:

```bash
#!/usr/bin/env bash
# Retrieves the Cloudflare OpenAPI document and extracts the two custom-hostname
# status enums into a pinned snapshot. Developer tool: never run by the plugin.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
date_stamp="${1:?usage: extract-cloudflare-status-schema.sh YYYY-MM-DD}"
url='https://raw.githubusercontent.com/cloudflare/api-schemas/main/openapi.json'
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

curl --fail --silent --show-error --location "${url}" --output "${work}/openapi.json"

jq --sort-keys '
	.paths["/zones/{zone_id}/custom_hostnames"].get.parameters as $p
	| {
		hostname_status: ( $p[] | select( .name == "hostname_status" ) | .schema.enum ),
		ssl_status:      ( $p[] | select( .name == "ssl_status" )      | .schema.enum )
	}
' "${work}/openapi.json" > "${root}/references/cloudflare-api-schema.${date_stamp}.json"

snapshot="cloudflare-api-schema.${date_stamp}.json"
digest="$( shasum -a 256 "${root}/references/${snapshot}" | cut -d' ' -f1 )"
api_version="$( jq -r '.info.version' "${work}/openapi.json" )"

jq -n \
	--arg file "${snapshot}" \
	--arg source_url "${url}" \
	--arg retrieved_at "$( date -u +%Y-%m-%dT%H:%M:%SZ )" \
	--arg sha256 "${digest}" \
	--arg api_version "${api_version}" \
	--arg extraction 'jq .paths["/zones/{zone_id}/custom_hostnames"].get.parameters[] | select(.name=="hostname_status" or .name=="ssl_status") | .schema.enum' \
	'{ file: $file, source_url: $source_url, retrieved_at: $retrieved_at, sha256: $sha256, api_version: $api_version, extraction: $extraction }' \
	> "${root}/references/cloudflare-schema-provenance.json"

echo "hostname_status: $( jq '.hostname_status | length' "${root}/references/${snapshot}" )"
echo "ssl_status:      $( jq '.ssl_status | length' "${root}/references/${snapshot}" )"
```

Verified on 2026-08-27: `hostname_status` carries **16** values and `ssl_status`
carries **21**, matching the specification's stated cardinality. Both are listed
in full in the policy below, so nothing here is left to be discovered later.

The negative fixture is derived from the snapshot the same way — one invented
value appended so generation has something to reject:

```bash
jq '.hostname_status += ["pd_unclassified_probe"]' \
	references/cloudflare-api-schema.2026-08-27.json \
	> tests/unit/fixtures/cloudflare-schema-extra-value.json
```

Everything after this point is offline: the snapshot, the policy, and the
generator are all committed, and `composer generate:status-map` reads only files
in the repository.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/StatusMapGeneratorTest.php`:

```php
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

		return array( 'schema' => $schema, 'provenance' => $provenance );
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

		file_put_contents( $path, rtrim( $saved ) . "\n" );

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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter StatusMapGeneratorTest`
Expected: FAIL — `file_get_contents(…/references/cloudflare-schema-provenance.json): Failed to open stream`

- [ ] **Step 3: Write minimal implementation**

Create `bin/extract-cloudflare-status-schema.sh` with the script given above,
`chmod +x` it, and run it once:

```bash
chmod +x bin/extract-cloudflare-status-schema.sh
bin/extract-cloudflare-status-schema.sh 2026-08-27
```

That writes `references/cloudflare-api-schema.2026-08-27.json` and
`references/cloudflare-schema-provenance.json`. Confirm it printed
`hostname_status: 16` and `ssl_status: 21`; if either count differs, Cloudflare
has changed the contract and the policy below must be revisited before going
further rather than patched to fit.

Then create the negative fixture:

```bash
jq '.hostname_status += ["pd_unclassified_probe"]' \
	references/cloudflare-api-schema.2026-08-27.json \
	> tests/unit/fixtures/cloudflare-schema-extra-value.json
```

Create `references/cloudflare-status-policy.php`. This is the complete
classification — every one of the 16 hostname values and 21 SSL values, with no
value left to be filled in:

```php
<?php
/**
 * Human-authored classification. The pinned schema says which values exist; this
 * says what each one means locally. Generation fails on any value missing here.
 *
 * Local states: active, pending_validation, failed, revoked.
 *
 * - active             the certificate is serving traffic now
 * - pending_validation the provider is still working; wait, do not act
 * - failed             a terminal problem an operator must resolve
 * - revoked            the resource is being or has been withdrawn
 *
 * Cloudflare's `test_*` hostname statuses describe a staging hostname that is
 * not serving production traffic, so a healthy test state is pending rather
 * than active; a failed one is a real failure.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

return array(
	'hostname' => array(
		'active'              => 'active',
		'pending'             => 'pending_validation',
		'active_redeploying'  => 'active',
		'moved'               => 'failed',
		'pending_deletion'    => 'revoked',
		'deleted'             => 'revoked',
		'pending_blocked'     => 'failed',
		'pending_migration'   => 'pending_validation',
		'pending_provisioned' => 'pending_validation',
		'test_pending'        => 'pending_validation',
		'test_active'         => 'pending_validation',
		'test_active_apex'    => 'pending_validation',
		'test_blocked'        => 'failed',
		'test_failed'         => 'failed',
		'provisioned'         => 'pending_validation',
		'blocked'             => 'failed',
	),
	'ssl'      => array(
		'initializing'           => 'pending_validation',
		'pending_validation'     => 'pending_validation',
		'deleted'                => 'revoked',
		'pending_issuance'       => 'pending_validation',
		'pending_deployment'     => 'pending_validation',
		'pending_deletion'       => 'revoked',
		'pending_expiration'     => 'active',
		'expired'                => 'failed',
		'active'                 => 'active',
		'initializing_timed_out' => 'failed',
		'validation_timed_out'   => 'failed',
		'issuance_timed_out'     => 'failed',
		'deployment_timed_out'   => 'failed',
		'deletion_timed_out'     => 'failed',
		'pending_cleanup'        => 'revoked',
		'staging_deployment'     => 'pending_validation',
		'staging_active'         => 'pending_validation',
		'deactivating'           => 'revoked',
		'inactive'               => 'revoked',
		'backup_issued'          => 'active',
		'holding_deployment'     => 'pending_validation',
	),
);
```

Two of those deserve a note, because they are the ones a reviewer will question.
`pending_expiration` still serves traffic, so it is `active` and the operator is
warned elsewhere rather than being told the certificate is broken. `backup_issued`
likewise describes a certificate that exists and serves.

Create `bin/generate-cloudflare-status-map.php`:

```php
<?php
/**
 * Generates references/cloudflare-status-map.php from the pinned schema snapshot
 * and the human-authored classification policy. Offline by construction.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

$pd_root = dirname( __DIR__ );
$pd_args = array();

foreach ( array_slice( $argv, 1 ) as $pd_arg ) {
	if ( str_starts_with( $pd_arg, '--schema=' ) ) {
		$pd_args['schema'] = substr( $pd_arg, strlen( '--schema=' ) );
	}

	if ( '--stdout' === $pd_arg ) {
		$pd_args['stdout'] = true;
	}
}

/** @var array<string, string> $pd_provenance */
$pd_provenance = json_decode(
	(string) file_get_contents( $pd_root . '/references/cloudflare-schema-provenance.json' ),
	true
);

$pd_schema_path = $pd_args['schema'] ?? $pd_root . '/references/' . $pd_provenance['file'];

if ( ! isset( $pd_args['schema'] ) ) {
	$pd_digest = hash_file( 'sha256', $pd_schema_path );

	if ( $pd_digest !== $pd_provenance['sha256'] ) {
		fwrite( STDERR, "schema digest mismatch: expected {$pd_provenance['sha256']}, got {$pd_digest}\n" );
		exit( 1 );
	}
}

/** @var array<string, string[]> $pd_schema */
$pd_schema = json_decode( (string) file_get_contents( $pd_schema_path ), true );

/** @var array{hostname: array<string, string>, ssl: array<string, string>} $pd_policy */
$pd_policy = require $pd_root . '/references/cloudflare-status-policy.php';

$pd_expected = array( 'hostname_status' => 16, 'ssl_status' => 21 );
$pd_map      = array( 'hostname' => array(), 'ssl' => array() );

foreach ( array( 'hostname_status' => 'hostname', 'ssl_status' => 'ssl' ) as $pd_axis => $pd_key ) {
	$pd_values = $pd_schema[ $pd_axis ] ?? null;

	if ( ! is_array( $pd_values ) ) {
		fwrite( STDERR, "schema axis {$pd_axis} is missing or malformed\n" );
		exit( 1 );
	}

	if ( count( $pd_values ) !== count( array_unique( $pd_values ) ) ) {
		fwrite( STDERR, "schema axis {$pd_axis} contains duplicates\n" );
		exit( 1 );
	}

	if ( ! isset( $pd_args['schema'] ) && count( $pd_values ) !== $pd_expected[ $pd_axis ] ) {
		fwrite(
			STDERR,
			"schema axis {$pd_axis} has " . count( $pd_values )
			. " values, expected {$pd_expected[ $pd_axis ]}; update the expectation deliberately\n"
		);
		exit( 1 );
	}

	foreach ( $pd_values as $pd_value ) {
		if ( ! isset( $pd_policy[ $pd_key ][ $pd_value ] ) ) {
			fwrite( STDERR, "unclassified {$pd_axis} value: {$pd_value}\n" );
			exit( 1 );
		}

		$pd_map[ $pd_key ][ $pd_value ] = $pd_policy[ $pd_key ][ $pd_value ];
	}
}

$pd_output = "<?php\n"
	. "/**\n * GENERATED from the pinned schema snapshot and the classification policy.\n"
	. " * Do not edit: run `composer generate:status-map`.\n *\n * @package PostDomain\n */\n\n"
	. "declare( strict_types = 1 );\n\n"
	. 'return ' . var_export( $pd_map, true ) . ";\n";

if ( isset( $pd_args['stdout'] ) ) {
	echo $pd_output; // phpcs:ignore WordPress.Security.EscapeOutput

	exit( 0 );
}

file_put_contents( $pd_root . '/references/cloudflare-status-map.php', $pd_output );
```

Add to `composer.json` scripts:

```json
		"generate:status-map": "php bin/generate-cloudflare-status-map.php"
```

Add to `.github/workflows/ci.yml`, before `composer test`:

```yaml
      - run: composer generate:status-map
      - run: git diff --exit-code references/cloudflare-status-map.php
```

Run `composer generate:status-map` once to produce the committed map.

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer generate:status-map && vendor/bin/phpunit --testsuite unit --filter StatusMapGeneratorTest`
Expected: PASS — 10 tests

- [ ] **Step 5: Add the optional drift check**

This job is scheduled, not part of the required build, and it never edits
anything. It tells a maintainer that Cloudflare published a value the pinned
snapshot does not have; a human then decides what the new value means.

Create `.github/workflows/cloudflare-schema-drift.yml`:

```yaml
name: Cloudflare schema drift

on:
  schedule:
    - cron: '17 6 * * 1'
  workflow_dispatch:

jobs:
  drift:
    runs-on: ubuntu-latest
    continue-on-error: true
    steps:
      - uses: actions/checkout@v4
      - name: Compare the live enums with the pinned snapshot
        run: |
          curl --fail --silent --show-error --location \
            https://raw.githubusercontent.com/cloudflare/api-schemas/main/openapi.json \
            --output /tmp/openapi.json
          jq --sort-keys '
            .paths["/zones/{zone_id}/custom_hostnames"].get.parameters as $p
            | {
                hostname_status: ( $p[] | select( .name == "hostname_status" ) | .schema.enum ),
                ssl_status:      ( $p[] | select( .name == "ssl_status" )      | .schema.enum )
              }
          ' /tmp/openapi.json > /tmp/live.json
          if ! diff -u references/cloudflare-api-schema.2026-08-27.json /tmp/live.json; then
            echo '::warning::Cloudflare status enums have drifted; classify the new values before repinning.'
            exit 1
          fi
```

- [ ] **Step 6: Commit**

```bash
git add bin/generate-cloudflare-status-map.php bin/extract-cloudflare-status-schema.sh references/ composer.json .github/workflows/ci.yml .github/workflows/cloudflare-schema-drift.yml tests/unit/Ssl/StatusMapGeneratorTest.php tests/unit/fixtures/cloudflare-schema-extra-value.json
git commit -m "Generate the Cloudflare status map from a pinned, digested snapshot

The enums come from the published OpenAPI document, selected by parameter name
rather than position. The schema says which values exist and the policy says
what they mean; the map is their join. CI fails on an unclassified value, a
digest mismatch, or a cardinality change, and generation never touches the
network."
```

---

### Task 2: Combining the two status axes

**Files:**
- Create: `src/Ssl/CloudflareStatusMap.php`
- Test: `tests/unit/Ssl/CloudflareStatusMapTest.php`

**Interfaces:**
- Consumes: the generated map (Task 1).
- Produces: `PostDomain\Ssl\CloudflareStatusMap::combine( ?string $hostname_status, ?string $ssl_status ): array{state: SslState, unknown: bool}` and `::classify_errors( array $verification_errors, array $validation_errors ): ?string`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/CloudflareStatusMapTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\CloudflareStatusMap;

final class CloudflareStatusMapTest extends TestCase {

	public function test_active_and_active_is_the_only_route_to_local_active(): void {
		$this->assertSame( SslState::ACTIVE, CloudflareStatusMap::combine( 'active', 'active' )['state'] );
	}

	public function test_active_hostname_with_pending_ssl_is_pending(): void {
		$this->assertSame(
			SslState::PENDING_VALIDATION,
			CloudflareStatusMap::combine( 'active', 'pending_validation' )['state']
		);
	}

	public function test_pending_hostname_with_active_ssl_is_pending(): void {
		$this->assertSame(
			SslState::PENDING_VALIDATION,
			CloudflareStatusMap::combine( 'pending', 'active' )['state'],
			'production ready needs both axes active'
		);
	}

	public function test_a_failed_axis_fails_the_combination(): void {
		$this->assertSame( SslState::FAILED, CloudflareStatusMap::combine( 'moved', 'active' )['state'] );
		$this->assertSame( SslState::FAILED, CloudflareStatusMap::combine( 'active', 'expired' )['state'] );
	}

	/**
	 * @dataProvider unknown_values
	 */
	public function test_an_unknown_value_is_pending_and_flagged( ?string $hostname, ?string $ssl ): void {
		$result = CloudflareStatusMap::combine( $hostname, $ssl );

		$this->assertSame( SslState::PENDING_VALIDATION, $result['state'] );
		$this->assertTrue( $result['unknown'] );
	}

	/** @return array<string, array{0: string|null, 1: string|null}> */
	public static function unknown_values(): array {
		return array(
			'future hostname value' => array( 'some_future_state', 'active' ),
			'future ssl value'      => array( 'active', 'some_future_state' ),
			'null hostname'         => array( null, 'active' ),
			'null ssl'              => array( 'active', null ),
		);
	}

	public function test_an_unknown_value_can_never_produce_failed_or_revoked(): void {
		foreach ( array( 'unknown_a', 'unknown_b' ) as $value ) {
			$state = CloudflareStatusMap::combine( $value, $value )['state'];

			$this->assertNotSame( SslState::FAILED, $state );
			$this->assertNotSame( SslState::REVOKED, $state );
		}
	}

	public function test_caa_errors_are_classified_from_the_error_arrays(): void {
		$code = CloudflareStatusMap::classify_errors(
			array(),
			array( array( 'message' => 'SERVFAIL looking up CAA for app.example.com' ) )
		);

		$this->assertSame( 'caa_error', $code );
	}

	public function test_caa_is_not_a_status_axis_value(): void {
		/** @var array{hostname: array<string, string>, ssl: array<string, string>} $map */
		$map = require dirname( __DIR__, 3 ) . '/references/cloudflare-status-map.php';

		$this->assertArrayNotHasKey( 'caa_error', $map['hostname'] );
		$this->assertArrayNotHasKey( 'caa_error', $map['ssl'] );
	}

	public function test_empty_error_arrays_classify_to_nothing(): void {
		$this->assertNull( CloudflareStatusMap::classify_errors( array(), array() ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter CloudflareStatusMapTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\CloudflareStatusMap" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/CloudflareStatusMap.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\SslState;

final class CloudflareStatusMap {

	/** @var array{hostname: array<string, string>, ssl: array<string, string>}|null */
	private static ?array $map = null;

	/** @return array{state: SslState, unknown: bool} */
	public static function combine( ?string $hostname_status, ?string $ssl_status ): array {
		$map = self::map();

		$hostname = null === $hostname_status ? null : ( $map['hostname'][ $hostname_status ] ?? null );
		$ssl      = null === $ssl_status ? null : ( $map['ssl'][ $ssl_status ] ?? null );

		if ( null === $hostname || null === $ssl ) {
			// Non-destructive and alerting: a schema addition can never tear down
			// a working certificate.
			return array( 'state' => SslState::PENDING_VALIDATION, 'unknown' => true );
		}

		if ( 'revoked' === $hostname || 'revoked' === $ssl ) {
			return array( 'state' => SslState::REVOKED, 'unknown' => false );
		}

		if ( 'failed' === $hostname || 'failed' === $ssl ) {
			return array( 'state' => SslState::FAILED, 'unknown' => false );
		}

		if ( 'active' === $hostname && 'active' === $ssl ) {
			return array( 'state' => SslState::ACTIVE, 'unknown' => false );
		}

		return array( 'state' => SslState::PENDING_VALIDATION, 'unknown' => false );
	}

	/**
	 * caa_error is not a status-axis value: it comes from the error arrays.
	 *
	 * @param array<int, array<string, string>> $verification_errors
	 * @param array<int, array<string, string>> $validation_errors
	 */
	public static function classify_errors( array $verification_errors, array $validation_errors ): ?string {
		foreach ( array_merge( $verification_errors, $validation_errors ) as $error ) {
			$message = (string) ( $error['message'] ?? ( is_string( $error ) ? $error : '' ) );

			if ( false !== stripos( $message, 'caa' ) ) {
				return 'caa_error';
			}
		}

		return null;
	}

	/** @return array{hostname: array<string, string>, ssl: array<string, string>} */
	private static function map(): array {
		if ( null === self::$map ) {
			/** @var array{hostname: array<string, string>, ssl: array<string, string>} $map */
			$map       = require dirname( __DIR__, 2 ) . '/references/cloudflare-status-map.php';
			self::$map = $map;
		}

		return self::$map;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter CloudflareStatusMapTest`
Expected: PASS — 13 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/CloudflareStatusMap.php tests/unit/Ssl/CloudflareStatusMapTest.php
git commit -m "Combine the two Cloudflare status axes, non-destructively

Local active needs both axes active. An unknown value on either axis becomes
pending and raises an alert, and can never produce failed or revoked."
```

---

### Task 3: Typed apex capability

**Files:**
- Create: `src/Ssl/ApexRouting.php`, `src/Ssl/ApexCapability.php`, `src/Support/PublicSuffix.php`
- Test: `tests/unit/Ssl/ApexCapabilityTest.php`

**Interfaces:**
- Consumes: `jeremykendall/php-domain-parser`.
- Produces: `PostDomain\Ssl\ApexRouting` enum, `ApexCapability` (readonly `ApexRouting $routing`, `string $reason`, `string[] $targets`, `?string $target_provenance`, `bool $operator_attested`) with `::validated( mixed $candidate ): self`, and `PostDomain\Support\PublicSuffix::is_apex( string $host ): bool`.

Entitlement is never inferred from the presence of address strings, which is why
`operator_attested` exists (spec §14.13).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/ApexCapabilityTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Support\PublicSuffix;

final class ApexCapabilityTest extends TestCase {

	public function test_apex_detection_uses_the_public_suffix_list(): void {
		$this->assertTrue( PublicSuffix::is_apex( 'example.com' ) );
		$this->assertFalse( PublicSuffix::is_apex( 'shop.example.com' ) );
	}

	public function test_a_multi_label_suffix_is_handled_correctly(): void {
		$this->assertTrue(
			PublicSuffix::is_apex( 'example.co.uk' ),
			'a label count would get this wrong'
		);
		$this->assertFalse( PublicSuffix::is_apex( 'shop.example.co.uk' ) );
	}

	public function test_cname_flattening_needs_no_targets(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::CNAME_FLATTENING, 'zone is on Cloudflare', array(), null, false )
		);

		$this->assertSame( ApexRouting::CNAME_FLATTENING, $capability->routing );
	}

	public function test_apex_proxy_requires_targets(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array(), 'byoip', true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_apex_proxy_requires_valid_ip_targets(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( 'not-an-ip' ), 'byoip', true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_apex_proxy_requires_a_declared_provenance(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), null, true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_apex_proxy_requires_an_operator_attestation(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'static_ip_prefix', false )
		);

		$this->assertSame(
			ApexRouting::UNSUPPORTED,
			$capability->routing,
			'entitlement is never inferred from address strings alone'
		);
	}

	public function test_a_fully_attested_apex_proxy_capability_is_accepted(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'static_ip_prefix', true )
		);

		$this->assertSame( ApexRouting::APEX_PROXY, $capability->routing );
		$this->assertSame( array( '203.0.113.5' ), $capability->targets );
	}

	public function test_an_unknown_provenance_is_rejected(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'my-origin-server', true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_a_non_capability_value_becomes_unsupported(): void {
		$this->assertSame( ApexRouting::UNSUPPORTED, ApexCapability::validated( 'yes please' )->routing );
		$this->assertSame( ApexRouting::UNSUPPORTED, ApexCapability::validated( true )->routing );
		$this->assertSame( ApexRouting::UNSUPPORTED, ApexCapability::validated( null )->routing );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter ApexCapabilityTest`
Expected: FAIL — `Error: Class "PostDomain\Support\PublicSuffix" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/PublicSuffix.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use Pdp\Domain;
use Pdp\Rules;

/**
 * Apex is decided against the registrable domain, never a label count: a label
 * count is wrong for example.co.uk and every other multi-label suffix.
 */
final class PublicSuffix {

	private static ?Rules $rules = null;

	public static function is_apex( string $host ): bool {
		try {
			$resolved = self::rules()->resolve( Domain::fromIDNA2008( $host ) );

			return $resolved->registrableDomain()->toString() === $host;
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}

	private static function rules(): Rules {
		if ( null === self::$rules ) {
			self::$rules = Rules::fromPath( dirname( __DIR__, 2 ) . '/references/public_suffix_list.dat' );
		}

		return self::$rules;
	}
}
```

Download the Public Suffix List once to
`references/public_suffix_list.dat` from `https://publicsuffix.org/list/public_suffix_list.dat`
and commit it, so no build or request depends on network access.

Create `src/Ssl/ApexRouting.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum ApexRouting: string {
	case CNAME_FLATTENING = 'cname_flattening';
	case ALIAS_OR_ANAME   = 'alias_or_aname';
	case APEX_PROXY       = 'apex_proxy';
	case UNSUPPORTED      = 'unsupported';
}
```

Create `src/Ssl/ApexCapability.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ApexCapability {

	public const PROVENANCES = array( 'static_ip_prefix', 'byoip' );

	/** @param string[] $targets */
	public function __construct(
		public readonly ApexRouting $routing,
		public readonly string $reason,
		public readonly array $targets,
		public readonly ?string $target_provenance,
		public readonly bool $operator_attested
	) {}

	public static function unsupported( string $reason ): self {
		return new self( ApexRouting::UNSUPPORTED, $reason, array(), null, false );
	}

	/**
	 * A/AAAA records are emitted only for a fully attested Apex Proxying or BYOIP
	 * capability. Ordinary origin addresses are never valid apex proxy targets.
	 *
	 * @param mixed $candidate
	 */
	public static function validated( $candidate ): self {
		if ( ! $candidate instanceof self ) {
			return self::unsupported( 'filter did not return an ApexCapability' );
		}

		if ( ApexRouting::APEX_PROXY !== $candidate->routing ) {
			return $candidate;
		}

		if ( array() === $candidate->targets ) {
			return self::unsupported( 'apex proxying declared with no targets' );
		}

		foreach ( $candidate->targets as $target ) {
			if ( ! is_string( $target ) || false === filter_var( $target, FILTER_VALIDATE_IP ) ) {
				return self::unsupported( 'apex proxy target is not an IP address' );
			}
		}

		if ( ! in_array( $candidate->target_provenance, self::PROVENANCES, true ) ) {
			return self::unsupported( 'apex proxy targets need a declared Cloudflare provenance' );
		}

		if ( ! $candidate->operator_attested ) {
			return self::unsupported( 'apex proxying requires an explicit operator attestation' );
		}

		return $candidate;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer require jeremykendall/php-domain-parser && vendor/bin/phpunit --testsuite unit --filter ApexCapabilityTest`
Expected: PASS — 12 tests

- [ ] **Step 5: Commit**

```bash
git add src/Support/PublicSuffix.php src/Ssl/ApexRouting.php src/Ssl/ApexCapability.php references/public_suffix_list.dat composer.json composer.lock tests/unit/Ssl/ApexCapabilityTest.php
git commit -m "Type the apex routing capability and require an attestation

Apex Proxying and BYOIP addresses are entitlement-gated and distinct from
ordinary origin addresses, so the plugin refuses to infer the entitlement from
the presence of address strings."
```

---

### Task 4: The validation plan and its translations

**Files:**
- Create: `src/Ssl/DnsRecordSpec.php`, `src/Ssl/DnsRequirementSet.php`, `src/Ssl/HttpRequirementSet.php`, `src/Ssl/ManualRequirement.php`, `src/Ssl/ValidationPending.php`, `src/Ssl/DnsBlocker.php`, `src/Ssl/CloudflareValidationPlan.php`
- Modify: `src/Ssl/ValidationPlan.php` (type the collections)
- Test: `tests/unit/Ssl/CloudflareValidationPlanTest.php`

**Interfaces:**
- Consumes: `ApexCapability` (Task 3).
- Produces: `CloudflareValidationPlan::build( array $hostname_payload, string $cname_target, ApexCapability $apex, bool $is_apex, string $core_record_name, string $core_record_value ): ValidationPlan`.

Four purposes, never substituted: `ownership` (core, permanent),
`provider_ownership`, `ssl_validation`, `routing` (spec §14.13).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Ssl/CloudflareValidationPlanTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Ssl\CloudflareValidationPlan;

final class CloudflareValidationPlanTest extends TestCase {

	private function build( array $payload, bool $is_apex = false, ?ApexCapability $apex = null ) {
		return CloudflareValidationPlan::build(
			$payload,
			'saas.example.net',
			$apex ?? new ApexCapability( ApexRouting::CNAME_FLATTENING, 'zone on Cloudflare', array(), null, false ),
			$is_apex,
			'_post-domain-challenge.mapped.test',
			'post-domain-verify=abc'
		);
	}

	public function test_core_contributes_exactly_one_permanent_ownership_record(): void {
		$plan = $this->build( array( 'status' => 'pending' ) );

		$this->assertCount( 1, $plan->dns['ownership'] );
		$this->assertSame( 'core', $plan->dns['ownership'][0]->source );
		$this->assertFalse( $plan->dns['ownership'][0]->removable_once_active );
	}

	public function test_a_provider_ownership_txt_becomes_a_removable_requirement(): void {
		$plan = $this->build(
			array(
				'status'                => 'pending',
				'ownership_verification' => array(
					'type'  => 'txt',
					'name'  => '_cf-custom-hostname.mapped.test',
					'value' => '0e2d5a7f',
				),
			)
		);

		$set = $plan->dns['provider_ownership'][0];

		$this->assertSame( 'cf-hostname-txt', $set->id );
		$this->assertTrue( $set->removable_once_active );
		$this->assertSame( '_cf-custom-hostname.mapped.test', $set->records[0]->name );
	}

	public function test_a_provider_ownership_http_token_is_not_a_dns_record(): void {
		$plan = $this->build(
			array(
				'status'                      => 'pending',
				'ownership_verification_http' => array(
					'http_url'  => 'http://mapped.test/.well-known/cf-custom-hostname-challenge/abc',
					'http_body' => 'token-value',
				),
			)
		);

		$this->assertArrayNotHasKey( 'provider_ownership', $plan->dns );
		$this->assertCount( 1, $plan->http );
		$this->assertSame( 'provider_ownership', $plan->http[0]->purpose );
	}

	public function test_both_ownership_forms_present_render_as_alternatives(): void {
		$plan = $this->build(
			array(
				'status'                      => 'pending',
				'ownership_verification'      => array( 'type' => 'txt', 'name' => '_cf.mapped.test', 'value' => 'v' ),
				'ownership_verification_http' => array( 'http_url' => 'http://x/y', 'http_body' => 'b' ),
			)
		);

		$this->assertCount( 1, $plan->dns['provider_ownership'] );
		$this->assertCount( 1, $plan->http );
		$this->assertTrue( $plan->alternatives_for( 'provider_ownership' ) );
	}

	public function test_neither_ownership_form_while_pending_is_a_wait_not_a_blocker(): void {
		$plan = $this->build( array( 'status' => 'pending' ) );

		$this->assertSame( array(), $plan->blockers );
		$this->assertNotEmpty(
			array_filter( $plan->pending, static fn( $p ): bool => 'provider_ownership' === $p->purpose )
		);
	}

	public function test_an_active_hostname_suppresses_completed_ownership_instructions(): void {
		$plan = $this->build(
			array(
				'status'                 => 'active',
				'ownership_verification' => array( 'type' => 'txt', 'name' => '_cf.mapped.test', 'value' => 'v' ),
			)
		);

		$this->assertArrayNotHasKey( 'provider_ownership', $plan->dns );
	}

	public function test_malformed_ownership_data_becomes_a_blocker(): void {
		$plan = $this->build(
			array(
				'status'                 => 'pending',
				'ownership_verification' => array( 'type' => 'txt', 'name' => '' ),
			)
		);

		$this->assertNotEmpty( $plan->blockers );
		$this->assertSame( 'provider_record_malformed', $plan->blockers[0]->code );
	}

	public function test_a_dcv_txt_record_becomes_an_ssl_validation_requirement(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(
						array( 'txt_name' => '_acme-challenge.mapped.test', 'txt_value' => 'abc' ),
					),
				),
			)
		);

		$this->assertSame( 'cf-dcv-txt', $plan->dns['ssl_validation'][0]->id );
	}

	public function test_a_dcv_http_token_is_an_http_requirement(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(
						array( 'http_url' => 'http://mapped.test/.well-known/pki-validation/x.txt', 'http_body' => 'y' ),
					),
				),
			)
		);

		$this->assertCount( 1, $plan->http );
		$this->assertSame( 'ssl_validation', $plan->http[0]->purpose );
	}

	public function test_email_dcv_becomes_a_manual_requirement(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(
						array( 'emails' => array( 'admin@mapped.test', 'webmaster@mapped.test' ) ),
					),
				),
			)
		);

		$this->assertCount( 1, $plan->manual );
		$this->assertContains( 'admin@mapped.test', $plan->manual[0]->contacts );
	}

	public function test_empty_validation_records_shortly_after_create_is_pending(): void {
		$plan = $this->build(
			array( 'status' => 'active', 'ssl' => array( 'status' => 'pending_validation', 'validation_records' => array() ) )
		);

		$this->assertNotEmpty(
			array_filter( $plan->pending, static fn( $p ): bool => 'ssl_validation' === $p->purpose )
		);
		$this->assertSame( array(), $plan->blockers );
	}

	public function test_an_unrecognised_validation_record_becomes_a_blocker(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array( array( 'mystery_field' => 'x' ) ),
				),
			)
		);

		$this->assertSame( 'provider_record_malformed', $plan->blockers[0]->code );
	}

	public function test_a_non_apex_host_gets_a_cname_routing_set(): void {
		$plan = $this->build( array( 'status' => 'pending' ) );

		$this->assertSame( 'CNAME', $plan->dns['routing'][0]->records[0]->type );
		$this->assertSame( 'saas.example.net', $plan->dns['routing'][0]->records[0]->value );
		$this->assertFalse( $plan->dns['routing'][0]->apex_compatible );
	}

	public function test_an_apex_host_with_flattening_gets_a_cname_set(): void {
		$plan = $this->build( array( 'status' => 'pending' ), true );

		$this->assertSame( 'CNAME', $plan->dns['routing'][0]->records[0]->type );
		$this->assertTrue( $plan->dns['routing'][0]->apex_compatible );
	}

	public function test_an_apex_host_with_attested_proxying_gets_a_records(): void {
		$apex = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'byoip', true )
		);

		$plan = $this->build( array( 'status' => 'pending' ), true, $apex );

		$this->assertSame( 'A', $plan->dns['routing'][0]->records[0]->type );
		$this->assertSame( '203.0.113.5', $plan->dns['routing'][0]->records[0]->value );
	}

	public function test_an_apex_host_without_capability_gets_a_blocker_and_no_routing(): void {
		$apex = ApexCapability::unsupported( 'no apex-capable target configured' );
		$plan = $this->build( array( 'status' => 'pending' ), true, $apex );

		$this->assertArrayNotHasKey( 'routing', $plan->dns );
		$this->assertNotEmpty( $plan->blockers );
	}

	public function test_no_record_type_is_ever_the_literal_unsupported(): void {
		$apex = ApexCapability::unsupported( 'none' );
		$plan = $this->build( array( 'status' => 'pending' ), true, $apex );

		foreach ( $plan->dns as $sets ) {
			foreach ( $sets as $set ) {
				foreach ( $set->records as $record ) {
					$this->assertNotSame( 'unsupported', $record->type );
				}
			}
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter CloudflareValidationPlanTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\CloudflareValidationPlan" not found`

- [ ] **Step 3: Write minimal implementation**

Create the five value objects:

```php
<?php
// src/Ssl/DnsRecordSpec.php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DnsRecordSpec {

	public function __construct(
		public readonly string $type,
		public readonly string $name,
		public readonly string $value,
		public readonly int $ttl = 300
	) {}
}
```

```php
<?php
// src/Ssl/DnsRequirementSet.php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DnsRequirementSet {

	/** @param DnsRecordSpec[] $records */
	public function __construct(
		public readonly string $purpose,
		public readonly string $id,
		public readonly string $label,
		public readonly array $records,
		public readonly bool $apex_compatible,
		public readonly string $source,
		public readonly bool $removable_once_active = false
	) {}
}
```

```php
<?php
// src/Ssl/HttpRequirementSet.php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class HttpRequirementSet {

	public function __construct(
		public readonly string $purpose,
		public readonly string $id,
		public readonly string $label,
		public readonly string $url,
		public readonly string $body,
		public readonly string $source,
		public readonly bool $removable_once_active = false
	) {}
}
```

```php
<?php
// src/Ssl/ManualRequirement.php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ManualRequirement {

	/** @param string[] $contacts */
	public function __construct(
		public readonly string $purpose,
		public readonly string $id,
		public readonly string $label,
		public readonly string $instruction,
		public readonly array $contacts,
		public readonly string $source
	) {}
}
```

```php
<?php
// src/Ssl/ValidationPending.php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPending {

	public function __construct(
		public readonly string $purpose,
		public readonly string $reason,
		public readonly ?int $retry_after = null
	) {}
}
```

```php
<?php
// src/Ssl/DnsBlocker.php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class DnsBlocker {

	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly string $remedy,
		public readonly string $source
	) {}
}
```

Replace `src/Ssl/ValidationPlan.php` with the typed version:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPlan {

	/**
	 * @param array<string, DnsRequirementSet[]> $dns
	 * @param HttpRequirementSet[]               $http
	 * @param ManualRequirement[]                $manual
	 * @param ValidationPending[]                $pending
	 * @param DnsBlocker[]                       $blockers
	 */
	public function __construct(
		public readonly array $dns,
		public readonly array $http,
		public readonly array $manual,
		public readonly array $pending,
		public readonly array $blockers
	) {}

	/** True when a purpose offers more than one genuinely sufficient route. */
	public function alternatives_for( string $purpose ): bool {
		$dns  = count( $this->dns[ $purpose ] ?? array() );
		$http = count( array_filter( $this->http, static fn( HttpRequirementSet $h ): bool => $h->purpose === $purpose ) );

		return ( $dns + $http ) > 1;
	}
}
```

Create `src/Ssl/CloudflareValidationPlan.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class CloudflareValidationPlan {

	/**
	 * @param array<string, mixed> $payload The custom hostname payload.
	 */
	public static function build(
		array $payload,
		string $cname_target,
		ApexCapability $apex,
		bool $is_apex,
		string $core_record_name,
		string $core_record_value
	): ValidationPlan {
		$dns      = array();
		$http     = array();
		$manual   = array();
		$pending  = array();
		$blockers = array();

		// Purpose 1: the plugin's own permanent challenge.
		$dns['ownership'] = array(
			new DnsRequirementSet(
				'ownership',
				'core-ownership',
				'Ownership TXT (permanent)',
				array( new DnsRecordSpec( 'TXT', $core_record_name, $core_record_value ) ),
				true,
				'core',
				false
			),
		);

		// Purpose 2: Cloudflare hostname ownership.
		$hostname_status = (string) ( $payload['status'] ?? '' );

		if ( 'active' !== $hostname_status ) {
			$ownership = $payload['ownership_verification'] ?? null;
			$http_own  = $payload['ownership_verification_http'] ?? null;
			$found     = false;

			if ( is_array( $ownership ) ) {
				if ( '' === (string) ( $ownership['name'] ?? '' ) || '' === (string) ( $ownership['value'] ?? '' ) ) {
					$blockers[] = new DnsBlocker(
						'provider_record_malformed',
						'Cloudflare returned an incomplete ownership record.',
						'Re-read the custom hostname; if it persists, recreate it.',
						'cloudflare-saas'
					);
				} else {
					$dns['provider_ownership'] = array(
						new DnsRequirementSet(
							'provider_ownership',
							'cf-hostname-txt',
							'Cloudflare hostname ownership TXT',
							array(
								new DnsRecordSpec(
									strtoupper( (string) ( $ownership['type'] ?? 'TXT' ) ),
									(string) $ownership['name'],
									(string) $ownership['value']
								),
							),
							true,
							'cloudflare-saas',
							true
						),
					);
					$found = true;
				}
			}

			if ( is_array( $http_own )
				&& '' !== (string) ( $http_own['http_url'] ?? '' )
				&& '' !== (string) ( $http_own['http_body'] ?? '' ) ) {
				$http[] = new HttpRequirementSet(
					'provider_ownership',
					'cf-hostname-http',
					'Cloudflare hostname ownership HTTP token',
					(string) $http_own['http_url'],
					(string) $http_own['http_body'],
					'cloudflare-saas',
					true
				);
				$found  = true;
			}

			if ( ! $found && array() === $blockers ) {
				$pending[] = new ValidationPending( 'provider_ownership', 'provider_records_not_yet_issued' );
			}
		}

		// Purpose 3: certificate validation.
		$ssl        = is_array( $payload['ssl'] ?? null ) ? $payload['ssl'] : array();
		$ssl_status = (string) ( $ssl['status'] ?? '' );
		$records    = is_array( $ssl['validation_records'] ?? null ) ? $ssl['validation_records'] : array();

		if ( 'active' !== $ssl_status ) {
			if ( array() === $records ) {
				$pending[] = new ValidationPending( 'ssl_validation', 'provider_records_not_yet_issued' );
			}

			foreach ( $records as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				if ( '' !== (string) ( $record['txt_name'] ?? '' ) && '' !== (string) ( $record['txt_value'] ?? '' ) ) {
					$dns['ssl_validation'][] = new DnsRequirementSet(
						'ssl_validation',
						'cf-dcv-txt',
						'Certificate validation TXT',
						array(
							new DnsRecordSpec( 'TXT', (string) $record['txt_name'], (string) $record['txt_value'] ),
						),
						true,
						'cloudflare-saas'
					);

					continue;
				}

				if ( '' !== (string) ( $record['http_url'] ?? '' ) && '' !== (string) ( $record['http_body'] ?? '' ) ) {
					$http[] = new HttpRequirementSet(
						'ssl_validation',
						'cf-dcv-http',
						'Certificate validation HTTP token',
						(string) $record['http_url'],
						(string) $record['http_body'],
						'cloudflare-saas'
					);

					continue;
				}

				if ( is_array( $record['emails'] ?? null ) && array() !== $record['emails'] ) {
					$manual[] = new ManualRequirement(
						'ssl_validation',
						'cf-dcv-email',
						'Certificate validation email',
						'A person must open the approval email and follow its link. This cannot be automated.',
						array_map( 'strval', $record['emails'] ),
						'cloudflare-saas'
					);

					continue;
				}

				$blockers[] = new DnsBlocker(
					'provider_record_malformed',
					'Cloudflare returned a validation record in an unrecognised shape.',
					'Re-read the custom hostname; if it persists, change the validation method.',
					'cloudflare-saas'
				);
			}
		}

		// Purpose 4: routing. No CNAME is assumed, and no record is ever invented.
		if ( ! $is_apex ) {
			$dns['routing'] = array(
				new DnsRequirementSet(
					'routing',
					'routing-cname',
					'Point the hostname at the SaaS target',
					array( new DnsRecordSpec( 'CNAME', 'mapped host', $cname_target ) ),
					false,
					'cloudflare-saas'
				),
			);
		} elseif ( ApexRouting::APEX_PROXY === $apex->routing ) {
			$dns['routing'] = array(
				new DnsRequirementSet(
					'routing',
					'routing-apex-proxy',
					'Point the apex at the assigned addresses',
					array_map(
						static fn( string $ip ): DnsRecordSpec => new DnsRecordSpec(
							false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 'A' : 'AAAA',
							'mapped host',
							$ip
						),
						$apex->targets
					),
					true,
					'cloudflare-saas'
				),
			);
		} elseif ( in_array( $apex->routing, array( ApexRouting::CNAME_FLATTENING, ApexRouting::ALIAS_OR_ANAME ), true ) ) {
			$dns['routing'] = array(
				new DnsRequirementSet(
					'routing',
					'routing-apex-cname',
					'Point the apex at the SaaS target (flattened)',
					array( new DnsRecordSpec( 'CNAME', 'mapped host', $cname_target ) ),
					true,
					'cloudflare-saas'
				),
			);
		} else {
			$blockers[] = new DnsBlocker(
				'apex_routing_unsupported',
				'This apex domain has no supported routing mechanism: ' . $apex->reason,
				'Move the zone to a provider with CNAME flattening, ALIAS, or ANAME, or configure attested Apex Proxying or BYOIP targets.',
				'cloudflare-saas'
			);
		}

		return new ValidationPlan( $dns, $http, $manual, $pending, $blockers );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter CloudflareValidationPlanTest`
Expected: PASS — 17 tests

- [ ] **Step 5: Commit**

```bash
git add src/Ssl/DnsRecordSpec.php src/Ssl/DnsRequirementSet.php src/Ssl/HttpRequirementSet.php src/Ssl/ManualRequirement.php src/Ssl/ValidationPending.php src/Ssl/DnsBlocker.php src/Ssl/ValidationPlan.php src/Ssl/CloudflareValidationPlan.php tests/unit/Ssl/CloudflareValidationPlanTest.php
git commit -m "Translate provider payloads into four distinct record purposes

HTTP tokens are never rendered as DNS records, an unissued record is pending
rather than a blocker, and no record type is ever the literal 'unsupported'."
```

---

### Task 5: The driver, credentials, and error 1413

**Files:**
- Create: `src/Ssl/Credentials.php`, `src/Ssl/CloudflareSaasDriver.php`
- Test: `tests/integration/Ssl/CloudflareSaasDriverTest.php`

**Interfaces:**
- Consumes: `HttpClient` (Plan 02), Tasks 2–4.
- Produces: `PostDomain\Ssl\Credentials::api_token()`, `::zone_id()`, `::cname_target()`, `::ssl_method()`, `::apex_targets()`, `::apex_provenance()`; and `CloudflareSaasDriver` implementing `SslDriver`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/CloudflareSaasDriverTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Contracts\HttpClient;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\CloudflareSaasDriver;
use PostDomain\Ssl\ExecutionPermit;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\HttpResponse;
use WP_UnitTestCase;

final class CloudflareSaasDriverTest extends WP_UnitTestCase {

	/** @var array<int, array{method: string, url: string, body: string}> */
	private array $sent = array();

	private function client( array $responses ): HttpClient {
		$sent = &$this->sent;

		return new class( $responses, $sent ) implements HttpClient {
			/** @param array<int, HttpResponse> $responses */
			public function __construct( private array $responses, private array &$sent ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				$this->sent[] = array(
					'method' => $method,
					'url'    => $url,
					'body'   => (string) ( $opts['body'] ?? '' ),
				);

				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};
	}

	private function ok( array $result ): HttpResponse {
		return new HttpResponse(
			200,
			array( 'content-type' => 'application/json' ),
			(string) wp_json_encode( array( 'success' => true, 'result' => $result, 'errors' => array() ) )
		);
	}

	private function error( int $code, int $status = 400 ): HttpResponse {
		return new HttpResponse(
			$status,
			array( 'content-type' => 'application/json' ),
			(string) wp_json_encode(
				array( 'success' => false, 'result' => null, 'errors' => array( array( 'code' => $code, 'message' => 'e' ) ) )
			)
		);
	}

	private function context( ?string $ref = null ): SslResourceContext {
		return new SslResourceContext(
			12, 'mapped.test', 'install-a', 'cloudflare-saas', null === $ref ? null : 'cf-zone:zone-1', $ref, null, null,
			'_post-domain-challenge.mapped.test', 'post-domain-verify=abc', 3, str_repeat( '1', 32 ), 'txt'
		);
	}

	private function permit( MutationKind $kind ): ExecutionPermit {
		return new ExecutionPermit(
			$kind, 12, 4, str_repeat( '1', 32 ),
			new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'UTC' ) )
		);
	}

	private function driver( array $responses ): CloudflareSaasDriver {
		return new CloudflareSaasDriver( $this->client( $responses ), 'token', 'zone-1', 'saas.example.net' );
	}

	public function test_create_posts_the_hostname_and_the_configured_method(): void {
		$driver = $this->driver( array( $this->ok( array( 'id' => 'ref-1', 'hostname' => 'mapped.test', 'status' => 'pending' ) ) ) );

		$status = $driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertSame( 'ref-1', $status->ref );
		$this->assertStringContainsString( '"method":"txt"', $this->sent[0]['body'] );
		$this->assertStringContainsString( '"type":"dv"', $this->sent[0]['body'] );
	}

	public function test_create_never_requests_a_wildcard(): void {
		$driver = $this->driver( array( $this->ok( array( 'id' => 'ref-1', 'hostname' => 'mapped.test', 'status' => 'pending' ) ) ) );

		$driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertStringNotContainsString( '"wildcard":true', $this->sent[0]['body'] );
	}

	public function test_error_1413_retries_once_without_custom_metadata(): void {
		$driver = $this->driver(
			array(
				$this->error( 1413 ),
				$this->ok( array( 'id' => 'ref-1', 'hostname' => 'mapped.test', 'status' => 'pending' ) ),
			)
		);

		$status = $driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertCount( 2, $this->sent );
		$this->assertStringContainsString( 'custom_metadata', $this->sent[0]['body'] );
		$this->assertStringNotContainsString( 'custom_metadata', $this->sent[1]['body'] );
		$this->assertSame( 'ref-1', $status->ref );
		$this->assertSame( MarkerSupport::UNAVAILABLE, $driver->marker_support() );
	}

	public function test_error_1413_is_not_transient(): void {
		$driver = $this->driver( array( $this->error( 1413 ), $this->error( 1413 ) ) );

		$status = $driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertFalse( $status->transient, '1413 reports a definitive rejection' );
		$this->assertCount( 2, $this->sent, 'exactly one retry, never a loop' );
	}

	public function test_a_timeout_grants_no_retry(): void {
		$driver = $this->driver( array( new HttpResponse( 0, array(), '', 'timeout' ) ) );

		$status = $driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertTrue( $status->transient );
		$this->assertCount( 1, $this->sent, 'an ambiguous failure is resolved by reading, not repeating' );
	}

	public function test_a_5xx_grants_no_retry(): void {
		$driver = $this->driver( array( new HttpResponse( 503, array( 'content-type' => 'application/json' ), '{}' ) ) );

		$driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertCount( 1, $this->sent );
	}

	public function test_a_duplicate_record_error_routes_to_identify(): void {
		$driver = $this->driver(
			array(
				$this->error( 1406 ),
				$this->ok( array( array( 'id' => 'ref-9', 'hostname' => 'mapped.test', 'status' => 'pending' ) ) ),
			)
		);

		$status = $driver->create( $this->context(), $this->permit( MutationKind::CREATE ) );

		$this->assertSame( 'GET', $this->sent[1]['method'] );
		$this->assertNull( $status->ref, 'a duplicate is never bound without identification' );
	}

	public function test_identify_reports_the_exact_hostname_and_reference(): void {
		$driver = $this->driver(
			array( $this->ok( array( 'id' => 'ref-1', 'hostname' => 'mapped.test', 'status' => 'active' ) ) )
		);

		$identity = $driver->identify( $this->context( 'ref-1' ) );

		$this->assertSame( 'ref-1', $identity->observed_ref );
		$this->assertSame( 'mapped.test', $identity->observed_hostname );
		$this->assertTrue( $identity->read_complete );
	}

	public function test_status_combines_both_axes(): void {
		$driver = $this->driver(
			array(
				$this->ok(
					array(
						'id'       => 'ref-1',
						'hostname' => 'mapped.test',
						'status'   => 'active',
						'ssl'      => array( 'status' => 'active', 'method' => 'txt' ),
					)
				),
			)
		);

		$status = $driver->status( $this->context( 'ref-1' ) );

		$this->assertSame( SslState::ACTIVE, $status->state );
		$this->assertSame( 'txt', $status->confirmed_method );
	}

	public function test_a_404_on_delete_counts_as_removed(): void {
		$driver = $this->driver( array( new HttpResponse( 404, array( 'content-type' => 'application/json' ), '{}' ) ) );

		$result = $driver->remove( $this->context( 'ref-1' ), $this->permit( MutationKind::REMOVE ) );

		$this->assertSame( \PostDomain\Ssl\RemovalOutcome::REMOVED, $result->outcome );
	}

	public function test_a_429_sets_a_cooldown_from_retry_after(): void {
		$driver = $this->driver(
			array( new HttpResponse( 429, array( 'retry-after' => '90', 'content-type' => 'application/json' ), '{}' ) )
		);

		$result = $driver->remove( $this->context( 'ref-1' ), $this->permit( MutationKind::REMOVE ) );

		$this->assertSame( \PostDomain\Ssl\RemovalOutcome::TRANSIENT, $result->outcome );
		$this->assertSame( 90, $result->retry_after );
		$this->assertTrue( \PostDomain\Ssl\Cooldown::active_for( 'cloudflare-saas' ) );
	}

	public function test_credentials_never_appear_in_a_status(): void {
		$driver = $this->driver( array( $this->error( 1000, 403 ) ) );

		$status = $driver->status( $this->context( 'ref-1' ) );

		$this->assertStringNotContainsString( 'token', (string) $status->message );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CloudflareSaasDriverTest`
Expected: FAIL — `Error: Class "PostDomain\Ssl\CloudflareSaasDriver" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Ssl/Credentials.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Constants first, then a dedicated option. Never a mapping row, never a REST
 * response, never an event or log line.
 */
final class Credentials {

	public static function api_token(): string {
		return self::value( 'PD_CLOUDFLARE_API_TOKEN', 'api_token' );
	}

	public static function zone_id(): string {
		return self::value( 'PD_CLOUDFLARE_ZONE_ID', 'zone_id' );
	}

	public static function cname_target(): string {
		return self::value( 'PD_CLOUDFLARE_CNAME_TARGET', 'cname_target' );
	}

	public static function ssl_method(): string {
		$method = self::value( 'PD_CLOUDFLARE_SSL_METHOD', 'ssl_method' );

		return in_array( $method, MethodChangeAuthorizer::METHODS, true ) ? $method : 'txt';
	}

	/** @return string[] */
	public static function apex_targets(): array {
		$raw = self::value( 'PD_CLOUDFLARE_APEX_PROXY_TARGETS', 'apex_proxy_targets' );

		return '' === $raw ? array() : array_map( 'trim', explode( ',', $raw ) );
	}

	public static function apex_provenance(): ?string {
		$value = self::value( 'PD_CLOUDFLARE_APEX_PROXY_PROVENANCE', 'apex_proxy_provenance' );

		return in_array( $value, ApexCapability::PROVENANCES, true ) ? $value : null;
	}

	private static function value( string $constant, string $key ): string {
		if ( defined( $constant ) ) {
			$value = constant( $constant );

			return is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}

		$option = get_option( 'pd_ssl_credentials', array() );

		return is_array( $option ) && isset( $option[ $key ] ) ? (string) $option[ $key ] : '';
	}
}
```

Create `src/Ssl/CloudflareSaasDriver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\HttpClient;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\SslState;
use PostDomain\Support\HttpResponse;
use PostDomain\Support\PublicSuffix;

final class CloudflareSaasDriver implements SslDriver {

	private const API = 'https://api.cloudflare.com/client/v4';

	private const ERROR_UNAVAILABLE_METADATA = 1413;

	private const ERROR_DUPLICATE = 1406;

	private MarkerSupport $marker_support = MarkerSupport::UNKNOWN;

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $token,
		private readonly string $zone_id,
		private readonly string $cname_target
	) {}

	public function id(): string {
		return 'cloudflare-saas';
	}

	/**
	 * Custom hostnames live in a zone, and a zone id is globally unique and not a
	 * credential — it appears in every API path. It is therefore the right thing
	 * to bind a mutation to: a token rotated to a different account reaches a
	 * different zone id, and the same zone id always means the same resources.
	 * The API token is never part of this value.
	 */
	public function environment_id(): string {
		return 'cf-zone:' . $this->zone_id;
	}

	public function marker_support(): MarkerSupport {
		return $this->marker_support;
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities(
			MarkerSupport::UNAVAILABLE !== $this->marker_support,
			array( 'http', 'txt', 'email' ),
			array() !== Credentials::apex_targets()
		);
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		$response = $this->get_hostname( $ctx );

		if ( null === $response['payload'] ) {
			return $this->status_from_failure( $response['response'] );
		}

		return $this->status_from_payload( $response['payload'] );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		$response = $this->get_hostname( $ctx );
		$payload  = $response['payload'];

		if ( null === $payload ) {
			return new IdentityResult(
				IdentityVerdict::UNKNOWN,
				$ctx->provider_ref,
				null,
				null,
				null,
				$this->marker_support,
				false,
				$this->is_transient( $response['response'] )
			);
		}

		$observed_ref = (string) ( $payload['id'] ?? '' );
		$hostname     = (string) ( $payload['hostname'] ?? '' );
		$marker       = $this->parse_marker( $payload );

		if ( null === $ctx->provider_ref ) {
			return new IdentityResult(
				IdentityVerdict::RECOVERABLE_CREATE,
				null,
				$observed_ref,
				$hostname,
				$marker,
				$this->marker_support,
				true,
				false
			);
		}

		$verdict = ( $observed_ref === $ctx->provider_ref && $hostname === $ctx->host )
			? IdentityVerdict::MATCH
			: IdentityVerdict::MISMATCH;

		return new IdentityResult(
			$verdict,
			$ctx->provider_ref,
			$observed_ref,
			$hostname,
			$marker,
			$this->marker_support,
			true,
			false
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( MutationKind::CREATE );

		$body = array(
			'hostname' => $ctx->host,
			'ssl'      => array(
				'method' => $ctx->requested_method ?? Credentials::ssl_method(),
				'type'   => 'dv',
			),
		);

		if ( MarkerSupport::UNAVAILABLE !== $this->marker_support ) {
			$body['custom_metadata'] = array(
				'pd_install' => $ctx->installation_id,
				'pd_mapping' => (string) $ctx->mapping_id,
			);
		}

		$response = $this->request( 'POST', '/zones/' . $this->zone_id . '/custom_hostnames', $body );

		if ( $this->has_error( $response, self::ERROR_UNAVAILABLE_METADATA ) ) {
			// Definitive rejection: nothing was created. Exactly one retry,
			// inside this execution, without the optional field.
			$this->marker_support = MarkerSupport::UNAVAILABLE;
			unset( $body['custom_metadata'] );

			$response = $this->request( 'POST', '/zones/' . $this->zone_id . '/custom_hostnames', $body );
		}

		if ( $this->has_error( $response, self::ERROR_DUPLICATE ) ) {
			// Read, never re-POST.
			$this->get_hostname( $ctx );

			return new SslStatus( SslState::NONE, null, 'duplicate_record', 'A resource already exists for this hostname.' );
		}

		$payload = $this->payload( $response );

		if ( null === $payload ) {
			return $this->status_from_failure( $response );
		}

		if ( (string) ( $payload['hostname'] ?? '' ) !== $ctx->host ) {
			return new SslStatus( SslState::FAILED, null, 'hostname_mismatch', 'The provider returned a different hostname.' );
		}

		return $this->status_from_payload( $payload );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( MutationKind::ADOPT );

		if ( MarkerSupport::UNAVAILABLE === $this->marker_support || null === $ctx->provider_ref ) {
			return $this->status( $ctx );
		}

		$this->request(
			'PATCH',
			'/zones/' . $this->zone_id . '/custom_hostnames/' . $ctx->provider_ref,
			array(
				'custom_metadata' => array(
					'pd_install' => $ctx->installation_id,
					'pd_mapping' => (string) $ctx->mapping_id,
				),
			)
		);

		return $this->status( $ctx );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_kind( MutationKind::METHOD );

		$this->request(
			'PATCH',
			'/zones/' . $this->zone_id . '/custom_hostnames/' . (string) $ctx->provider_ref,
			array( 'ssl' => array( 'method' => $method, 'type' => 'dv' ) )
		);

		// Persist only what a re-read confirms.
		return $this->status( $ctx );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_kind( MutationKind::REMOVE );

		$response = $this->request(
			'DELETE',
			'/zones/' . $this->zone_id . '/custom_hostnames/' . (string) $ctx->provider_ref,
			null
		);

		if ( 404 === $response->status ) {
			return new RemovalResult( RemovalOutcome::REMOVED, 'already_absent' );
		}

		if ( 429 === $response->status ) {
			$retry = (int) ( $response->headers['retry-after'] ?? 60 );
			Cooldown::set( $this->id(), $retry, '429' );

			return new RemovalResult( RemovalOutcome::TRANSIENT, 'rate_limited', null, $retry );
		}

		if ( $this->is_transient( $response ) ) {
			return new RemovalResult( RemovalOutcome::TRANSIENT, 'transient' );
		}

		return 200 === $response->status
			? new RemovalResult( RemovalOutcome::REMOVED )
			: new RemovalResult( RemovalOutcome::FAILED, 'provider_error' );
	}

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport {
		$statuses = array();

		foreach ( $contexts as $context ) {
			$statuses[ $context->host ] = $this->status( $context );
		}

		return new ReconcileReport( $statuses, true );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		$payload = $this->get_hostname( $ctx )['payload'] ?? array();

		$capability = $apex instanceof ApexCapability
			? $apex
			: ApexCapability::validated( apply_filters( 'pd_apex_capability', $this->derive_apex(), $ctx->host, null ) );

		return CloudflareValidationPlan::build(
			is_array( $payload ) ? $payload : array(),
			$this->cname_target,
			$capability,
			PublicSuffix::is_apex( $ctx->host ),
			$ctx->challenge_name,
			$ctx->challenge_value
		);
	}

	private function derive_apex(): ApexCapability {
		$targets = Credentials::apex_targets();

		if ( array() === $targets ) {
			return new ApexCapability(
				ApexRouting::CNAME_FLATTENING,
				'no apex proxy targets configured; relying on CNAME flattening',
				array(),
				null,
				false
			);
		}

		return new ApexCapability(
			ApexRouting::APEX_PROXY,
			'apex proxy targets configured',
			$targets,
			Credentials::apex_provenance(),
			true
		);
	}

	/** @return array{payload: array<string, mixed>|null, response: HttpResponse} */
	private function get_hostname( SslResourceContext $ctx ): array {
		$path = null !== $ctx->provider_ref
			? '/zones/' . $this->zone_id . '/custom_hostnames/' . $ctx->provider_ref
			: '/zones/' . $this->zone_id . '/custom_hostnames?hostname=' . rawurlencode( $ctx->host );

		$response = $this->request( 'GET', $path, null );
		$payload  = $this->payload( $response );

		if ( is_array( $payload ) && isset( $payload[0] ) && is_array( $payload[0] ) ) {
			$payload = $payload[0];
		}

		return array( 'payload' => is_array( $payload ) ? $payload : null, 'response' => $response );
	}

	/** @param array<string, mixed> $payload */
	private function status_from_payload( array $payload ): SslStatus {
		$ssl      = is_array( $payload['ssl'] ?? null ) ? $payload['ssl'] : array();
		$combined = CloudflareStatusMap::combine(
			isset( $payload['status'] ) ? (string) $payload['status'] : null,
			isset( $ssl['status'] ) ? (string) $ssl['status'] : null
		);

		$code = CloudflareStatusMap::classify_errors(
			is_array( $payload['verification_errors'] ?? null ) ? $payload['verification_errors'] : array(),
			is_array( $ssl['validation_errors'] ?? null ) ? $ssl['validation_errors'] : array()
		);

		return new SslStatus(
			$combined['state'],
			isset( $payload['id'] ) ? (string) $payload['id'] : null,
			$combined['unknown'] ? 'unknown_provider_state' : $code,
			null,
			isset( $ssl['method'] ) ? (string) $ssl['method'] : null,
			false,
			array(
				'hostname_status'     => $payload['status'] ?? null,
				'ssl_status'          => $ssl['status'] ?? null,
				'verification_errors' => $payload['verification_errors'] ?? array(),
				'validation_errors'   => $ssl['validation_errors'] ?? array(),
			)
		);
	}

	private function status_from_failure( HttpResponse $response ): SslStatus {
		if ( $this->is_transient( $response ) ) {
			return new SslStatus( SslState::NONE, null, 'transient', 'The provider did not answer.', null, true );
		}

		return new SslStatus( SslState::FAILED, null, 'provider_error', 'The provider rejected the request.' );
	}

	private function is_transient( HttpResponse $response ): bool {
		return null !== $response->error || 0 === $response->status || $response->status >= 500 || 429 === $response->status;
	}

	/** @param array<string, mixed>|null $body */
	private function request( string $method, string $path, ?array $body ): HttpResponse {
		$opts = array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$opts['body'] = (string) wp_json_encode( $body );
		}

		return $this->http->request( $method, self::API . $path, $opts );
	}

	/** @return array<string, mixed>|null */
	private function payload( HttpResponse $response ): ?array {
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) || true !== ( $decoded['success'] ?? false ) ) {
			return null;
		}

		return is_array( $decoded['result'] ?? null ) ? $decoded['result'] : null;
	}

	private function has_error( HttpResponse $response, int $code ): bool {
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) || ! is_array( $decoded['errors'] ?? null ) ) {
			return false;
		}

		foreach ( $decoded['errors'] as $error ) {
			if ( is_array( $error ) && (int) ( $error['code'] ?? 0 ) === $code ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $payload */
	private function parse_marker( array $payload ): ?ProviderMarker {
		$metadata = $payload['custom_metadata'] ?? null;

		if ( ! is_array( $metadata ) ) {
			return null;
		}

		$this->marker_support = MarkerSupport::SUPPORTED;

		return new ProviderMarker(
			isset( $metadata['pd_install'] ) ? (string) $metadata['pd_install'] : null,
			isset( $metadata['pd_mapping'] ) ? (int) $metadata['pd_mapping'] : null,
			$metadata
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CloudflareSaasDriverTest`
Expected: PASS — 12 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Ssl/Credentials.php src/Ssl/CloudflareSaasDriver.php tests/integration/Ssl/CloudflareSaasDriverTest.php
git commit -m "Add the Cloudflare for SaaS driver, working without paid entitlements

Error 1413 is a definitive rejection: one retry without custom_metadata inside
the same execution. A timeout or 5xx grants no retry at all, because those are
ambiguous and are resolved by reading."
```

---

### Task 6: Making the driver reachable in production

**Files:**
- Modify: `src/Ssl/Credentials.php` (add `::cloudflare_is_configured()`), `src/Ssl/DriverFactory.php` (`built_in_drivers()`)
- Test: `tests/integration/Ssl/CloudflareRegistrationTest.php`

**Interfaces:**
- Consumes: `DriverFactory`, `DriverUnavailable` (Plan 07 Task 9), `CloudflareSaasDriver`, `Credentials` (Task 5).
- Produces: `PostDomain\Ssl\Credentials::cloudflare_is_configured(): bool`, and a `DriverFactory::built_in_drivers()` that constructs `CloudflareSaasDriver` when that returns true.

Until this task the driver exists but nothing in production constructs it: every
caller resolves through `DriverFactory`, whose built-in list is empty. This is
the task that closes that gap. The factory's shape does not change — only the one
method that says which built-in drivers this build ships.

Incomplete credentials do **not** register a broken driver. An unregistered
driver produces `DriverUnavailable( 'driver_not_registered', 'cloudflare-saas' )`,
which the REST layer reports as a configuration problem — the opposite of a
half-built driver failing later with a transport error.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Ssl/CloudflareRegistrationTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Ssl\BoundResource;
use PostDomain\Ssl\CloudflareSaasDriver;
use PostDomain\Ssl\Credentials;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class CloudflareRegistrationTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_settings' );
		delete_option( 'pd_ssl_credentials' );
		DriverFactory::reset();
		$this->repo = new DbRepository();
	}

	public function tear_down(): void {
		delete_option( 'pd_ssl_credentials' );
		delete_option( 'pd_settings' );
		DriverFactory::reset();
		parent::tear_down();
	}

	/** @param array<string, string> $overrides */
	private function configure( array $overrides = array() ): void {
		update_option(
			'pd_ssl_credentials',
			array_merge(
				array(
					'api_token'    => 'cf-token-value',
					'zone_id'      => 'zone-1',
					'cname_target' => 'saas.example.net',
				),
				$overrides
			),
			false
		);
		update_option( 'pd_settings', array( 'ssl_driver' => 'cloudflare-saas' ), false );
		DriverFactory::reset();
	}

	private function unprovisioned(): Mapping {
		return $this->repo->save(
			new Mapping(
				0, 'mapped.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);
	}

	public function test_complete_credentials_register_the_driver(): void {
		$this->configure();

		$this->assertTrue( Credentials::cloudflare_is_configured() );
		$this->assertContains( 'cloudflare-saas', DriverFactory::registry()->ids() );
	}

	public function test_a_new_mapping_provisions_through_cloudflare(): void {
		$this->configure();

		$this->assertInstanceOf(
			CloudflareSaasDriver::class,
			DriverFactory::for_mapping( $this->unprovisioned() ),
			'a stored provider of null must resolve through the configured selection'
		);
	}

	/**
	 * @dataProvider missing_credentials
	 */
	public function test_incomplete_credentials_register_nothing( string $missing ): void {
		$this->configure( array( $missing => '' ) );

		$this->assertFalse( Credentials::cloudflare_is_configured() );
		$this->assertNotContains( 'cloudflare-saas', DriverFactory::registry()->ids() );
	}

	/** @return array<string, array{0: string}> */
	public static function missing_credentials(): array {
		return array(
			'no api token'    => array( 'api_token' ),
			'no zone id'      => array( 'zone_id' ),
			'no cname target' => array( 'cname_target' ),
		);
	}

	public function test_missing_credentials_produce_a_named_configuration_refusal(): void {
		$this->configure( array( 'api_token' => '' ) );

		$result = DriverFactory::for_mapping( $this->unprovisioned() );

		$this->assertInstanceOf( DriverUnavailable::class, $result );
		$this->assertSame( 'driver_not_registered', $result->reason );
		$this->assertSame( 'cloudflare-saas', $result->driver_id );
	}

	public function test_no_credential_value_is_exposed_by_the_registry(): void {
		$this->configure();

		$encoded = (string) wp_json_encode( DriverFactory::registry()->ids() );

		$this->assertStringNotContainsString( 'cf-token-value', $encoded );
	}

	public function test_the_environment_identity_names_the_zone_and_holds_no_credential(): void {
		$this->configure();

		$driver = DriverFactory::registry()->get( 'cloudflare-saas' );

		$this->assertSame( 'cf-zone:zone-1', $driver?->environment_id() );
		$this->assertStringNotContainsString( 'cf-token-value', (string) $driver?->environment_id() );
	}

	public function test_a_different_zone_is_a_different_environment(): void {
		$this->configure();
		$first = DriverFactory::registry()->get( 'cloudflare-saas' )?->environment_id();

		$this->configure( array( 'zone_id' => 'zone-2' ) );
		$second = DriverFactory::registry()->get( 'cloudflare-saas' )?->environment_id();

		$this->assertNotSame( $first, $second, 'recovery has to be able to tell these apart' );
	}

	public function test_a_provisioned_mapping_records_the_zone_it_lives_in(): void {
		$this->configure();

		// A resource bound in zone-1 stays readable only while zone-1 is configured.
		$bound = $this->repo->save(
			new Mapping(
				0, 'bound.test', null, self::factory()->post->create(), 1,
				VerificationState::VERIFIED, ActivationState::ACTIVE, SslState::ACTIVE,
				null, str_repeat( 'b', 32 ), '_post-domain-challenge',
				OwnershipOrigin::CREATED, Environment::installation_id(),
				'cloudflare-saas', 'cf-zone:zone-1', 'ref-1'
			)
		);

		$this->assertInstanceOf( CloudflareSaasDriver::class, BoundResource::driver_for( $bound ) );

		$this->configure( array( 'zone_id' => 'zone-2' ) );

		$refusal = BoundResource::driver_for( $this->repo->by_id( $bound->id ) );

		$this->assertInstanceOf( DriverUnavailable::class, $refusal );
		$this->assertSame( 'provider_environment_changed', $refusal->reason );
		$this->assertSame( 'cf-zone:zone-1', $refusal->driver_id, 'the refusal names what to restore' );
	}

	public function test_rest_and_cron_resolve_the_same_driver_instance(): void {
		$this->configure();

		$mapping = $this->unprovisioned();

		$this->assertSame(
			DriverFactory::for_mapping( $mapping ),
			DriverFactory::for_mapping( $mapping ),
			'one registry, one instance, whichever entry point asked'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter CloudflareRegistrationTest`
Expected: FAIL — `Error: Call to undefined method PostDomain\Ssl\Credentials::cloudflare_is_configured()`

- [ ] **Step 3: Write minimal implementation**

Add to `src/Ssl/Credentials.php`:

```php
	/** Every value the driver's constructor needs, present and non-empty. */
	public static function cloudflare_is_configured(): bool {
		return '' !== self::api_token()
			&& '' !== self::zone_id()
			&& '' !== self::cname_target();
	}
```

Replace `DriverFactory::built_in_drivers()` with:

```php
	/**
	 * Built-in drivers that are configured well enough to construct. A driver
	 * missing part of its configuration is left unregistered on purpose: a named
	 * "not registered" refusal is more useful than a half-built driver that
	 * fails later inside a transport call.
	 *
	 * @return SslDriver[]
	 */
	private static function built_in_drivers(): array {
		$drivers = array();

		if ( Credentials::cloudflare_is_configured() ) {
			$drivers[] = new CloudflareSaasDriver(
				new \PostDomain\Support\WpHttpClient(),
				Credentials::api_token(),
				Credentials::zone_id(),
				Credentials::cname_target()
			);
		}

		return $drivers;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter CloudflareRegistrationTest`
Expected: PASS — 12 tests (including the three missing-credential cases)

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Ssl/Credentials.php src/Ssl/DriverFactory.php tests/integration/Ssl/CloudflareRegistrationTest.php
git commit -m "Register the Cloudflare driver in the one production factory

Complete credentials put it in the registry; incomplete ones leave it out, so a
misconfiguration reads as a configuration problem rather than as a transport
failure halfway through a provisioning run."
```

---

## Gate for Plan 09

```bash
composer generate:status-map && git diff --exit-code references/cloudflare-status-map.php
composer lint && composer analyse && composer test && composer test:integration
```

Plus: generation fails on an unclassified value, `environment_id()` names the zone
and contains no credential, the driver operates end to end
with `marker_support = UNAVAILABLE`, no apex A record is emitted without an
attested provenance, and a mapping whose stored provider is null resolves to the
configured Cloudflare driver rather than to `NullDriver`.
