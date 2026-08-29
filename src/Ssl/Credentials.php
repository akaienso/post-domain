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

	/** Every value the driver's constructor needs, present and non-empty. */
	public static function cloudflare_is_configured(): bool {
		return '' !== self::api_token()
			&& '' !== self::zone_id()
			&& '' !== self::cname_target();
	}
}
