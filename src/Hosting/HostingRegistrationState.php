<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/** The outcome of asking a hosting provider to accept a hostname. */
enum HostingRegistrationState: string {
	case REGISTERED   = 'registered';
	case ALREADY_MINE = 'already_mine';
	case FOREIGN      = 'foreign';
	case REFUSED      = 'refused';
	case AMBIGUOUS    = 'ambiguous';
	case UNSUPPORTED  = 'unsupported';

	/**
	 * The row moved between the provider answering and the state being written.
	 *
	 * The compare-and-swap matched nothing, so whatever the provider said was
	 * never recorded. Reporting the provider's answer here would announce a
	 * state the database does not hold; the claim stays, and recovery settles it
	 * by reading.
	 */
	case FENCED = 'fenced';
}
