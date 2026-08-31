<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Hosting\HostingEnvironment;
use PostDomain\Hosting\HostingIdentityResult;
use PostDomain\Hosting\HostingResourceContext;
use PostDomain\Hosting\RegistrationOutcome;

/**
 * A hosting or origin provider that must be told about a mapped hostname.
 *
 * Separate from `SslDriver` on purpose. The certificate provider terminates TLS
 * at the edge; the hosting provider is what finally answers with the page, and
 * it has to recognise the mapped Host header. They are different accounts,
 * different credentials and different failure modes, and forcing one into the
 * other's shape would make both harder to reason about.
 */
interface HostingProvider {

	/** A stable id: `wordify`, `manual`. */
	public function id(): string;

	/** The account and site this provider is currently bound to, if any. */
	public function environment(): ?HostingEnvironment;

	/** Whether this provider is configured well enough to be used at all. */
	public function is_ready(): bool;

	/** What the provider says exists for this hostname. Reads only. */
	public function identify( HostingResourceContext $context ): HostingIdentityResult;

	/** Asks the provider to accept the hostname. Never promotes it to primary. */
	public function register( HostingResourceContext $context ): RegistrationOutcome;

	/**
	 * Whether this provider can detach a hostname at all.
	 *
	 * Answering false is a legitimate, permanent answer — the plugin then keeps
	 * the mapping deletion behaviour it already has and tells the operator what
	 * to remove by hand, rather than inventing an operation.
	 */
	public function supports_detach(): bool;

	/** Detaches the hostname. Only ever called when `supports_detach()` is true. */
	public function detach( HostingResourceContext $context ): RegistrationOutcome;
}
