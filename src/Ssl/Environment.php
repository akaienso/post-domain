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
					'hosting_provider'          => null,
					'hosting_environment'       => null,
					'hosting_ref'               => null,
					'hosting_state'             => null,
					'hosting_registered_at'     => null,
					'ssl_provider'              => null,
					'ssl_provider_environment'  => null,
					'ssl_ref'                   => null,
					'ssl_ownership_origin'      => null,
					'ssl_owner_installation_id' => null,
					'ssl_adopted_at'            => null,
					'ssl_adopted_by'            => null,
					'ssl_provider_state'        => null,
					'ssl_state'                 => SslState::NONE->value,
					// All six lease columns, never four. The repository enforces
					// that they move together (spec §12.6, DbRepository::assert_valid),
					// so clearing the token while leaving the driver and the
					// environment behind produces a row the repository itself
					// rejects — and a row nothing can then save.
					'ssl_mutation_token'        => null,
					'ssl_mutation_kind'         => null,
					'ssl_mutation_phase'        => null,
					'ssl_mutation_expires_at'   => null,
					'ssl_mutation_driver'       => null,
					'ssl_mutation_environment'  => null,
					// An outstanding removal is intent recorded by the *source*
					// installation against the *source* installation's provider
					// resource. A clone owns nothing anywhere (§14.4, §14.8), so
					// carrying the scope and its schedule across would leave the
					// copy due for a removal it was never asked to perform — and
					// for scope `mapping` (§14.15) that removal deletes the row.
					'ssl_removal_scope'         => null,
					'deletion_requested_at'     => null,
					'deletion_attempts'         => 0,
					'deletion_next_attempt_at'  => null,
					// Retry and observation state describes a provider environment
					// this installation is no longer bound to. Keeping it would
					// make a clone act on a backoff, a transient count, an error,
					// and a marker-support verdict that were all measured
					// somewhere else (§12.6 "evidence about nothing").
					'ssl_next_attempt_at'       => null,
					'ssl_transient_count'       => 0,
					'ssl_error'                 => null,
					'ssl_checked_at'            => null,
					'ssl_marker_support'        => null,
					'ssl_method_requested_at'   => null,
					// `ssl_method` is deliberately NOT cleared. It is the DCV
					// method the operator chose for this mapping — configuration,
					// per §14.12 ("configuration source", "per-mapping change is
					// explicit"), merely persisted once a provider confirmed it.
					// §14.8's clone row enumerates what a clone clears and does
					// not name it, and a clone re-requesting a certificate should
					// re-request under the method its operator picked. The same
					// reasoning keeps `host`, `post_id`, `alias_of`, `title`,
					// `challenge_label`, and `activation_state`: configuration a
					// copy of a site legitimately still means.
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

		// A clone has tested nothing. Every stored confirmation describes work
		// done by the installation this was copied from.
		\PostDomain\Admin\OriginConfirmation::forget_all();
		\PostDomain\Admin\OriginChallenge::forget_all();

		// A clone inherits the hosting binding too, and those domains belong to
		// the installation this was copied from. The site choice is kept — it is
		// ordinary configuration and an operator may well want it again — but the
		// validation is not, so nothing can be attached, detached or reconciled
		// until someone reconnects deliberately from one installation.
		\PostDomain\Hosting\HostingBinding::invalidate();
		do_action( 'pd_hosting_authority_revoked' );

		delete_option( 'pd_installation_id' );
		self::installation_id();
		self::remember_primary_host();
		delete_option( 'pd_environment_mismatch' );
	}
}
