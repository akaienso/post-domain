<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum MutationOperation: string {
	case CREATE        = 'create';
	case ADOPT         = 'adopt';
	case CHANGE_METHOD = 'change_method';
	case REMOVE        = 'remove';

	public function kind(): MutationKind {
		return match ( $this ) {
			self::CREATE        => MutationKind::CREATE,
			self::ADOPT         => MutationKind::ADOPT,
			self::CHANGE_METHOD => MutationKind::METHOD,
			self::REMOVE        => MutationKind::REMOVE,
		};
	}
}
