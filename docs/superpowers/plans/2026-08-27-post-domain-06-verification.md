# post-domain 06 — Verification subsystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A domain proves ownership by publishing a permanent TXT record, verified
over DNS-over-HTTPS with two-resolver agreement, and a transient failure can never
deactivate a live mapping.

**Architecture:** The resolver returns an RCODE-derived outcome, not a boolean.
`GracePolicy` is pure arithmetic over that outcome. `Verifier` takes a per-mapping
lease before resolving and discards any result whose CAS fails, because a result
that arrives after the row changed answers a question nobody is asking any more.

**Tech Stack:** As Plans 01–05.

**Spec:** `docs/superpowers/specs/2026-08-27-post-domain-design.md`

## Global Constraints

Inherit Plans 01–05, and add:

- **`DohResolver` is the authoritative default.** A hard outcome requires two
  independent endpoints to agree (spec §13.2).
- **`NativeDnsResolver` may emit only `MATCH`, `MISMATCH`, or `TRANSIENT`.** It
  can never deactivate a verified mapping (spec §13.2).
- **Transient outcomes never touch `hard_failure_count`** and never deactivate
  (spec §12.7).
- **Grace arithmetic increments first, then compares `>=`** (spec §12.7).
- **`pd_txt_record_label` runs only at create and rotate;** ordinary verification
  composes the name from persisted data (spec §13.1).
- **Corrupt persisted challenge data sets `integrity_error`,** which is
  `BROKEN_503`, not a soft warning (spec §13.1).
- **A DNS result whose CAS fails is discarded, never replayed** (spec §13.5).
- **These are public verification resolvers, not an authoritative-DNS
  requirement** (spec §13.2, §14.14).

---

## File map

| File | Responsibility |
|---|---|
| `src/Verification/Challenge.php` | Token generation, label validation, record composition |
| `src/Verification/DnsOutcome.php` | The five outcomes |
| `src/Verification/DnsResult.php` | Outcome plus observed values |
| `src/Contracts/DnsResolver.php` | The resolver interface |
| `src/Verification/DohResolver.php` | Two-endpoint DNS-over-HTTPS, hardened transport |
| `src/Verification/NativeDnsResolver.php` | Restricted fallback for hosts without outbound HTTPS |
| `src/Verification/GracePolicy.php` | Pure counter and state arithmetic |
| `src/Verification/Verifier.php` | Lease, resolve, apply under CAS, record the event |
| `src/Verification/FreshProof.php` | Live proof with no stored state, for provider mutations |
| `src/Verification/Schedule.php` | Cron registration, due-work selection, budget, continuation |

---

### Task 1: Challenge tokens and record composition

**Files:**
- Create: `src/Verification/Challenge.php`
- Test: `tests/integration/Verification/ChallengeTest.php`

**Interfaces:**
- Consumes: `Mapping` (Plan 02).
- Produces:
  - `PostDomain\Verification\Challenge::token(): string` — 32 lowercase hex.
  - `::label_for( Mapping $m ): string` — runs `pd_txt_record_label`, validated.
  - `::record_name( string $label, string $host ): ?string` — composed and validated, `null` when it cannot fit.
  - `::expected_value( string $token ): string` — `post-domain-verify=<token>`.
  - `::max_host_length( string $label ): int`.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Verification/ChallengeTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Verification\Challenge;
use WP_UnitTestCase;

final class ChallengeTest extends WP_UnitTestCase {

	private function mapping( string $host = 'example.test' ): Mapping {
		return new Mapping(
			1, $host, null, 42, 1,
			VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
			null, str_repeat( 'a', 32 ), '_post-domain-challenge'
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_txt_record_label' );
		parent::tear_down();
	}

	public function test_a_token_is_32_lowercase_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Challenge::token() );
	}

	public function test_tokens_do_not_repeat(): void {
		$this->assertNotSame( Challenge::token(), Challenge::token() );
	}

	public function test_the_default_label(): void {
		$this->assertSame( '_post-domain-challenge', Challenge::label_for( $this->mapping() ) );
	}

	public function test_the_record_name_is_label_dot_host(): void {
		$this->assertSame(
			'_post-domain-challenge.example.test',
			Challenge::record_name( '_post-domain-challenge', 'example.test' )
		);
	}

	public function test_the_expected_value_shape(): void {
		$this->assertSame(
			'post-domain-verify=' . str_repeat( 'a', 32 ),
			Challenge::expected_value( str_repeat( 'a', 32 ) )
		);
	}

	public function test_a_valid_custom_label_is_accepted(): void {
		add_filter( 'pd_txt_record_label', static fn(): string => '_acme-owner' );

		$this->assertSame( '_acme-owner', Challenge::label_for( $this->mapping() ) );
	}

	/**
	 * @dataProvider invalid_labels
	 */
	public function test_an_invalid_label_falls_back_to_the_default( string $label ): void {
		add_filter( 'pd_txt_record_label', static fn(): string => $label );

		$this->assertSame( '_post-domain-challenge', Challenge::label_for( $this->mapping() ) );
	}

	/** @return array<string, array{0: string}> */
	public static function invalid_labels(): array {
		return array(
			'contains a dot'   => array( 'a.b' ),
			'empty'            => array( '' ),
			'too long'         => array( str_repeat( 'a', 64 ) ),
			'leading hyphen'   => array( '-bad' ),
			'trailing hyphen'  => array( 'bad-' ),
			'illegal char'     => array( 'bad_label!' ),
		);
	}

	public function test_a_label_is_lowercased(): void {
		add_filter( 'pd_txt_record_label', static fn(): string => '_ACME-Owner' );

		$this->assertSame( '_acme-owner', Challenge::label_for( $this->mapping() ) );
	}

	public function test_the_default_label_leaves_230_bytes_for_the_host(): void {
		$this->assertSame( 230, Challenge::max_host_length( '_post-domain-challenge' ) );
	}

	public function test_a_composed_name_over_253_bytes_is_rejected(): void {
		$host = str_repeat( 'a', 60 ) . '.' . str_repeat( 'b', 60 ) . '.'
			. str_repeat( 'c', 60 ) . '.' . str_repeat( 'd', 45 ) . '.test';

		$this->assertGreaterThan( 230, strlen( $host ) );
		$this->assertNull( Challenge::record_name( '_post-domain-challenge', $host ) );
	}

	public function test_a_longer_label_shrinks_the_permitted_host(): void {
		$this->assertLessThan(
			Challenge::max_host_length( '_post-domain-challenge' ),
			Challenge::max_host_length( str_repeat( 'x', 40 ) )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter ChallengeTest`
Expected: FAIL — `Error: Class "PostDomain\Verification\Challenge" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/Challenge.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\Mapping;

final class Challenge {

	public const DEFAULT_LABEL = '_post-domain-challenge';

	public const VALUE_PREFIX = 'post-domain-verify=';

	private const MAX_NAME = 253;

	public static function token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Runs the filter. Called only at create and rotate — ordinary verification
	 * composes the name from the persisted label instead.
	 */
	public static function label_for( Mapping $mapping ): string {
		$label = (string) apply_filters( 'pd_txt_record_label', self::DEFAULT_LABEL, $mapping );
		$label = strtolower( $label );

		if ( 1 !== preg_match( '/^_?[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label ) || strlen( $label ) > 63 ) {
			return self::DEFAULT_LABEL;
		}

		return $label;
	}

	public static function record_name( string $label, string $host ): ?string {
		$name = $label . '.' . $host;

		if ( strlen( $name ) > self::MAX_NAME ) {
			return null;
		}

		foreach ( explode( '.', $name ) as $part ) {
			if ( '' === $part || strlen( $part ) > 63 ) {
				return null;
			}
		}

		return $name;
	}

	public static function expected_value( string $token ): string {
		return self::VALUE_PREFIX . $token;
	}

	public static function max_host_length( string $label ): int {
		return self::MAX_NAME - strlen( $label ) - 1;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter ChallengeTest`
Expected: PASS — 16 tests

- [ ] **Step 5: Commit**

```bash
git add src/Verification/Challenge.php tests/integration/Verification/ChallengeTest.php
git commit -m "Generate challenge tokens and compose the TXT record name

The 230-byte host limit is the composed name's 253-byte ceiling minus the
default label, so a longer custom label rejects a host that would overflow it."
```

---

### Task 2: The DNS-over-HTTPS resolver

**Files:**
- Create: `src/Verification/DnsOutcome.php`, `src/Verification/DnsResult.php`, `src/Contracts/DnsResolver.php`, `src/Verification/DohResolver.php`
- Test: `tests/unit/Verification/DohResolverTest.php`

**Interfaces:**
- Consumes: `HttpClient` (Plan 02).
- Produces: `PostDomain\Verification\DnsOutcome` enum, `DnsResult` (readonly `DnsOutcome $outcome`, `string[] $values`, `?string $error`), `PostDomain\Contracts\DnsResolver::txt( string $name, string $expected ): DnsResult`, and `DohResolver::__construct( HttpClient $http, string[] $endpoints )`.

`dns_get_record()` cannot supply an RCODE — it returns an empty array for
NXDOMAIN, NOERROR-with-no-TXT, and SERVFAIL alike. Treating that ambiguity as a
hard failure is how live domains get deactivated by a resolver hiccup (spec §13.2).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Verification/DohResolverTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Contracts\HttpClient;
use PostDomain\Support\HttpResponse;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DohResolver;

final class DohResolverTest extends TestCase {

	/**
	 * @param array<int, HttpResponse> $responses
	 */
	private function resolver( array $responses ): DohResolver {
		$client = new class( $responses ) implements HttpClient {
			/** @param array<int, HttpResponse> $responses */
			public function __construct( private array $responses ) {}

			public function request( string $method, string $url, array $opts = array() ): HttpResponse {
				return array_shift( $this->responses ) ?? new HttpResponse( 0, array(), '', 'exhausted' );
			}
		};

		return new DohResolver( $client, array( 'https://one.example/dns-query', 'https://two.example/dns-query' ) );
	}

	private function json( int $status, array $answers ): HttpResponse {
		return new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) wp_json_encode( array( 'Status' => $status, 'Answer' => $answers ) )
		);
	}

	private function txt( string $value ): array {
		return array( array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"' . $value . '"' ) );
	}

	public function test_both_endpoints_agreeing_on_the_value_is_a_match(): void {
		$result = $this->resolver(
			array(
				$this->json( 0, $this->txt( 'post-domain-verify=abc' ) ),
				$this->json( 0, $this->txt( 'post-domain-verify=abc' ) ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::MATCH, $result->outcome );
	}

	public function test_both_endpoints_agreeing_on_a_different_value_is_a_mismatch(): void {
		$result = $this->resolver(
			array(
				$this->json( 0, $this->txt( 'post-domain-verify=zzz' ) ),
				$this->json( 0, $this->txt( 'post-domain-verify=zzz' ) ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::MISMATCH, $result->outcome );
	}

	public function test_both_endpoints_agreeing_on_nxdomain(): void {
		$result = $this->resolver( array( $this->json( 3, array() ), $this->json( 3, array() ) ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NXDOMAIN, $result->outcome );
	}

	public function test_noerror_with_no_txt_is_no_record_not_nxdomain(): void {
		$result = $this->resolver( array( $this->json( 0, array() ), $this->json( 0, array() ) ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::NO_RECORD, $result->outcome );
	}

	public function test_disagreement_between_endpoints_is_transient(): void {
		$result = $this->resolver(
			array(
				$this->json( 0, $this->txt( 'post-domain-verify=abc' ) ),
				$this->json( 3, array() ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$result->outcome,
			'a hard outcome requires agreement'
		);
	}

	public function test_servfail_is_transient(): void {
		$result = $this->resolver( array( $this->json( 2, array() ), $this->json( 2, array() ) ) )
			->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_a_transport_error_is_transient(): void {
		$result = $this->resolver(
			array(
				new HttpResponse( 0, array(), '', 'timeout' ),
				new HttpResponse( 0, array(), '', 'timeout' ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_a_non_200_is_transient(): void {
		$result = $this->resolver(
			array(
				new HttpResponse( 502, array( 'content-type' => 'application/dns-json' ), '{}' ),
				new HttpResponse( 502, array( 'content-type' => 'application/dns-json' ), '{}' ),
			)
		)->txt( '_x.example', 'post-domain-verify=abc' );

		$this->assertSame( DnsOutcome::TRANSIENT, $result->outcome );
	}

	public function test_malformed_json_is_transient(): void {
		$broken = new HttpResponse( 200, array( 'content-type' => 'application/dns-json' ), 'not json' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$this->resolver( array( $broken, $broken ) )->txt( '_x.example', 'x' )->outcome
		);
	}

	public function test_a_wrong_shape_is_transient(): void {
		$wrong = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) wp_json_encode( array( 'Status' => 'zero', 'Answer' => 'nope' ) )
		);

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$this->resolver( array( $wrong, $wrong ) )->txt( '_x.example', 'x' )->outcome
		);
	}

	public function test_a_non_json_content_type_is_transient(): void {
		$html = new HttpResponse( 200, array( 'content-type' => 'text/html' ), '<html></html>' );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$this->resolver( array( $html, $html ) )->txt( '_x.example', 'x' )->outcome
		);
	}

	public function test_non_txt_answer_types_are_ignored(): void {
		$mixed = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) wp_json_encode(
				array(
					'Status' => 0,
					'Answer' => array(
						array( 'name' => '_x.example', 'type' => 5, 'TTL' => 300, 'data' => 'cname.example.' ),
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"post-domain-verify=abc"' ),
					),
				)
			)
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$this->resolver( array( $mixed, $mixed ) )->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_all_txt_values_are_examined(): void {
		$many = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) wp_json_encode(
				array(
					'Status' => 0,
					'Answer' => array(
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"v=spf1 -all"' ),
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"post-domain-verify=abc"' ),
					),
				)
			)
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$this->resolver( array( $many, $many ) )->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_multi_string_txt_values_are_concatenated(): void {
		$split = new HttpResponse(
			200,
			array( 'content-type' => 'application/dns-json' ),
			(string) wp_json_encode(
				array(
					'Status' => 0,
					'Answer' => array(
						array( 'name' => '_x.example', 'type' => 16, 'TTL' => 300, 'data' => '"post-domain-" "verify=abc"' ),
					),
				)
			)
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$this->resolver( array( $split, $split ) )->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter DohResolverTest`
Expected: FAIL — `Error: Enum "PostDomain\Verification\DnsOutcome" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/DnsOutcome.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

enum DnsOutcome: string {
	case MATCH     = 'match';
	case MISMATCH  = 'mismatch';
	case NO_RECORD = 'no_record';
	case NXDOMAIN  = 'nxdomain';
	case TRANSIENT = 'transient';

	public function is_hard(): bool {
		return in_array( $this, array( self::MISMATCH, self::NO_RECORD, self::NXDOMAIN ), true );
	}
}
```

Create `src/Verification/DnsResult.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

final class DnsResult {

	/** @param string[] $values */
	public function __construct(
		public readonly DnsOutcome $outcome,
		public readonly array $values = array(),
		public readonly ?string $error = null
	) {}
}
```

Create `src/Contracts/DnsResolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Verification\DnsResult;

interface DnsResolver {

	public function txt( string $name, string $expected ): DnsResult;
}
```

Create `src/Verification/DohResolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Contracts\HttpClient;

/**
 * The grace policy depends on an RCODE, which dns_get_record() cannot supply.
 * A hard outcome requires two independent endpoints to agree.
 */
final class DohResolver implements DnsResolver {

	private const MAX_BYTES = 65536;

	private const TYPE_TXT = 16;

	/** @param string[] $endpoints */
	public function __construct(
		private readonly HttpClient $http,
		private readonly array $endpoints
	) {}

	public function txt( string $name, string $expected ): DnsResult {
		$outcomes = array();
		$values   = array();

		foreach ( $this->endpoints as $endpoint ) {
			$single = $this->query( $endpoint, $name, $expected );

			$outcomes[] = $single->outcome;
			$values     = array_merge( $values, $single->values );
		}

		if ( array() === $outcomes ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'no endpoints configured' );
		}

		$distinct = array_unique( array_map( static fn( DnsOutcome $o ): string => $o->value, $outcomes ) );

		if ( 1 !== count( $distinct ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, $values, 'endpoints disagreed' );
		}

		return new DnsResult( $outcomes[0], array_values( array_unique( $values ) ) );
	}

	private function query( string $endpoint, string $name, string $expected ): DnsResult {
		if ( ! str_starts_with( $endpoint, 'https://' ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'endpoint is not https' );
		}

		$url = $endpoint . '?name=' . rawurlencode( $name ) . '&type=TXT';

		$response = $this->http->request(
			'GET',
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/dns-json' ),
			)
		);

		if ( null !== $response->error || 200 !== $response->status ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), $response->error ?? 'http ' . $response->status );
		}

		$type = strtolower( $response->headers['content-type'] ?? '' );

		if ( ! str_contains( $type, 'json' ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'unexpected content type' );
		}

		if ( strlen( $response->body ) > self::MAX_BYTES ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'oversize response' );
		}

		/** @var mixed $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['Status'] ) || ! is_int( $decoded['Status'] ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'malformed response' );
		}

		if ( 3 === $decoded['Status'] ) {
			return new DnsResult( DnsOutcome::NXDOMAIN );
		}

		if ( 0 !== $decoded['Status'] ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'rcode ' . $decoded['Status'] );
		}

		$answers = $decoded['Answer'] ?? array();

		if ( ! is_array( $answers ) ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'malformed answer section' );
		}

		$values = array();

		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) || ( $answer['type'] ?? null ) !== self::TYPE_TXT ) {
				continue;
			}

			$values[] = $this->unquote( (string) ( $answer['data'] ?? '' ) );
		}

		if ( array() === $values ) {
			return new DnsResult( DnsOutcome::NO_RECORD );
		}

		foreach ( $values as $value ) {
			if ( hash_equals( $expected, $value ) ) {
				return new DnsResult( DnsOutcome::MATCH, $values );
			}
		}

		return new DnsResult( DnsOutcome::MISMATCH, $values );
	}

	/** Concatenates the character-strings of one TXT record, per RFC 1035. */
	private function unquote( string $data ): string {
		if ( 0 === preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches ) ) {
			return trim( $data, '"' );
		}

		return implode( '', $matches[1] );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter DohResolverTest`
Expected: PASS — 14 tests

- [ ] **Step 5: Commit**

```bash
git add src/Verification/DnsOutcome.php src/Verification/DnsResult.php src/Contracts/DnsResolver.php src/Verification/DohResolver.php tests/unit/Verification/DohResolverTest.php
git commit -m "Resolve verification TXT records over DoH with two-endpoint agreement

NOERROR-with-no-TXT and NXDOMAIN are different answers, and only an RCODE tells
them apart. Any disagreement or malformed response is transient, never a hard
failure."
```

---

### Task 3: The restricted native resolver

**Files:**
- Create: `src/Verification/NativeDnsResolver.php`
- Test: `tests/unit/Verification/NativeDnsResolverTest.php`

**Interfaces:**
- Consumes: `DnsResolver` (Task 2).
- Produces: `PostDomain\Verification\NativeDnsResolver::__construct( ?callable $lookup = null )`.

It may emit only `MATCH`, `MISMATCH`, or `TRANSIENT` — every empty or failed
lookup is `TRANSIENT`, so it can never deactivate a verified mapping (spec §13.2).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Verification/NativeDnsResolverTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\NativeDnsResolver;

final class NativeDnsResolverTest extends TestCase {

	public function test_a_matching_record_is_a_match(): void {
		$resolver = new NativeDnsResolver(
			static fn(): array => array( array( 'type' => 'TXT', 'txt' => 'post-domain-verify=abc' ) )
		);

		$this->assertSame(
			DnsOutcome::MATCH,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_a_present_but_different_record_is_a_mismatch(): void {
		$resolver = new NativeDnsResolver(
			static fn(): array => array( array( 'type' => 'TXT', 'txt' => 'post-domain-verify=zzz' ) )
		);

		$this->assertSame(
			DnsOutcome::MISMATCH,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_an_empty_result_is_transient_not_no_record(): void {
		$resolver = new NativeDnsResolver( static fn(): array => array() );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome,
			'dns_get_record cannot tell an absent record from a failed lookup'
		);
	}

	public function test_a_failed_lookup_is_transient(): void {
		$resolver = new NativeDnsResolver( static fn(): bool => false );

		$this->assertSame(
			DnsOutcome::TRANSIENT,
			$resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome
		);
	}

	public function test_it_can_never_emit_a_hard_absence(): void {
		foreach ( array( array(), false, null ) as $lookup_result ) {
			$resolver = new NativeDnsResolver( static fn() => $lookup_result );
			$outcome  = $resolver->txt( '_x.example', 'post-domain-verify=abc' )->outcome;

			$this->assertNotSame( DnsOutcome::NO_RECORD, $outcome );
			$this->assertNotSame( DnsOutcome::NXDOMAIN, $outcome );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter NativeDnsResolverTest`
Expected: FAIL — `Error: Class "PostDomain\Verification\NativeDnsResolver" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/NativeDnsResolver.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;

/**
 * For hosts that cannot make outbound HTTPS calls. Restricted by design: it may
 * emit only MATCH, MISMATCH, or TRANSIENT, so it can never deactivate a verified
 * mapping. dns_get_record() returns an empty array for NXDOMAIN, for
 * NOERROR-with-no-TXT, and for SERVFAIL alike.
 */
final class NativeDnsResolver implements DnsResolver {

	/** @var callable */
	private $lookup;

	public function __construct( ?callable $lookup = null ) {
		$this->lookup = $lookup ?? static fn( string $name ) => @dns_get_record( $name, DNS_TXT );
	}

	public function txt( string $name, string $expected ): DnsResult {
		/** @var mixed $records */
		$records = ( $this->lookup )( $name );

		if ( ! is_array( $records ) || array() === $records ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'no answer, cause indistinguishable' );
		}

		$values = array();

		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['txt'] ) ) {
				$values[] = (string) $record['txt'];
			}
		}

		if ( array() === $values ) {
			return new DnsResult( DnsOutcome::TRANSIENT, array(), 'no TXT strings in the answer' );
		}

		foreach ( $values as $value ) {
			if ( hash_equals( $expected, $value ) ) {
				return new DnsResult( DnsOutcome::MATCH, $values );
			}
		}

		return new DnsResult( DnsOutcome::MISMATCH, $values );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter NativeDnsResolverTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Verification/NativeDnsResolver.php tests/unit/Verification/NativeDnsResolverTest.php
git commit -m "Restrict the native resolver to outcomes it can actually justify

It cannot distinguish an absent record from a failed lookup, so every empty
answer is transient. That makes it unable to deactivate a live mapping, which
is the correct behaviour for a resolver with no RCODE."
```

---

### Task 4: Grace arithmetic

**Files:**
- Create: `src/Verification/GracePolicy.php`
- Test: `tests/unit/Verification/GracePolicyTest.php`

**Interfaces:**
- Consumes: `DnsOutcome` (Task 2), `VerificationState` (Plan 02).
- Produces: `PostDomain\Verification\GracePolicy::apply( VerificationState $state, DnsOutcome $outcome, int $hard, int $transient, int $limit, bool $deadline_passed ): array{state: VerificationState, hard: int, transient: int}`.

Increment first, then compare `>=`. With the default 3: failures 1 and 2 keep the
mapping verified; failure 3 fails it (spec §12.7).

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Verification/GracePolicyTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use PostDomain\Mapping\VerificationState;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\GracePolicy;

final class GracePolicyTest extends TestCase {

	public function test_a_match_verifies_and_resets_both_counters(): void {
		$after = GracePolicy::apply( VerificationState::PENDING, DnsOutcome::MATCH, 2, 5, 3, false );

		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 0, $after['hard'] );
		$this->assertSame( 0, $after['transient'] );
	}

	public function test_the_first_two_hard_failures_keep_a_verified_mapping(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::NO_RECORD, 0, 0, 3, false );
		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 1, $after['hard'] );

		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::NO_RECORD, 1, 0, 3, false );
		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 2, $after['hard'] );
	}

	public function test_the_third_hard_failure_fails_the_mapping(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::NXDOMAIN, 2, 0, 3, false );

		$this->assertSame( VerificationState::FAILED, $after['state'] );
		$this->assertSame( 3, $after['hard'] );
	}

	public function test_a_transient_never_touches_the_hard_counter(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::TRANSIENT, 2, 0, 3, false );

		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
		$this->assertSame( 2, $after['hard'], 'the hard counter is untouched' );
		$this->assertSame( 1, $after['transient'] );
	}

	public function test_a_transient_can_never_fail_a_mapping(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::TRANSIENT, 2, 99, 3, true );

		$this->assertSame( VerificationState::VERIFIED, $after['state'] );
	}

	public function test_a_hard_failure_resets_the_transient_counter(): void {
		$after = GracePolicy::apply( VerificationState::VERIFIED, DnsOutcome::MISMATCH, 0, 7, 3, false );

		$this->assertSame( 0, $after['transient'], 'a hard answer proves the resolver is reachable' );
	}

	public function test_pending_stays_pending_until_the_deadline(): void {
		$after = GracePolicy::apply( VerificationState::PENDING, DnsOutcome::NO_RECORD, 5, 0, 3, false );

		$this->assertSame( VerificationState::PENDING, $after['state'] );
	}

	public function test_pending_fails_when_the_deadline_passes(): void {
		$after = GracePolicy::apply( VerificationState::PENDING, DnsOutcome::NO_RECORD, 0, 0, 3, true );

		$this->assertSame( VerificationState::FAILED, $after['state'] );
	}

	public function test_a_failed_mapping_can_reach_verified_only_through_pending(): void {
		$after = GracePolicy::apply( VerificationState::FAILED, DnsOutcome::MATCH, 0, 0, 3, false );

		$this->assertSame(
			VerificationState::FAILED,
			$after['state'],
			'a failed mapping is re-checked only after an explicit reset to pending'
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter GracePolicyTest`
Expected: FAIL — `Error: Class "PostDomain\Verification\GracePolicy" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/GracePolicy.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\VerificationState;

final class GracePolicy {

	/**
	 * @return array{state: VerificationState, hard: int, transient: int}
	 */
	public static function apply(
		VerificationState $state,
		DnsOutcome $outcome,
		int $hard,
		int $transient,
		int $limit,
		bool $deadline_passed
	): array {
		if ( DnsOutcome::TRANSIENT === $outcome ) {
			// The hard counter is untouched, and no transient result deactivates.
			return array(
				'state'     => $state,
				'hard'      => $hard,
				'transient' => $transient + 1,
			);
		}

		if ( DnsOutcome::MATCH === $outcome ) {
			return array(
				'state'     => VerificationState::FAILED === $state
					? VerificationState::FAILED
					: VerificationState::VERIFIED,
				'hard'      => 0,
				'transient' => 0,
			);
		}

		// A hard answer proves the resolver is reachable.
		++$hard;

		if ( VerificationState::PENDING === $state ) {
			return array(
				'state'     => $deadline_passed ? VerificationState::FAILED : VerificationState::PENDING,
				'hard'      => $hard,
				'transient' => 0,
			);
		}

		if ( VerificationState::VERIFIED === $state ) {
			return array(
				'state'     => $hard >= $limit ? VerificationState::FAILED : VerificationState::VERIFIED,
				'hard'      => $hard,
				'transient' => 0,
			);
		}

		return array( 'state' => $state, 'hard' => $hard, 'transient' => 0 );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter GracePolicyTest`
Expected: PASS — 9 tests

- [ ] **Step 5: Commit**

```bash
git add src/Verification/GracePolicy.php tests/unit/Verification/GracePolicyTest.php
git commit -m "Increment then compare, and keep transient results out of the count

With the default limit of three, failures one and two keep a verified mapping
and the third fails it. A transient result never reaches the hard counter, so a
nameserver blip cannot deactivate a live domain."
```

---

### Task 5: The verifier, its lease, and the discarded result

**Files:**
- Create: `src/Verification/Verifier.php`
- Test: `tests/integration/Verification/VerifierTest.php`

**Interfaces:**
- Consumes: `DnsResolver` (Task 2), `GracePolicy` (Task 4), `MappingRepository`, `EventLog` (Plan 02), `Clock` (Plan 02).
- Produces: `PostDomain\Verification\Verifier::__construct( MappingRepository $repo, DnsResolver $resolver, Clock $clock )` and `::verify( Mapping $m ): DnsOutcome`.

A DNS result whose CAS fails is **discarded, never replayed** (spec §13.5).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Verification/VerifierTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Support\SystemClock;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\Verifier;
use WP_UnitTestCase;

final class VerifierTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
	}

	private function stub( DnsOutcome $outcome ): DnsResolver {
		return new class( $outcome ) implements DnsResolver {
			public function __construct( private readonly DnsOutcome $outcome ) {}

			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult( $this->outcome );
			}
		};
	}

	private function seed( VerificationState $state, int $hard = 0 ): Mapping {
		$mapping = $this->repo->save(
			new Mapping(
				0, 'example.test', null, self::factory()->post->create(), 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, str_repeat( 'a', 32 ), '_post-domain-challenge'
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'verification_state' => $state->value, 'hard_failure_count' => $hard ),
			array( 'id' => $mapping->id )
		);

		return (object) $this->repo->by_id( $mapping->id ) === null
			? $mapping
			: $this->repo->by_id( $mapping->id );
	}

	public function test_a_match_verifies_the_mapping(): void {
		$mapping  = $this->seed( VerificationState::PENDING );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::MATCH ), new SystemClock() );

		$this->assertSame( DnsOutcome::MATCH, $verifier->verify( $mapping ) );
		$this->assertSame( VerificationState::VERIFIED, $this->repo->by_id( $mapping->id )?->verification_state );
	}

	public function test_the_third_hard_failure_fails_a_verified_mapping(): void {
		$mapping  = $this->seed( VerificationState::VERIFIED, 2 );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::NO_RECORD ), new SystemClock() );

		$verifier->verify( $mapping );

		$this->assertSame( VerificationState::FAILED, $this->repo->by_id( $mapping->id )?->verification_state );
	}

	public function test_a_transient_leaves_a_verified_mapping_verified(): void {
		$mapping  = $this->seed( VerificationState::VERIFIED, 2 );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::TRANSIENT ), new SystemClock() );

		$verifier->verify( $mapping );

		$after = $this->repo->by_id( $mapping->id );

		$this->assertSame( VerificationState::VERIFIED, $after?->verification_state );
	}

	public function test_the_resolver_class_is_recorded(): void {
		$mapping  = $this->seed( VerificationState::PENDING );
		$resolver = $this->stub( DnsOutcome::MATCH );

		( new Verifier( $this->repo, $resolver, new SystemClock() ) )->verify( $mapping );

		global $wpdb;
		$recorded = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT resolver_class FROM ' . Schema::domains_table() . ' WHERE id = %d',
				$mapping->id
			)
		);

		$this->assertSame( $resolver::class, $recorded );
	}

	public function test_an_event_is_written(): void {
		$mapping = $this->seed( VerificationState::PENDING );

		( new Verifier( $this->repo, $this->stub( DnsOutcome::MATCH ), new SystemClock() ) )->verify( $mapping );

		$events = EventLog::for_domain( $mapping->id );

		$this->assertNotEmpty( $events );
		$this->assertSame( 'verification', $events[0]['type'] );
	}

	public function test_a_result_is_discarded_when_the_row_changed_underneath(): void {
		$mapping = $this->seed( VerificationState::PENDING );

		$racing = new class( $this->repo, $mapping ) implements DnsResolver {
			public function __construct(
				private readonly DbRepository $repo,
				private readonly Mapping $mapping
			) {}

			public function txt( string $name, string $expected ): DnsResult {
				// Rotate the challenge while the query is in flight.
				global $wpdb;
				$wpdb->update( // phpcs:ignore WordPress.DB
					Schema::domains_table(),
					array( 'challenge' => str_repeat( 'z', 32 ), 'revision' => 99 ),
					array( 'id' => $this->mapping->id )
				);

				return new DnsResult( DnsOutcome::MATCH );
			}
		};

		( new Verifier( $this->repo, $racing, new SystemClock() ) )->verify( $mapping );

		$this->assertNotSame(
			VerificationState::VERIFIED,
			$this->repo->by_id( $mapping->id )?->verification_state,
			'the result answered a question that is no longer being asked'
		);
	}

	public function test_a_corrupt_challenge_label_sets_the_integrity_error(): void {
		$mapping = $this->seed( VerificationState::PENDING );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'challenge_label' => 'has.a.dot' ),
			array( 'id' => $mapping->id )
		);

		$reloaded = $this->repo->by_id( $mapping->id );
		$verifier = new Verifier( $this->repo, $this->stub( DnsOutcome::MATCH ), new SystemClock() );

		$verifier->verify( $reloaded );

		$this->assertNotNull( $this->repo->by_id( $mapping->id )?->integrity_error );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter VerifierTest`
Expected: FAIL — `Error: Class "PostDomain\Verification\Verifier" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/Verifier.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\DnsResolver;
use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;

final class Verifier {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly DnsResolver $resolver,
		private readonly Clock $clock
	) {}

	public function verify( Mapping $mapping ): DnsOutcome {
		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			$this->mark_corrupt( $mapping, 'challenge_name_invalid' );

			return DnsOutcome::TRANSIENT;
		}

		$token = $this->take_lease( $mapping );

		if ( null === $token ) {
			return DnsOutcome::TRANSIENT;
		}

		$result = $this->resolver->txt( $name, Challenge::expected_value( $mapping->challenge ) );

		$limit = (int) apply_filters( 'pd_verification_grace', 3 );
		$limit = max( 1, $limit );

		$after = GracePolicy::apply(
			$mapping->verification_state,
			$result->outcome,
			0,
			0,
			$limit,
			false
		);

		$this->apply_under_cas( $mapping, $token, $result, $after, $limit );

		return $result->outcome;
	}

	private function take_lease( Mapping $mapping ): ?string {
		global $wpdb;

		$token   = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + 120 );
		$now     = $this->clock->mysql();
		$table   = Schema::domains_table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET verify_lease_token = %s, verify_lease_expires_at = %s, revision = revision + 1
				  WHERE id = %d AND revision = %d
				    AND ( verify_lease_expires_at IS NULL OR verify_lease_expires_at <= %s )",
				$token,
				$expires,
				$mapping->id,
				$mapping->revision,
				$now
			)
		);

		return 1 === $affected ? $token : null;
	}

	/**
	 * @param array{state: VerificationState, hard: int, transient: int} $after
	 */
	private function apply_under_cas(
		Mapping $mapping,
		string $token,
		DnsResult $result,
		array $after,
		int $limit
	): void {
		global $wpdb;

		$table = Schema::domains_table();
		$now   = $this->clock->mysql();

		$next = DnsOutcome::TRANSIENT === $result->outcome
			? $this->clock->now()->getTimestamp() + 1800
			: $this->clock->now()->getTimestamp() + ( VerificationState::VERIFIED === $after['state'] ? 86400 : 900 );

		$sql = "UPDATE {$table}
		           SET verification_state = %s,
		               hard_failure_count = CASE WHEN %s = 'transient' THEN hard_failure_count
		                                          WHEN %s = 'match' THEN 0
		                                          ELSE hard_failure_count + 1 END,
		               transient_failure_count = CASE WHEN %s = 'transient'
		                                              THEN transient_failure_count + 1 ELSE 0 END,
		               last_outcome = %s,
		               last_checked_at = %s,
		               verified_at = CASE WHEN %s = 'match' THEN %s ELSE verified_at END,
		               verify_next_attempt_at = %s,
		               resolver_class = %s,
		               verify_lease_token = NULL,
		               verify_lease_expires_at = NULL,
		               revision = revision + 1,
		               updated_at = %s
		         WHERE id = %d AND verify_lease_token = %s AND challenge = %s";

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				$sql,
				$this->resolved_state( $mapping, $result, $limit )->value,
				$result->outcome->value,
				$result->outcome->value,
				$result->outcome->value,
				$result->outcome->value,
				$now,
				$result->outcome->value,
				$now,
				gmdate( 'Y-m-d H:i:s', $next ),
				$this->resolver::class,
				$now,
				$mapping->id,
				$token,
				$mapping->challenge
			)
		);

		if ( 1 !== $affected ) {
			// The row changed underneath the attempt. Discard, never replay.
			return;
		}

		EventLog::record(
			$mapping->id,
			$mapping->host,
			'verification',
			$mapping->verification_state->value,
			$this->resolved_state( $mapping, $result, $limit )->value,
			'cron',
			array(
				'outcome'        => $result->outcome->value,
				'resolver_class' => $this->resolver::class,
				'attempt_id'     => $token,
			)
		);
	}

	private function resolved_state( Mapping $mapping, DnsResult $result, int $limit ): VerificationState {
		global $wpdb;

		$table = Schema::domains_table();
		$hard  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT hard_failure_count FROM {$table} WHERE id = %d", $mapping->id ) // phpcs:ignore WordPress.DB
		);

		$deadline_passed = false;

		return GracePolicy::apply(
			$mapping->verification_state,
			$result->outcome,
			$hard,
			0,
			$limit,
			$deadline_passed
		)['state'];
	}

	private function mark_corrupt( Mapping $mapping, string $reason ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'integrity_error' => $reason, 'updated_at' => $this->clock->mysql() ),
			array( 'id' => $mapping->id )
		);

		EventLog::record( $mapping->id, $mapping->host, 'verification', null, null, 'cron', array( 'integrity' => $reason ) );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter VerifierTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Verification/Verifier.php tests/integration/Verification/VerifierTest.php
git commit -m "Lease a mapping before resolving and discard a stale result

If the row changed while the query was in flight, the answer describes a
question nobody is asking any more. The next sweep resolves again."
```

---

### Task 6: Fresh proof

**Files:**
- Create: `src/Verification/FreshProof.php`
- Test: `tests/integration/Verification/FreshProofTest.php`

**Interfaces:**
- Consumes: `DnsResolver` (Task 2), `Challenge` (Task 1).
- Produces: `PostDomain\Verification\FreshProof::__construct( DnsResolver $resolver )` and `::prove( Mapping $m ): DnsOutcome`.

It never reads `verification_state`, `verified_at`, or `last_outcome`. It exists
because cached verification state is not sufficient authorization for a provider
mutation, and because a rotated challenge must invalidate any authority a copy of
the database might otherwise claim (spec §13.4).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Verification/FreshProofTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Verification\DnsOutcome;
use PostDomain\Verification\DnsResult;
use PostDomain\Verification\FreshProof;
use WP_UnitTestCase;

final class FreshProofTest extends WP_UnitTestCase {

	private function mapping( string $challenge, VerificationState $state ): Mapping {
		return new Mapping(
			1, 'example.test', null, 42, 1,
			$state, ActivationState::ACTIVE, SslState::ACTIVE,
			null, $challenge, '_post-domain-challenge'
		);
	}

	private function resolver_expecting( string $expected_value ): DnsResolver {
		return new class( $expected_value ) implements DnsResolver {
			public function __construct( private readonly string $published ) {}

			public function txt( string $name, string $expected ): DnsResult {
				return new DnsResult(
					hash_equals( $expected, $this->published ) ? DnsOutcome::MATCH : DnsOutcome::MISMATCH
				);
			}
		};
	}

	public function test_the_current_challenge_proves(): void {
		$challenge = str_repeat( 'a', 32 );
		$proof     = new FreshProof( $this->resolver_expecting( 'post-domain-verify=' . $challenge ) );

		$this->assertSame(
			DnsOutcome::MATCH,
			$proof->prove( $this->mapping( $challenge, VerificationState::VERIFIED ) )
		);
	}

	public function test_a_rotated_challenge_no_longer_proves(): void {
		$published = 'post-domain-verify=' . str_repeat( 'a', 32 );
		$proof     = new FreshProof( $this->resolver_expecting( $published ) );

		$this->assertSame(
			DnsOutcome::MISMATCH,
			$proof->prove( $this->mapping( str_repeat( 'b', 32 ), VerificationState::VERIFIED ) ),
			'a clone rotates challenges, so it cannot prove against the original record'
		);
	}

	public function test_stored_verification_state_does_not_influence_the_result(): void {
		$challenge = str_repeat( 'a', 32 );
		$proof     = new FreshProof( $this->resolver_expecting( 'post-domain-verify=' . $challenge ) );

		foreach ( VerificationState::cases() as $state ) {
			$this->assertSame(
				DnsOutcome::MATCH,
				$proof->prove( $this->mapping( $challenge, $state ) ),
				'the proof is live, not a reading of stored state'
			);
		}
	}

	public function test_it_never_reads_stored_verification_fields(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Verification/FreshProof.php' );

		foreach ( array( 'verification_state', 'verified_at', 'last_outcome' ) as $field ) {
			$this->assertStringNotContainsString( $field, $source );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter FreshProofTest`
Expected: FAIL — `Error: Class "PostDomain\Verification\FreshProof" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/FreshProof.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Contracts\DnsResolver;
use PostDomain\Mapping\Mapping;

/**
 * A live proof with no stored state. Cached verification is not sufficient
 * authorization for a provider mutation, and a rotated challenge must invalidate
 * any authority a copy of the database might otherwise claim.
 */
final class FreshProof {

	public function __construct( private readonly DnsResolver $resolver ) {}

	public function prove( Mapping $mapping ): DnsOutcome {
		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return DnsOutcome::TRANSIENT;
		}

		return $this->resolver->txt( $name, Challenge::expected_value( $mapping->challenge ) )->outcome;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter FreshProofTest`
Expected: PASS — 4 tests (the third asserts across all four states)

- [ ] **Step 5: Commit**

```bash
git add src/Verification/FreshProof.php tests/integration/Verification/FreshProofTest.php
git commit -m "Prove ownership live, with no stored state consulted

A test asserts the class never mentions the stored verification fields, because
the point of this class is that reading them would defeat it."
```

---

### Task 7: Queue, budget, and cron topology

**Files:**
- Create: `src/Verification/Schedule.php`
- Modify: `src/Plugin.php`
- Test: `tests/integration/Verification/ScheduleTest.php`

**Interfaces:**
- Consumes: `Verifier` (Task 5), `Clock`, `Scheduler` (Plan 02).
- Produces: `PostDomain\Verification\Schedule::due_pending( int $batch ): Mapping[]`, `::due_established( int $batch ): Mapping[]`, `::run_sweep( string $hook, int $budget_seconds, int $batch ): int`, `::register_cron(): void`.

Due-work queries select on persisted next-attempt columns and exclude **every**
row carrying a provider-mutation lease, expired or not (spec §13.5).

- [ ] **Step 1: Write the failing test**

Create `tests/integration/Verification/ScheduleTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Verification\Schedule;
use WP_UnitTestCase;

final class ScheduleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	private function seed( string $host, VerificationState $state, ?string $next, array $lease = array() ): int {
		global $wpdb;

		$id = ( new DbRepository() )->save(
			new Mapping(
				0, $host, null, self::factory()->post->create(), 1,
				VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
				null, substr( md5( $host ), 0, 32 ), '_post-domain-challenge'
			)
		)->id;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array_merge(
				array( 'verification_state' => $state->value, 'verify_next_attempt_at' => $next ),
				$lease
			),
			array( 'id' => $id )
		);

		return $id;
	}

	public function test_only_due_pending_rows_are_selected(): void {
		$due    = $this->seed( 'due.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$future = $this->seed( 'future.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$null   = $this->seed( 'null.test', VerificationState::PENDING, null );

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertContains( $due, $ids );
		$this->assertNotContains( $future, $ids );
		$this->assertNotContains( $null, $ids, 'a null next-attempt is not due' );
	}

	public function test_a_leased_row_is_skipped_even_when_due(): void {
		$leased = $this->seed(
			'leased.test',
			VerificationState::PENDING,
			gmdate( 'Y-m-d H:i:s', time() - 60 ),
			array(
				'ssl_mutation_token'      => str_repeat( '9', 32 ),
				'ssl_mutation_kind'       => 'create',
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			)
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertNotContains( $leased, $ids );
	}

	public function test_an_expired_lease_still_blocks_ordinary_work(): void {
		$expired = $this->seed(
			'expired.test',
			VerificationState::PENDING,
			gmdate( 'Y-m-d H:i:s', time() - 60 ),
			array(
				'ssl_mutation_token'      => str_repeat( '8', 32 ),
				'ssl_mutation_kind'       => 'remove',
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
			)
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertNotContains(
			$expired,
			$ids,
			'expiry transfers the row to LeaseRecovery, it does not free it'
		);
	}

	public function test_a_row_with_an_integrity_error_is_skipped(): void {
		global $wpdb;

		$corrupt = $this->seed( 'corrupt.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'integrity_error' => 'challenge_name_invalid' ),
			array( 'id' => $corrupt )
		);

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertNotContains( $corrupt, $ids );
	}

	public function test_rows_are_ordered_oldest_due_first(): void {
		$newer = $this->seed( 'newer.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$older = $this->seed( 'older.test', VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 600 ) );

		$ids = array_map( static fn( Mapping $m ): int => $m->id, Schedule::due_pending( 50 ) );

		$this->assertSame( $older, $ids[0] );
		$this->assertSame( $newer, $ids[1] );
	}

	public function test_the_batch_cap_is_honoured(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed( "batch-{$i}.test", VerificationState::PENDING, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		}

		$this->assertCount( 2, Schedule::due_pending( 2 ) );
	}

	public function test_the_four_cron_hooks_are_registered(): void {
		Schedule::register_cron();

		foreach ( array( 'pd_verify_pending', 'pd_verify_established', 'pd_ssl_sweep', 'pd_maintenance' ) as $hook ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ), "{$hook} must be scheduled" );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter ScheduleTest`
Expected: FAIL — `Error: Class "PostDomain\Verification\Schedule" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Verification/Schedule.php`:

```php
<?php
declare( strict_types = 1 );

namespace PostDomain\Verification;

use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;

final class Schedule {

	public const HOOKS = array(
		'pd_verify_pending'     => 900,
		'pd_verify_established' => 3600,
		'pd_ssl_sweep'          => 900,
		'pd_maintenance'        => 86400,
	);

	/** @return Mapping[] */
	public static function due_pending( int $batch ): array {
		return self::due( array( VerificationState::PENDING->value ), $batch );
	}

	/** @return Mapping[] */
	public static function due_established( int $batch ): array {
		return self::due( array( VerificationState::VERIFIED->value ), $batch );
	}

	/**
	 * @param string[] $states
	 * @return Mapping[]
	 */
	private static function due( array $states, int $batch ): array {
		global $wpdb;

		$table        = Schema::domains_table();
		$placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$now          = gmdate( 'Y-m-d H:i:s' );

		$sql = "SELECT * FROM {$table}
		         WHERE verification_state IN ({$placeholders})
		           AND integrity_error IS NULL
		           AND verify_next_attempt_at IS NOT NULL
		           AND verify_next_attempt_at <= %s
		           AND ssl_mutation_token IS NULL
		         ORDER BY verify_next_attempt_at ASC
		         LIMIT %d";

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( $sql, array_merge( $states, array( $now, $batch ) ) ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	public static function register_cron(): void {
		foreach ( array_keys( self::HOOKS ) as $hook ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_single_event( time() + 60, $hook );
			}
		}
	}

	/**
	 * @param callable(Mapping): void $work
	 * @return int Rows processed.
	 */
	public static function run_sweep( array $rows, int $budget_seconds, callable $work, string $continuation ): int {
		$started   = time();
		$processed = 0;

		foreach ( $rows as $row ) {
			$work( $row );
			++$processed;

			if ( ( time() - $started ) >= $budget_seconds ) {
				break;
			}
		}

		if ( $processed < count( $rows ) && false === wp_next_scheduled( $continuation ) ) {
			// One continuation, not a loop.
			wp_schedule_single_event( time() + 60, $continuation );
		}

		return $processed;
	}
}
```

Add to `src/Plugin.php`, inside `boot()`:

```php
		add_action( 'init', array( $plugin, 'register_cron' ), 100 );
		add_action( 'pd_verify_pending', array( $plugin, 'sweep_pending' ) );
		add_action( 'pd_verify_established', array( $plugin, 'sweep_established' ) );
```

and the methods:

```php
	public function register_cron(): void {
		\PostDomain\Verification\Schedule::register_cron();
	}

	public function sweep_pending(): void {
		$this->sweep( \PostDomain\Verification\Schedule::due_pending( 50 ), 'pd_verify_pending' );
	}

	public function sweep_established(): void {
		$this->sweep( \PostDomain\Verification\Schedule::due_established( 50 ), 'pd_verify_established' );
	}

	/** @param \PostDomain\Mapping\Mapping[] $rows */
	private function sweep( array $rows, string $hook ): void {
		$budget = (int) apply_filters( 'pd_sweep_budget_seconds', 20 );
		$budget = max( 1, min( 300, $budget ) );

		$verifier = new \PostDomain\Verification\Verifier(
			$this->repository,
			$this->dns_resolver(),
			new \PostDomain\Support\SystemClock()
		);

		\PostDomain\Verification\Schedule::run_sweep(
			$rows,
			$budget,
			static function ( \PostDomain\Mapping\Mapping $mapping ) use ( $verifier ): void {
				$verifier->verify( $mapping );
			},
			$hook
		);
	}

	public function dns_resolver(): \PostDomain\Contracts\DnsResolver {
		/** @var string[] $endpoints */
		$endpoints = (array) apply_filters(
			'pd_doh_endpoints',
			array( 'https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve' )
		);

		$default = new \PostDomain\Verification\DohResolver(
			new \PostDomain\Support\WpHttpClient(),
			$endpoints
		);

		/** @var \PostDomain\Contracts\DnsResolver $resolver */
		$resolver = apply_filters( 'pd_dns_resolver', $default );

		return $resolver instanceof \PostDomain\Contracts\DnsResolver ? $resolver : $default;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter ScheduleTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Run the full suite**

Run: `composer lint && composer analyse && composer test && composer test:integration`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Verification/Schedule.php src/Plugin.php tests/integration/Verification/ScheduleTest.php
git commit -m "Select due verification work without scanning, and skip leased rows

The lease condition is a no-lease test rather than an expiry test: an expired
lease belongs to recovery, not to ordinary work. A backlog drains through one
continuation rather than a loop."
```

---

## Gate for Plan 06

```bash
composer lint && composer analyse && composer test && composer test:integration
```

Plus: a seeded mapping moves `unverified → pending → verified` against a stubbed
resolver, a transient outcome leaves a verified mapping verified with its hard
counter untouched, and `ScheduleTest` proves an expired lease still blocks
ordinary work.
