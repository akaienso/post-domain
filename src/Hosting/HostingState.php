<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The durable hosting-registration state of one mapping.
 *
 * Distinct from `HostingRegistrationState`, which is what a single provider
 * call answered. This is what the row remembers between requests, and it exists
 * so that a crash, a timeout or a second worker cannot turn one attachment into
 * two.
 *
 * `RESERVED` is written *before* the provider is called. That ordering is the
 * whole point: a row that says RESERVED after a crash means "a mutation may
 * have been sent", which recovery settles by reading. A row with no state means
 * nothing was ever sent.
 *
 * @package PostDomain
 */
enum HostingState: string {

	/** Claimed for one attachment attempt. The provider may already have it. */
	case RESERVED = 'reserved';

	/** The provider confirmed the hostname on the bound site. */
	case ATTACHED = 'attached';

	/** A write that may or may not have landed. Settled by reading only. */
	case AMBIGUOUS = 'ambiguous';

	/** Read enough times without settling. Needs a person. */
	case MANUAL_REVIEW = 'manual_review';

	/** The provider said no, terminally: bad credential, missing ability. */
	case REFUSED = 'refused';

	/** The hostname belongs to a different site on the same account. */
	case FOREIGN = 'foreign';

	/** Manual hosting: there is no provider to register with. */
	case NOT_REQUIRED = 'not_required';

	/** True while an attachment may be outstanding at the provider. */
	public function is_outstanding(): bool {
		return self::RESERVED === $this || self::AMBIGUOUS === $this;
	}

	/** True when nothing further will happen without an operator. */
	public function is_terminal(): bool {
		return in_array(
			$this,
			array( self::ATTACHED, self::REFUSED, self::FOREIGN, self::MANUAL_REVIEW, self::NOT_REQUIRED ),
			true
		);
	}

	/** True when the origin can be relied on to answer for the hostname. */
	public function is_settled_ok(): bool {
		return self::ATTACHED === $this || self::NOT_REQUIRED === $this;
	}

	public static function of( ?string $value ): ?self {
		return null === $value || '' === $value ? null : self::tryFrom( $value );
	}
}
