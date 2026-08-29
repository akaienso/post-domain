<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

enum ActivationState: string {
	case INACTIVE = 'inactive';
	case ACTIVE   = 'active';

	public function can_transition_to( self $to ): bool {
		unset( $to );

		return true;
	}
}
