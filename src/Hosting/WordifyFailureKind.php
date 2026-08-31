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
	/** This build has no route for the operation, so nothing was sent. */
	case ENDPOINT_UNVERIFIED = 'endpoint_unverified';

	/** No token, team or site is configured. */
	case NOT_CONFIGURED = 'not_configured';

	/** 401. The credential was rejected: absent, malformed, expired, revoked. */
	case UNAUTHENTICATED = 'unauthenticated';

	/** 403. The token is valid but lacks the ability the call needs. */
	case INSUFFICIENT_ABILITY = 'insufficient_ability';

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

	/**
	 * True when nothing about the request will change on a retry, so retrying is
	 * a wasted call rather than a chance of success. A missing ability and a
	 * rejected credential are both facts about the token, not about the moment.
	 */
	public function is_credential_fault(): bool {
		return self::UNAUTHENTICATED === $this || self::INSUFFICIENT_ABILITY === $this;
	}
}
