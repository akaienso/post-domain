<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum Representation: string {
	case HTML      = 'html';
	case FEED      = 'feed';
	case EMBED     = 'embed';
	case TRACKBACK = 'trackback';
	case JSON      = 'json';
}
