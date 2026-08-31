<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Contracts\HttpClient;
use PostDomain\Support\HttpResponse;

/**
 * A Wordify that answers from memory.
 *
 * The *only* substitution these tests make. Everything above it — the hook
 * registration, the credential store, the connection service, the admin action,
 * the application command, the CAS — is the production code, so a test that
 * passes here says something about the plugin rather than about its doubles.
 */
final class FakeWordifyTransport implements HttpClient {

	public const TEAM = '01HQTEAM0000000000000000AB';

	/** @var array<int, array{method: string, url: string, opts: array<string, mixed>}> */
	public array $calls = array();

	/** @var array<string, WordifyFakeSite> */
	private array $sites = array();

	/** @var array<int, array{status: int, body: string}> */
	private array $attach_answers = array();

	/** @var array<int, string> */
	public array $attached = array();

	public int $sites_per_page = 25;

	/** When set, every read answers this status instead. */
	public ?int $reads_fail_with = null;

	/** Hostnames the account holds on some *other* site. */
	public array $owned_elsewhere = array();

	public function with_sites( int $count, string $prefix = 'site' ): self {
		for ( $i = 1; $i <= $count; $i++ ) {
			$id                 = sprintf( '01HQ%022d', $i );
			$this->sites[ $id ] = new WordifyFakeSite( $id, $prefix . '-' . $i, $prefix . '-' . $i . '.example' );
		}

		return $this;
	}

	public function site_id( int $n ): string {
		return sprintf( '01HQ%022d', $n );
	}

	/** Scripts the next attach responses, in order. */
	public function attach_answers( array ...$answers ): self {
		foreach ( $answers as $answer ) {
			$this->attach_answers[] = array(
				'status' => (int) $answer['status'],
				'body'   => (string) $answer['body'],
			);
		}

		return $this;
	}

	/** Every attach this transport was asked to perform. */
	public function attach_calls(): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn ( array $call ): bool => 'POST' === $call['method']
					&& str_ends_with( $call['url'], '/domains' )
			)
		);
	}

	public function request( string $method, string $url, array $opts = array() ): HttpResponse {
		$this->calls[] = array(
			'method' => $method,
			'url'    => $url,
			'opts'   => $opts,
		);

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		if ( null !== $this->reads_fail_with && 'GET' === $method ) {
			return new HttpResponse( $this->reads_fail_with, array(), '' );
		}

		if ( str_ends_with( $path, '/me' ) ) {
			return $this->me();
		}

		if ( 'POST' === $method && str_ends_with( $path, '/domains' ) ) {
			return $this->attach( $opts );
		}

		if ( 'GET' === $method && str_ends_with( $path, '/domains' ) ) {
			return new HttpResponse(
				200,
				array(),
				(string) wp_json_encode(
					array_map(
						static fn ( string $host ): array => array(
							'id'         => 'dom-' . md5( $host ),
							'domain'     => $host,
							'is_primary' => false,
							'dns_status' => 'pending',
							'ssl_status' => 'pending',
						),
						$this->attached
					)
				)
			);
		}

		if ( 'GET' === $method && preg_match( '#/sites/([^/]+)$#', $path, $m ) === 1 ) {
			$site = $this->sites[ rawurldecode( $m[1] ) ] ?? null;

			return null === $site
				? new HttpResponse( 404, array(), '{"error":{"code":"not_found"}}' )
				: new HttpResponse( 200, array(), (string) wp_json_encode( $site->record() ) );
		}

		if ( 'GET' === $method && str_ends_with( $path, '/sites' ) ) {
			return $this->list_sites( $query );
		}

		return new HttpResponse( 404, array(), '{}' );
	}

	private function me(): HttpResponse {

		return new HttpResponse(
			200,
			array(),
			(string) wp_json_encode(
				array(
					'id'              => 'usr-1',
					'name'            => 'Operator',
					'current_team_id' => self::TEAM,
					'teams'           => array(
						array(
							'id'   => self::TEAM,
							'name' => 'Test Team',
						),
					),
				)
			)
		);
	}

	/** @param array<string, mixed> $query */
	private function list_sites( array $query ): HttpResponse {
		$sites = array_values( $this->sites );

		if ( isset( $query['domain'] ) && '' !== (string) $query['domain'] ) {
			$needle = (string) $query['domain'];

			if ( isset( $this->owned_elsewhere[ $needle ] ) ) {
				// The ownership lookup: this hostname lives on another site.
				$owner = $this->sites[ $this->owned_elsewhere[ $needle ] ] ?? null;
				$sites = null === $owner ? array() : array( $owner );
			} else {
				$sites = array_values(
					array_filter(
						$sites,
						static fn ( WordifyFakeSite $s ): bool => str_contains( $s->domain, $needle )
					)
				);
			}
		}

		$per_page = isset( $query['per_page'] ) ? max( 1, (int) $query['per_page'] ) : $this->sites_per_page;
		$page     = isset( $query['page'] ) ? max( 1, (int) $query['page'] ) : 1;
		$total    = count( $sites );
		$slice    = array_slice( $sites, ( $page - 1 ) * $per_page, $per_page );

		return new HttpResponse(
			200,
			array(),
			(string) wp_json_encode(
				array(
					'data' => array_map( static fn ( WordifyFakeSite $s ): array => $s->record(), $slice ),
					'meta' => array(
						'current_page' => $page,
						'per_page'     => $per_page,
						'total'        => $total,
						'last_page'    => (int) max( 1, (int) ceil( $total / $per_page ) ),
					),
				)
			)
		);
	}

	/** @param array<string, mixed> $opts */
	private function attach( array $opts ): HttpResponse {
		/** @var array<string, mixed> $body */
		$body = json_decode( (string) ( $opts['body'] ?? '{}' ), true );
		$host = (string) ( $body['domain'] ?? '' );

		if ( array() !== $this->attach_answers ) {
			$answer = array_shift( $this->attach_answers );

			if ( $answer['status'] < 400 ) {
				$this->attached[] = $host;
			}

			return new HttpResponse( $answer['status'], array( 'X-Request-Id' => 'req_test' ), $answer['body'] );
		}

		$this->attached[] = $host;

		return new HttpResponse(
			201,
			array(),
			(string) wp_json_encode(
				array(
					'id'         => 'dom-' . md5( $host ),
					'domain'     => $host,
					'is_primary' => false,
					'dns_status' => 'pending',
					'ssl_status' => 'pending',
				)
			)
		);
	}
}
