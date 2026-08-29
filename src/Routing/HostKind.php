<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum HostKind: string {
	case PRIMARY                = 'primary';
	case MAPPED                 = 'mapped';
	case ALLOWED_INFRASTRUCTURE = 'allowed_infrastructure';
	case UNKNOWN                = 'unknown';
	case MALFORMED              = 'malformed';
}
