<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum OwnershipOrigin: string {
	case CREATED = 'created';
	case ADOPTED = 'adopted';
}
