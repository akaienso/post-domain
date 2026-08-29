<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\EventLog;
use PostDomain\Mapping\Mapping;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\SystemClock;

/**
 * Removes the local row only. Issues no provider deletion, and cannot start from
 * a row carrying any lease — including an expired one, which belongs to recovery.
 */
final class ForceLocalDelete {

	public static function run( Mapping $mapping ): bool {
		$clock = new SystemClock();
		$lease = new MutationLease( $clock );

		// No provider deletion is issued here, so the binding names the null
		// environment: nothing external is being spoken to.
		$held = $lease->acquire( $mapping->id, $mapping->revision, MutationKind::REMOVE, new NullDriver() );

		if ( null === $held ) {
			return false;
		}

		// The host snapshot is captured before the row can vanish, and the event
		// is written inside the same transaction as the delete.
		$host  = $mapping->host;
		$id    = $mapping->id;
		$actor = 'admin:' . get_current_user_id();

		$deleted = AtomicTransition::commit(
			static fn (): bool => $lease->delete_row( $held ),
			static fn (): bool => EventLog::record(
				$id,
				$host,
				'ssl',
				null,
				'force_deleted',
				$actor,
				array( 'note' => 'provider_resource_may_remain' )
			)
		);

		// committed() alone is enough here for the same reason as the local delete:
		// every non-committed outcome means this caller cannot claim the row is
		// gone, and the response to all of them is identical.
		if ( ! $deleted->committed() ) {
			$lease->release_reserved( $held );

			return false;
		}

		return true;
	}
}
