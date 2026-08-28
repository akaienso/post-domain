<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

enum TransitionOutcome: string {

	/** The transition is applied and, on InnoDB, its event committed with it. */
	case COMMITTED = 'committed';

	/** The CAS matched nothing: someone else owns the row. Nothing was written. */
	case CAS_LOST = 'cas_lost';

	/** The event could not be inserted, so the transition was rolled back. */
	case EVENT_FAILED = 'event_failed';

	/** No transaction could be started, so the transition was never attempted. */
	case TRANSACTION_UNAVAILABLE = 'transaction_unavailable';

	/**
	 * The commit was not confirmed, or a rollback failed. The transition may or
	 * may not have landed and this process cannot tell — and cannot find out by
	 * reading its own connection, which may be showing it its own uncommitted
	 * work. Never success, never fencing, and never a reason to repeat a provider
	 * call. A later pass, on a connection with a committed view, decides.
	 */
	case COMMIT_UNCERTAIN = 'commit_uncertain';
}
