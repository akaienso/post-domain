<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * What actually became of a mutation attempt. These are five different facts and
 * a caller must be able to tell them apart: three of them look like success from
 * the provider's side and are not.
 */
enum MutationDisposition: string {

	/** The provider acted and the result is committed locally. */
	case COMMITTED = 'committed';

	/** Refused before the provider was called. Nothing happened anywhere. */
	case REFUSED = 'refused';

	/** The provider outcome is unknown; the row is retained for recovery. */
	case AMBIGUOUS_RETAINED = 'ambiguous_retained';

	/** Recovery replaced our token mid-flight: our result was discarded. */
	case FENCED = 'fenced';

	/**
	 * The provider confirmed, but the local write did not land — or, after an
	 * unconfirmed COMMIT, may or may not have landed. Either way this process
	 * cannot claim success and must not repeat the provider call. The row is
	 * re-read, and recovery or reconciliation settles what remains.
	 */
	case CONFIRMED_NOT_PERSISTED = 'confirmed_not_persisted';
}
