<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\Schema;
use PostDomain\Verification\Challenge;

final class Environment {

	public static function installation_id(): string {
		$id = get_option( 'pd_installation_id', '' );

		if ( ! is_string( $id ) || '' === $id ) {
			$id = wp_generate_uuid4();
			update_option( 'pd_installation_id', $id, false );
		}

		return $id;
	}

	public static function primary_host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function remember_primary_host(): void {
		update_option( 'pd_installation_primary_host', self::primary_host(), false );
	}

	/** @return array{stored: string, current: string}|null */
	public static function check(): ?array {
		$stored = get_option( 'pd_installation_primary_host', '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			self::remember_primary_host();

			return null;
		}

		$current = self::primary_host();

		if ( $stored === $current ) {
			return null;
		}

		$mismatch = array(
			'stored'  => $stored,
			'current' => $current,
		);
		update_option( 'pd_environment_mismatch', $mismatch, false );

		return $mismatch;
	}

	public static function is_blocked(): bool {
		return is_array( get_option( 'pd_environment_mismatch', null ) );
	}

	public static function resolve_as_restore(): void {
		self::remember_primary_host();
		delete_option( 'pd_environment_mismatch' );
	}

	public static function resolve_as_clone(): void {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var string[] $ids */
		$ids = $wpdb->get_col( "SELECT id FROM {$table}" ); // phpcs:ignore WordPress.DB

		foreach ( $ids as $id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				// The durable binding is cleared whole, ssl_provider included: a
				// clone owns nothing anywhere, and a row keeping a provider
				// without the rest is exactly the partial state the repository
				// invariant forbids (spec §12.2, §14.9).
				array(
					'ssl_provider'              => null,
					'ssl_provider_environment'  => null,
					'ssl_ref'                   => null,
					'ssl_ownership_origin'      => null,
					'ssl_owner_installation_id' => null,
					'ssl_adopted_at'            => null,
					'ssl_adopted_by'            => null,
					'ssl_provider_state'        => null,
					'ssl_state'                 => SslState::NONE->value,
					'ssl_mutation_token'        => null,
					'ssl_mutation_kind'         => null,
					'ssl_mutation_phase'        => null,
					'ssl_mutation_expires_at'   => null,
					'challenge'                 => Challenge::token(),
					'challenge_rotated_at'      => gmdate( 'Y-m-d H:i:s' ),
					'verification_state'        => VerificationState::UNVERIFIED->value,
					'verified_at'               => null,
					'hard_failure_count'        => 0,
					'transient_failure_count'   => 0,
					'revision'                  => 1,
					'updated_at'                => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $id )
			);
		}

		delete_option( 'pd_installation_id' );
		self::installation_id();
		self::remember_primary_host();
		delete_option( 'pd_environment_mismatch' );
	}
}
