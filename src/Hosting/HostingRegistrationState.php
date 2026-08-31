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
}
