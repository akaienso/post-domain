<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * Why a Wordify call did not produce an answer.
 *
 * The distinction that matters to callers is transient versus definite: a
 * transient failure says nothing about what exists at the provider and must be
 * resolved by reading, never by repeating a write.
 */
enum WordifyFailureKind: string {
	/** The operation's HTTP path was never verified, so nothing was sent. */
	case ENDPOINT_UNVERIFIED = 'endpoint_unverified';
	/** The auth header name was never verified, so no credential was sent. */
	case AUTH_UNVERIFIED = 'auth_unverified';
	/** No token, team or site is configured. */
	case NOT_CONFIGURED = 'not_configured';
	/** No answer: connection error, timeout, 5xx. */
	case TRANSPORT = 'transport';
	/** Answered, and said no. */
	case REFUSED = 'refused';
	/** Answered 429. Backs off; never loops. */
	case RATE_LIMITED = 'rate_limited';
	/** Answered with something this client cannot read. */
	case MALFORMED = 'malformed';

	/** True when the call may or may not have landed, or may succeed later. */
	public function is_transient(): bool {
		return self::TRANSPORT === $this || self::RATE_LIMITED === $this;
	}
}
