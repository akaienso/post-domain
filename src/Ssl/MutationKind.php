<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MutationKind: string {
	case CREATE = 'create';
	case ADOPT  = 'adopt';
	case METHOD = 'method';
	case REMOVE = 'remove';
}
