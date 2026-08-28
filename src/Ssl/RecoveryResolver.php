<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\Mapping;

/**
 * Reads provider state for a fenced mutation and decides what it means. It never
 * issues another provider mutation, and it never chooses its own driver.
 */
interface RecoveryResolver {

	/**
	 * @param SslDriver $driver The driver the mutation was DURABLY BOUND to, already
	 *                          verified to be pointing at the same provider environment.
	 *                          Never re-resolved from current configuration.
	 */
	public function resolve(
		Mapping $mapping,
		MutationKind $kind,
		string $recovery_token,
		SslDriver $driver
	): RecoveryOutcome;
}
