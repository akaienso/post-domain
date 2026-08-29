<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

final class DatabaseCapability {

	private const MIN_MYSQL   = '8.0.0';
	private const MIN_MARIADB = '10.2.2';

	public static function server_description(): string {
		global $wpdb;

		return (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB
	}

	public static function supports_recursive_cte(): bool {
		$version = self::server_description();

		if ( '' === $version ) {
			return false;
		}

		if ( false !== stripos( $version, 'mariadb' ) ) {
			$numeric = self::numeric_part( str_ireplace( 'mariadb', '', $version ) );

			return '' !== $numeric && version_compare( $numeric, self::MIN_MARIADB, '>=' );
		}

		$numeric = self::numeric_part( $version );

		return '' !== $numeric && version_compare( $numeric, self::MIN_MYSQL, '>=' );
	}

	private static function numeric_part( string $version ): string {
		return 1 === preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $version, $matches ) ? $matches[1] : '';
	}
}
