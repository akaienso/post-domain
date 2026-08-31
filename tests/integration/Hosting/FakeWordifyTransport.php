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

	public const TEAM  = '01HQTEAM0000000000000000AB';
	public const TEAM2 = '01HQTEAM0000000000000000CD';

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

	/** Teams `GET /me` reports. One, with a current team, unless changed. */
	public array $teams = array(
		array(
			'id'   => self::TEAM,
			'name' => 'Test Team',
		),
	);

	/** When false, `GET /me` names no current team, so none is implied. */
	public bool $has_current_team = true;

	/** Site id => team id. A site with no entry belongs to the default team. */
	public array $site_team = array();

	/** Every X-Wordify-Team header this transport was sent, in order. */
	public array $team_headers = array();

	/** Offers two teams and no current team, so nothing implies one. */
	public function with_two_teams(): self {
		$this->teams = array(
			array(
				'id'   => self::TEAM,
				'name' => 'First Team',
			),
			array(
				'id'   => self::TEAM2,
				'name' => 'Second Team',
			),
		);

		$this->has_current_team = false;

		return $this;
	}

	/** Puts a range of sites in a team, so a listing can be scoped to it. */
	public function assign_sites( string $team, int $from, int $to ): self {
		for ( $i = $from; $i <= $to; $i++ ) {
			$this->site_team[ $this->site_id( $i ) ] = $team;
		}

		return $this;
	}

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

		/** @var array<string, string> $headers */
		$headers              = $opts['headers'] ?? array();
		$this->team_headers[] = (string) ( $headers['X-Wordify-Team'] ?? '' );

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
			$id   = rawurldecode( $m[1] );
			$site = $this->sites[ $id ] ?? null;

			// A site is only readable by the team that holds it, which is what
			// makes a confirming read mean something.
			if ( null !== $site && ! $this->in_team( $id, (string) ( $headers['X-Wordify-Team'] ?? '' ) ) ) {
				$site = null;
			}

			return null === $site
				? new HttpResponse( 404, array(), '{"error":{"code":"not_found"}}' )
				: new HttpResponse( 200, array(), (string) wp_json_encode( $site->record() ) );
		}

		if ( 'GET' === $method && str_ends_with( $path, '/sites' ) ) {
			return $this->list_sites( $query, (string) ( $headers['X-Wordify-Team'] ?? '' ) );
		}

		return new HttpResponse( 404, array(), '{}' );
	}

	private function me(): HttpResponse {
		$record = array(
			'id'    => 'usr-1',
			'name'  => 'Operator',
			'teams' => $this->teams,
		);

		if ( $this->has_current_team ) {
			$record['current_team_id'] = (string) $this->teams[0]['id'];
		}

		return new HttpResponse( 200, array(), (string) wp_json_encode( $record ) );
	}

	/** True when a site belongs to the team making the request. */
	private function in_team( string $site_id, string $team ): bool {
		if ( array() === $this->site_team ) {
			return true;
		}

		return ( $this->site_team[ $site_id ] ?? (string) $this->teams[0]['id'] ) === $team;
	}

	/** @param array<string, mixed> $query */
	private function list_sites( array $query, string $team = '' ): HttpResponse {
		$sites = array_values(
			array_filter(
				$this->sites,
				fn ( WordifyFakeSite $s ): bool => '' === $team || $this->in_team( $s->id, $team )
			)
		);

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
