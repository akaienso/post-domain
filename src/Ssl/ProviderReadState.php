<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * What a provider read actually established.
 *
 * An empty payload used to stand for every one of these at once, so a rate
 * limit, a timeout, an unreadable body and a genuinely absent resource all
 * rendered as "there is nothing outstanding". Only ABSENT_UNBOUND may mean that.
 */
enum ProviderReadState: string {
	/** The resource was read and its description is trustworthy. */
	case PRESENT = 'present';

	/** The provider answered definitively that no resource exists, and no `ssl_ref` is persisted. */
	case ABSENT_UNBOUND = 'absent_unbound';

	/** An `ssl_ref` is persisted, yet the provider reports no such resource. An anomaly, never silence. */
	case MISSING_BOUND = 'missing_bound';

	/** The provider did not answer, or answered in a way that says "ask again": timeout, 429, 5xx. */
	case TRANSIENT = 'transient';

	/** The provider answered, but the answer could not be understood or was a definitive rejection. */
	case MALFORMED = 'malformed';
}
