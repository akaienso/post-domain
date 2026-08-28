<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum IdentityVerdict: string {
	case MATCH              = 'match';
	case RECOVERABLE_CREATE = 'recoverable_create';
	case MISMATCH           = 'mismatch';
	case ABSENT             = 'absent';
	case AMBIGUOUS          = 'ambiguous';
	case UNKNOWN            = 'unknown';
}
