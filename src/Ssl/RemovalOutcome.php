<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum RemovalOutcome: string {
	case REMOVED   = 'removed';
	case PENDING   = 'pending';
	case TRANSIENT = 'transient';
	case FAILED    = 'failed';
}
