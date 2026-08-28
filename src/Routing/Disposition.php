<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum Disposition: string {
	case PRIMARY         = 'primary';
	case INFRASTRUCTURE  = 'infrastructure';
	case SERVE           = 'serve';
	case MALFORMED_400   = 'malformed_400';
	case UNKNOWN_421     = 'unknown_421';
	case NOT_SERVING_404 = 'not_serving_404';
	case BROKEN_503      = 'broken_503';

	public function status(): ?int {
		return match ( $this ) {
			self::MALFORMED_400   => 400,
			self::UNKNOWN_421     => 421,
			self::NOT_SERVING_404 => 404,
			self::BROKEN_503      => 503,
			default               => null,
		};
	}
}
