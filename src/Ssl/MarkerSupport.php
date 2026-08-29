<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MarkerSupport: string {
	case SUPPORTED   = 'supported';
	case UNAVAILABLE = 'unavailable';
	case UNKNOWN     = 'unknown';
}
