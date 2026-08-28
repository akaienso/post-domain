<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

enum ApexRouting: string {
	case CNAME_FLATTENING = 'cname_flattening';
	case ALIAS_OR_ANAME   = 'alias_or_aname';
	case APEX_PROXY       = 'apex_proxy';
	case UNSUPPORTED      = 'unsupported';
}
