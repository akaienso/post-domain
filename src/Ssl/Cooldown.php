<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class Cooldown {

	public static function active_for( string $driver_id ): bool {
		$cooldowns = get_option( 'pd_provider_cooldowns', array() );

		if ( ! is_array( $cooldowns ) || ! isset( $cooldowns[ $driver_id ]['until'] ) ) {
			return false;
		}

		return strtotime( (string) $cooldowns[ $driver_id ]['until'] ) > time();
	}

	public static function set( string $driver_id, int $seconds, string $reason ): void {
		$cooldowns = get_option( 'pd_provider_cooldowns', array() );
		$cooldowns = is_array( $cooldowns ) ? $cooldowns : array();

		$cooldowns[ $driver_id ] = array(
			'until'  => gmdate( 'c', time() + max( 1, $seconds ) ),
			'reason' => $reason,
			'source' => 'retry_after',
		);

		update_option( 'pd_provider_cooldowns', $cooldowns, false );
	}
}
