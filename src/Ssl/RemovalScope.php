<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Which removal a row is waiting on, persisted so cron can tell without guessing.
 *
 * Two different operations leave a row due for provider work: `DELETE
 * /domains/{id}` wants the mapping gone once the provider confirms, and `DELETE
 * /domains/{id}/ssl` wants only the certificate gone and the mapping kept.
 * Resuming the wrong one would either hard-delete a domain whose operator only
 * asked to remove a certificate, or leave a requested deletion unfinished.
 *
 * It is a persisted column rather than an inference. `ssl_state` cannot carry it
 * — both removals legitimately pass through the same states — and the event log
 * is a record of what happened, never an input to what happens next (§12.3).
 */
enum RemovalScope: string {

	/** The whole mapping goes once the provider confirms (§14.15). */
	case MAPPING = 'mapping';

	/** Only the provider resource goes; the mapping stays. */
	case RESOURCE = 'resource';

	/**
	 * A persisted value has three meanings, not two: absent, one of these cases,
	 * or something this build does not recognise. `tryFrom()` collapses the third
	 * into the first, which is exactly the collapse that would let a corrupted
	 * scope be treated as an ordinary row. Callers that must tell them apart use
	 * `is_invalid()` on the raw column.
	 */
	public static function from_row( ?string $value ): ?self {
		return null === $value ? null : self::tryFrom( $value );
	}

	/** True when the column holds something, and that something is not a case. */
	public static function is_invalid( ?string $value ): bool {
		return null !== $value && null === self::tryFrom( $value );
	}
}
