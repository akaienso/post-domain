<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Contracts\HostingProvider;

/**
 * Turns an ambiguous registration into a settled one, by reading.
 *
 * An ambiguous outcome means a write may or may not have landed. The only safe
 * way to find out is to ask what exists — so this class reads, exactly once per
 * invocation, and never writes, never retries the attach, and never calls
 * `recheck()`, which is rate limited and can trigger certificate issuance.
 *
 * It is bounded rather than looping: a caller passes how many attempts have
 * already been spent, and past `MAX_ATTEMPTS` the answer becomes a plain
 * refusal an operator can act on. A hostname that turns out to live on another
 * site is `foreign()` here too, and is still never adopted.
 *
 * @package PostDomain
 */
final class HostingReconciler {

	/** Reads spent on one ambiguous registration before it becomes a refusal. */
	public const MAX_ATTEMPTS = 3;

	public function __construct( private readonly HostingProvider $provider ) {}

	/**
	 * @param int $attempts_spent Reads already made for this registration.
	 */
	public function resolve( HostingResourceContext $context, int $attempts_spent = 0 ): RegistrationOutcome {
		if ( $attempts_spent >= self::MAX_ATTEMPTS ) {
			return RegistrationOutcome::refused(
				'The hosting provider never settled this registration; it needs to be checked by hand.'
			);
		}

		$environment = $this->provider->environment();
		$identity    = $this->provider->identify( $context );

		if ( ! $identity->read_complete ) {
			// Still unresolved. Not a failure, and emphatically not a reason to
			// write again — the next attempt is another read.
			return RegistrationOutcome::ambiguous( 'The hosting provider could not be read; this stays unresolved.' );
		}

		if ( ! $identity->attached ) {
			return RegistrationOutcome::refused( 'The hosting provider does not have this hostname; it was never registered.' );
		}

		$bound = null === $environment ? null : $environment->site_id;

		if ( null !== $bound && $identity->attached_site_id !== $bound ) {
			return RegistrationOutcome::foreign( 'That hostname is attached to a different site on this hosting account.' );
		}

		return RegistrationOutcome::already_mine(
			$identity->reference,
			null === $environment ? '' : $environment->id()
		);
	}
}
