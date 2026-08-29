<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

enum UrlKind: string {
	case HOME      = 'home';
	case PERMALINK = 'permalink';
	case TERM      = 'term';
	case REST      = 'rest';
	case AJAX      = 'ajax';
	case FEED      = 'feed';
	case COMMENT   = 'comment';
	case EMBED     = 'embed';
	case SITEMAP   = 'sitemap';
	case ASSET     = 'asset';
	case MAIL      = 'mail';

	public function prefers_canonical_host(): bool {
		return false;
	}
}
