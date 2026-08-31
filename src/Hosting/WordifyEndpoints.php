<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The Wordify HTTP surface this plugin talks to.
 *
 * Provenance, stated exactly: these routes were established from Wordify's
 * public API documentation, its API-token UI, the Wordify MCP tool schemas, and
 * unauthenticated route probes in which every protected route below answered
 * HTTP 401 with Wordify's JSON error envelope rather than 404 — a 404 would
 * have meant the path did not exist. Response field names come from read-only
 * MCP responses against a real account. The authenticated OpenAPI document was
 * never obtained, and nothing here is inferred from one.
 *
 * All six routes ship filled in. Production needs no filter and no custom PHP.
 * `pd_wordify_endpoints` remains only so a test can point the client at a fake
 * base URL, and so an operator on a future API version can adjust a path; it is
 * validated, and it cannot remove a route or change the authentication scheme.
 *
 * @package PostDomain
 */
final class WordifyEndpoints {

	public const OP_ME            = 'me';
	public const OP_SITES         = 'sites';
	public const OP_SITE          = 'site';
	public const OP_DOMAINS       = 'domains';
	public const OP_ATTACH_DOMAIN = 'attach_domain';
	public const OP_RECHECK       = 'recheck';

	public const BASE = 'https://console.wordify.com/api/v1';

	/** The header carrying the bound team for a multi-team account. */
	public const TEAM_HEADER = 'X-Wordify-Team';

	/** Tokens Wordify issues are prefixed. A value without it is not one. */
	public const TOKEN_PREFIX = 'wpk_';

	/** @var array<string, string> */
	private const ROUTES = array(
		self::OP_ME            => '/me',
		self::OP_SITES         => '/sites',
		self::OP_SITE          => '/sites/{site_id}',
		self::OP_DOMAINS       => '/sites/{site_id}/domains',
		self::OP_ATTACH_DOMAIN => '/sites/{site_id}/domains',
		self::OP_RECHECK       => '/sites/{site_id}/domains/recheck',
	);

	/**
	 * @param array<string, string> $paths Operation id => path template.
	 */
	private function __construct(
		private readonly string $base,
		private readonly array $paths
	) {}

	/** Every verified route, against the real base URL. */
	public static function verified(): self {
		return new self( self::BASE, self::ROUTES );
	}

	/**
	 * @param array<string, string> $paths
	 */
	public static function supplied( string $base, array $paths ): self {
		return new self( rtrim( $base, '/' ), array_merge( self::ROUTES, $paths ) );
	}

	/**
	 * The shipped map, with a narrow filter seam over it.
	 *
	 * The seam exists for tests that need a fake base URL, and for a future API
	 * version that moves a path. It can override a path and the base; it cannot
	 * delete a route, and it has no say over authentication at all — the scheme
	 * is not configuration and a filter must never be able to redirect a
	 * credential somewhere unintended.
	 */
	public static function configured(): self {
		$verified = self::verified();

		/**
		 * Filters the Wordify base URL and route templates.
		 *
		 * Expects `array{ base: string, paths: array<string, string> }`. Anything
		 * else is discarded, and missing routes are filled from the shipped map.
		 *
		 * @param mixed $config
		 */
		$config = apply_filters(
			'pd_wordify_endpoints',
			array(
				'base'  => $verified->base,
				'paths' => $verified->paths,
			)
		);

		if ( ! is_array( $config ) ) {
			return $verified;
		}

		/** @var array<string, string> $clean */
		$clean = array();
		$paths = isset( $config['paths'] ) && is_array( $config['paths'] ) ? $config['paths'] : array();

		foreach ( $paths as $operation => $path ) {
			if ( is_string( $operation ) && is_string( $path ) && '' !== $path ) {
				$clean[ $operation ] = $path;
			}
		}

		$base = isset( $config['base'] ) && is_string( $config['base'] ) && '' !== $config['base']
			? rtrim( $config['base'], '/' )
			: $verified->base;

		// Every shipped route survives whatever the filter did or did not say.
		return new self( $base, array_merge( self::ROUTES, $clean ) );
	}

	public function base(): string {
		return $this->base;
	}

	public function knows( string $operation ): bool {
		return isset( $this->paths[ $operation ] );
	}

	/**
	 * The absolute URL for an operation.
	 *
	 * @param array<string, string> $tokens Template tokens, e.g. `site_id`.
	 */
	public function url( string $operation, array $tokens = array() ): ?string {
		if ( ! isset( $this->paths[ $operation ] ) ) {
			return null;
		}

		$path = $this->paths[ $operation ];

		foreach ( $tokens as $name => $value ) {
			$path = str_replace( '{' . $name . '}', rawurlencode( $value ), $path );
		}

		return $this->base . $path;
	}
}
