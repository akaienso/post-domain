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
			return array(
				'state'   => SslState::PENDING_VALIDATION,
				'unknown' => true,
			);
		}

		if ( 'revoked' === $hostname || 'revoked' === $ssl ) {
			return array(
				'state'   => SslState::REVOKED,
				'unknown' => false,
			);
		}

		if ( 'failed' === $hostname || 'failed' === $ssl ) {
			return array(
				'state'   => SslState::FAILED,
				'unknown' => false,
			);
		}

		if ( 'active' === $hostname && 'active' === $ssl ) {
			return array(
				'state'   => SslState::ACTIVE,
				'unknown' => false,
			);
		}

		return array(
			'state'   => SslState::PENDING_VALIDATION,
			'unknown' => false,
		);
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
