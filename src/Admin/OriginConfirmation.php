<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\Mapping;

/**
 * A record that a mapping was tested, bound to the state that was tested.
 *
 * Storing a bare timestamp made the result outlive its subject. Stop serving and
 * start again, remove and reissue the certificate, change the target, rotate the
 * challenge, restore from a backup — the domain still claimed to have been
 * tested, on evidence about a configuration that no longer existed. Deleting the
 * mapping left the record behind entirely, ready to be inherited by whatever
 * next took that id.
 *
 * So the confirmation carries a fingerprint of the state it was made about, and
 * is refused the moment that state moves. Invalidation is not a list of places
 * that must remember to call something: any change that bumps the revision or
 * moves the mapping invalidates it by construction, and the explicit clears
 * exist to stop orphans accumulating.
 */
final class OriginConfirmation {

	private const PREFIX = 'pd_origin_confirmed_';

	/** What the mapping points at, in a form that changes when the target does. */
	public static function target_identity( Mapping $mapping ): string {
		return null === $mapping->alias_of
			? 'post:' . (string) $mapping->post_id
			: 'alias:' . (string) $mapping->alias_of;
	}

	/**
	 * The state a test result is about.
	 *
	 * Everything here changes what a successful test would have meant. The
	 * revision alone would very nearly do, but naming the fields keeps the record
	 * legible and survives a future change that forgets to bump one.
	 */
	public static function fingerprint( Mapping $mapping ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'id'         => $mapping->id,
					'revision'   => $mapping->revision,
					'host'       => $mapping->host,
					'target'     => self::target_identity( $mapping ),
					'activation' => $mapping->activation_state->value,
					'ssl_state'  => $mapping->ssl_state->value,
				)
			)
		);
	}

	public static function record( Mapping $mapping ): void {
		update_option(
			self::key( $mapping->id ),
			array(
				'mapping_id'   => $mapping->id,
				'revision'     => $mapping->revision,
				'host'         => $mapping->host,
				'target'       => self::target_identity( $mapping ),
				'activation'   => $mapping->activation_state->value,
				'ssl_state'    => $mapping->ssl_state->value,
				'fingerprint'  => self::fingerprint( $mapping ),
				'confirmed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
	}

	/**
	 * When this exact mapping state was confirmed, or null.
	 *
	 * A record that no longer matches is not merely ignored, it is removed:
	 * leaving it would let a later change wander back into matching it.
	 */
	public static function confirmed_at( Mapping $mapping ): ?string {
		$stored = get_option( self::key( $mapping->id ) );

		if ( ! is_array( $stored ) ) {
			// A bare string is the shape this used to store. It carries no
			// evidence about state, so it cannot be honoured.
			if ( false !== $stored ) {
				self::forget( $mapping->id );
			}

			return null;
		}

		foreach ( array( 'mapping_id', 'revision', 'host', 'fingerprint', 'confirmed_at' ) as $key ) {
			if ( ! isset( $stored[ $key ] ) ) {
				self::forget( $mapping->id );

				return null;
			}
		}

		if ( ! hash_equals( self::fingerprint( $mapping ), (string) $stored['fingerprint'] ) ) {
			self::forget( $mapping->id );

			return null;
		}

		return (string) $stored['confirmed_at'];
	}

	public static function forget( int $mapping_id ): void {
		delete_option( self::key( $mapping_id ) );
	}

	/** Every stored confirmation, for a clone reset or an uninstall. */
	public static function forget_all(): void {
		global $wpdb;

		$like = $wpdb->esc_like( self::PREFIX ) . '%';

		/** @var string[] $names */
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	private static function key( int $mapping_id ): string {
		return self::PREFIX . $mapping_id;
	}
}
