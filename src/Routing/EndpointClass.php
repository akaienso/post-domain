<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

enum EndpointClass: string {
	case CLI             = 'cli';
	case CRON            = 'cron';
	case ADMIN           = 'admin';
	case LOGIN           = 'login';
	case AJAX            = 'ajax';
	case REST_MANAGEMENT = 'rest_management';
	case REST_CONTENT    = 'rest_content';
	case COMMENT_POST    = 'comment_post';
	case TRACKBACK       = 'trackback';
	case XMLRPC          = 'xmlrpc';
	case CRON_HTTP       = 'cron_http';
	case INFRASTRUCTURE  = 'infrastructure';
	case ASSET           = 'asset';
	case WELL_KNOWN      = 'well_known';
	case SITEMAP         = 'sitemap';
	case ROUTED          = 'routed';

	/** Classes a filter may never produce or replace (spec §11.8). */
	public function is_protected(): bool {
		return ! in_array( $this, array( self::ROUTED, self::WELL_KNOWN, self::SITEMAP ), true );
	}
}
