<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The read-only connection test, and the site reads the selector needs.
 *
 * This is the production listener behind `pd_hosting_test_connection`. It reads
 * and only reads: identity, the team the token can act for, and one bounded
 * page of that team's sites. It never attaches a domain, never rechecks DNS and
 * never writes anything at the provider — which is also why it cannot prove the
 * `sites:manage` ability, and does not pretend to.
 *
 * Nothing here stores a binding. Passing a connection test means the token
 * works; it does not mean the operator has told the plugin which site this
 * WordPress installation is. That remains a separate, explicit step.
 *
 * @package PostDomain
 */
final class WordifyConnectionService {

	/** A page an operator can actually read, on an account with hundreds. */
	public const PER_PAGE = 25;

	public function __construct( private readonly WordifyClient $client ) {}

	/**
	 * The production instance, built from the configured credential.
	 *
	 * The team is whichever one the operator has already bound, so a second test
	 * confirms the binding rather than drifting back to the account default.
	 */
	public static function production( ?string $team_id = null ): self {
		return new self( HostingProviderFactory::wordify_client( $team_id ) );
	}

	/** The `pd_hosting_test_connection` listener. */
	public static function test(): ConnectionResult {
		$binding = HostingBinding::current();

		if ( ! $binding->has_credential() ) {
			return ConnectionResult::no_credential();
		}

		return self::production( $binding->team_id )->probe( $binding->team_id );
	}

	/**
	 * The authenticated identity, and nothing else.
	 *
	 * The teams it names are the only teams this token can act for, which makes
	 * it the one admissible source for a team choice.
	 *
	 * @return WordifyAccount|WordifyFailure
	 */
	public function account() {
		return $this->client->me();
	}

	/**
	 * Reads identity, resolves a team, and lists that team's first page of sites.
	 *
	 * @param string|null $prefer_team A team already chosen, honoured when the
	 *                                 token can still act for it.
	 */
	public function probe( ?string $prefer_team = null, int $page = 1, string $search = '' ): ConnectionResult {
		$account = $this->client->me();

		if ( $account instanceof WordifyFailure ) {
			return ConnectionResult::from_failure( $account );
		}

		if ( null !== $prefer_team && '' !== $prefer_team ) {
			// A stated preference is honoured or refused, never silently
			// replaced. Falling back to the account default here would list —
			// and could bind — a different team's sites than the one the
			// operator named, which is the one substitution that must not happen
			// quietly.
			$team = $account->team( $prefer_team );

			return null === $team
				? ConnectionResult::no_team( $account )
				: $this->with_sites( $account, $team, $page, $search );
		}

		$team = $account->default_team();

		if ( null === $team ) {
			// Either no team at all, or several with none chosen. Both are for
			// the operator to resolve; neither is something to guess at.
			return ConnectionResult::no_team( $account );
		}

		return $this->with_sites( $account, $team, $page, $search );
	}

	/** One page of a resolved team's sites, or why there is none. */
	private function with_sites( WordifyAccount $account, WordifyTeam $team, int $page, string $search ): ConnectionResult {

		$sites = $this->sites( $team, $page, $search );

		if ( $sites instanceof WordifyFailure ) {
			return ConnectionResult::from_failure( $sites );
		}

		if ( $sites->is_empty() && '' === $search && 1 === $page ) {
			return ConnectionResult::no_sites( $account, $team );
		}

		return ConnectionResult::ready( $account, $team, $sites );
	}

	/**
	 * One bounded page of a team's sites.
	 *
	 * Bounded rather than complete on purpose: an account with hundreds of sites
	 * must still produce a page a person can read, and an unbounded read is a
	 * request that gets slower exactly as it gets more important.
	 *
	 * @return WordifySiteList|WordifyFailure
	 */
	public function sites( WordifyTeam $team, int $page = 1, string $search = '' ) {
		$filters = array(
			'page'     => (string) max( 1, $page ),
			'per_page' => (string) self::PER_PAGE,
		);

		if ( '' !== $search ) {
			// The one verified site filter. A hostname-shaped search goes to
			// `domain`, which is what the operator is almost always typing.
			$filters['domain'] = $search;
		}

		return $this->client_for( $team )->sites( $filters );
	}

	/**
	 * Re-reads one site with the credential, in the team it is claimed to be in.
	 *
	 * The confirming read before a binding is written. Reading the collection is
	 * not the same as reading the resource: this proves the token can address
	 * that exact site in that exact team, which is the authority a binding
	 * claims to have.
	 *
	 * @return WordifySite|WordifyFailure
	 */
	public function confirm_site( WordifyTeam $team, string $site_id ) {
		return $this->client_for( $team )->site( $site_id );
	}

	/**
	 * The client, bound to the team being read.
	 *
	 * A multi-team account needs the team header on every call, and the team
	 * being examined is not necessarily the one the binding names — during
	 * selection there may be no binding at all.
	 */
	private function client_for( WordifyTeam $team ): WordifyClient {
		if ( $this->client instanceof WordifyApiClient ) {
			return HostingProviderFactory::wordify_client( $team->id );
		}

		// A substituted client in a test already answers for whatever team it
		// was built with; rebuilding it here would discard the substitution.
		return $this->client;
	}
}
