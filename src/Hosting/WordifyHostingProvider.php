<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Contracts\HostingProvider;

/**
 * The Wordify hosting provider.
 *
 * Four rules, all of them about not making things worse:
 *
 * 1. A write is attempted at most once per call. A refusal, a timeout or an
 *    already-attached answer is resolved by *reading*, never by writing again.
 * 2. A hostname is only ever claimed as ours after a read has shown it on the
 *    bound site. A hostname on some other site is `foreign()` and is never
 *    adopted, because adopting it would take a live domain off another site.
 * 3. A read that failed is `unknown()`, never `absent()`. Absence has to be
 *    observed, not inferred from a provider that did not answer.
 * 4. `recheck()` is never called from `register()` or `identify()`. It performs
 *    live DNS queries and can trigger a Let's Encrypt issuance request, which is
 *    rate limited per registered domain per week, so it is only ever an explicit
 *    operator action — `recheck_dns()` below.
 *
 * Detachment is unsupported, permanently and on purpose: no verified Wordify
 * surface exposes a domain removal operation, and inventing one is exactly the
 * kind of guess this integration must not make.
 *
 * @package PostDomain
 */
final class WordifyHostingProvider implements HostingProvider {

	public const ID = 'wordify';

	public function __construct(
		private readonly WordifyClient $client,
		private readonly HostingEnvironment $environment
	) {}

	public function id(): string {
		return self::ID;
	}

	public function environment(): ?HostingEnvironment {
		return $this->environment;
	}

	public function is_ready(): bool {
		return '' !== $this->environment->site_id && $this->client->is_ready();
	}

	public function identify( HostingResourceContext $context ): HostingIdentityResult {
		$domains = $this->client->domains( $this->environment->site_id );

		if ( $domains instanceof WordifyFailure ) {
			return HostingIdentityResult::unknown( $domains->kind->value );
		}

		$record = $domains->find( $context->host );

		if ( null === $record ) {
			return HostingIdentityResult::absent();
		}

		return HostingIdentityResult::attached( $this->environment->site_id, $record->reference, $record->is_primary );
	}

	public function register( HostingResourceContext $context ): RegistrationOutcome {
		if ( ! $this->is_ready() ) {
			return RegistrationOutcome::refused( 'Wordify hosting is not configured.' );
		}

		// Exactly one write. Everything after this point is a read.
		$attached = $this->client->attach_domain( $this->environment->site_id, $context->host );

		if ( $attached instanceof WordifyDomain ) {
			return $attached->is( $context->host )
				? RegistrationOutcome::registered( $attached->reference, $this->environment->id() )
				: RegistrationOutcome::ambiguous( 'The hosting provider acknowledged a different hostname.' );
		}

		return $this->resolve_by_reading( $context, $attached );
	}

	public function supports_detach(): bool {
		// Not "not implemented yet": no verified operation exists. The plugin
		// keeps its own mapping deletion behaviour and tells the operator what to
		// remove by hand.
		return false;
	}

	public function detach( HostingResourceContext $context ): RegistrationOutcome {
		unset( $context );

		return RegistrationOutcome::unsupported();
	}

	/**
	 * Refreshes DNS and SSL state for the bound site.
	 *
	 * Deliberately outside the `HostingProvider` interface so nothing in the
	 * registration or identification path can reach it. Rate limited, and capable
	 * of triggering certificate issuance, so it is only ever an operator's
	 * explicit request and is never retried in a loop.
	 *
	 * @return WordifyDomainList|WordifyFailure
	 */
	public function recheck_dns() {
		return $this->client->recheck( $this->environment->site_id );
	}

	/**
	 * The write did not clearly succeed. Read once to find out what is true.
	 *
	 * A duplicate or already-attached answer is only idempotent after this read
	 * shows the hostname on the *bound* site. If the read cannot see it there,
	 * the hostname's actual owner is looked up over the one verified endpoint;
	 * another site owning it is a refusal to adopt, not a success.
	 */
	private function resolve_by_reading( HostingResourceContext $context, WordifyFailure $write ): RegistrationOutcome {
		$domains = $this->client->domains( $this->environment->site_id );

		if ( $domains instanceof WordifyDomainList ) {
			$record = $domains->find( $context->host );

			if ( null !== $record ) {
				return RegistrationOutcome::already_mine( $record->reference, $this->environment->id() );
			}
		}

		$owner = $this->owning_site( $context->host );

		if ( null !== $owner && $owner === $this->environment->site_id ) {
			// A second confirming read, over the one verified endpoint, showing
			// the hostname on the bound site. Still a read, never a second write.
			return RegistrationOutcome::already_mine( null, $this->environment->id() );
		}

		if ( null !== $owner && $owner !== $this->environment->site_id ) {
			return RegistrationOutcome::foreign( 'That hostname is already attached to a different site on this hosting account.' );
		}

		if ( $domains instanceof WordifyFailure || $write->is_transient() ) {
			// The write may or may not have landed and the read could not settle
			// it. Ambiguity is resolved later by reading again, never by writing.
			return RegistrationOutcome::ambiguous( 'The hosting provider did not give a settled answer; this will be resolved by reading.' );
		}

		return RegistrationOutcome::refused( $write->message );
	}

	/**
	 * Which site owns a hostname, using the verified `GET /api/v1/sites` `domain`
	 * filter. Null when the answer is not knowable, which is never treated as
	 * "nobody owns it".
	 */
	private function owning_site( string $host ): ?string {
		$sites = $this->client->sites( array( 'domain' => $host ) );

		if ( $sites instanceof WordifyFailure ) {
			return null;
		}

		$site = $sites->first();

		return null === $site ? null : $site->id;
	}
}
