<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The single seam holding every unverified detail of the Wordify HTTP surface.
 *
 * The authenticated OpenAPI document at https://console.wordify.com/docs/api.json
 * could not be obtained: it answers 200 with the console SPA shell rather than
 * JSON. So the operation *semantics* are verified — from the MCP tool schemas
 * recorded in `references/wordify-api-contract.json` — while almost none of the
 * transport is. This class is where that line is drawn, deliberately and in one
 * place, instead of being smeared through the client as plausible-looking
 * constants.
 *
 * Verified and shipped filled in:
 *   - `GET /api/v1/sites`, including its `domain` filter and its pagination.
 *
 * NOT verified, and therefore shipped empty:
 *   - every other HTTP path (site, domains, attach, recheck);
 *   - the authentication header name and token format;
 *   - the response envelope beyond the field names the tool schemas state
 *     (`ssl_state`, `dns_verified_at`, `is_primary`, `provisioning_status`, and
 *     26-character ULID site ids);
 *   - error status codes and error body shape;
 *   - any idempotency key mechanism — there is no evidence one exists, so the
 *     provider never relies on one;
 *   - a domain detachment operation, which no verified surface exposes at all.
 *
 * The base URL is the console host the documentation URL itself lives on; it is
 * observed, not read from a specification, and it is filterable for that reason.
 *
 * `WordifyApiClient` fails closed against this map: an operation with no path,
 * or any request at all while the auth header name is unknown, returns a
 * `WordifyFailure` naming the missing piece. It never guesses a path, and it
 * never sends a credential under a header name nobody verified.
 *
 * An operator who holds the specification supplies the rest through the
 * `pd_wordify_endpoints` filter:
 *
 *     add_filter( 'pd_wordify_endpoints', function ( array $config ): array {
 *         $config['auth_header']        = 'Authorization';
 *         $config['paths']['domains']   = '/api/v1/sites/{site_id}/domains';
 *         return $config;
 *     } );
 *
 * Filling one in is a statement that it was read from the specification. Until
 * then the plugin's manual workflow is what runs, which is the safe default.
 *
 * @package PostDomain
 */
final class WordifyEndpoints {

	public const OP_ME    = 'me';
	public const OP_SITES = 'sites';
	public const OP_SITE  = 'site';

	public const OP_DOMAINS       = 'domains';
	public const OP_ATTACH_DOMAIN = 'attach_domain';
	public const OP_RECHECK       = 'recheck';

	/** The only path this project could verify. */
	public const VERIFIED_SITES_PATH = '/api/v1/sites';

	/** Observed from the documentation URL, not read from a specification. */
	public const OBSERVED_BASE = 'https://console.wordify.com';

	/**
	 * @param array<string, string> $paths Operation id => path template, `{site_id}` substituted.
	 */
	private function __construct(
		private readonly string $base,
		private readonly array $paths,
		private readonly ?string $auth_header
	) {}

	/**
	 * The shipped map: the one verified path, nothing else, no auth header.
	 */
	public static function verified(): self {
		return new self( self::OBSERVED_BASE, array( self::OP_SITES => self::VERIFIED_SITES_PATH ), null );
	}

	/**
	 * @param array<string, string> $paths
	 */
	public static function supplied( string $base, array $paths, ?string $auth_header ): self {
		return new self( $base, $paths, $auth_header );
	}

	/**
	 * The verified map plus whatever an operator who holds the specification has
	 * supplied. WordPress-dependent, so it is called by the factory rather than
	 * by the client.
	 */
	public static function configured(): self {
		$verified = self::verified();

		/**
		 * Filters the Wordify HTTP transport details the plugin could not verify.
		 *
		 * Expects, and is validated back down to, an array of the shape
		 * `array{ base: string, paths: array<string, string>, auth_header: string|null }`.
		 * Anything else is discarded: a malformed filter must not be able to make
		 * this client send a credential somewhere unintended.
		 *
		 * @param mixed $config
		 */
		$config = apply_filters(
			'pd_wordify_endpoints',
			array(
				'base'        => $verified->base,
				'paths'       => $verified->paths,
				'auth_header' => $verified->auth_header,
			)
		);

		if ( ! is_array( $config ) ) {
			return $verified;
		}

		$paths = isset( $config['paths'] ) && is_array( $config['paths'] ) ? $config['paths'] : array();

		/** @var array<string, string> $clean_paths */
		$clean_paths = array();

		foreach ( $paths as $operation => $path ) {
			if ( is_string( $operation ) && is_string( $path ) && '' !== $path ) {
				$clean_paths[ $operation ] = $path;
			}
		}

		$base        = isset( $config['base'] ) && is_string( $config['base'] ) && '' !== $config['base']
			? $config['base']
			: $verified->base;
		$auth_header = isset( $config['auth_header'] ) && is_string( $config['auth_header'] ) && '' !== $config['auth_header']
			? $config['auth_header']
			: null;

		// The verified path is not something a filter may unset: it is the one
		// thing here that is known to be true.
		$clean_paths[ self::OP_SITES ] = self::VERIFIED_SITES_PATH;

		return new self( rtrim( $base, '/' ), $clean_paths, $auth_header );
	}

	public function base(): string {
		return $this->base;
	}

	/** The header name to send the token under, or null while it is unverified. */
	public function auth_header(): ?string {
		return $this->auth_header;
	}

	public function knows( string $operation ): bool {
		return isset( $this->paths[ $operation ] );
	}

	/**
	 * The absolute URL for an operation, or null when its path is unverified.
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
