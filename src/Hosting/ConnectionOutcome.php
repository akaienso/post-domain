<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/** Why a connection test ended the way it did. */
enum ConnectionOutcome: string {

	/** Authenticated, a team was resolved, and sites could be listed. */
	case READY = 'ready';

	/** Nothing to test: no token is configured on this site. */
	case NO_CREDENTIAL = 'no_credential';

	/** The token was rejected: absent, malformed, expired, revoked. */
	case REJECTED = 'rejected';

	/** Authenticated, but not permitted to read what the plugin needs. */
	case NOT_PERMITTED = 'not_permitted';

	/** The token works and can act for no team, so there is nothing to bind. */
	case NO_TEAM = 'no_team';

	/** The team is readable and holds no sites. */
	case NO_SITES = 'no_sites';

	/** The provider did not answer, or answered with something unreadable. */
	case UNREACHABLE = 'unreachable';

	public function is_ready(): bool {
		return self::READY === $this;
	}
}
