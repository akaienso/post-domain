<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MutationPhase: string {
	case RESERVED   = 'reserved';
	case IN_FLIGHT  = 'in_flight';
	case RECOVERING = 'recovering';
}
