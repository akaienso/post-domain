<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * What a read-only connection test established.
 *
 * A typed result rather than an array, so that what a test proved travels
 * intact to whatever consumes it, and is adapted into notice text only at the
 * admin boundary. The message here is this plugin's own sentence; the
 * provider's prose never reaches it, and neither does a response body.
 *
 * Note what is deliberately absent: any claim about the `sites:manage` ability.
 * No read-only call reports a token's abilities, and this plugin will not
 * perform a live mutation to find out, so the ability is discovered at the
 * first attachment and nowhere else.
 *
 * @package PostDomain
 */
final class ConnectionResult {

	private function __construct(
		public readonly ConnectionOutcome $outcome,
		public readonly ?WordifyAccount $account,
		public readonly ?WordifyTeam $team,
		public readonly ?WordifySiteList $sites,
		/** Wordify's correlation id, when it gave one. Identifies nobody. */
		public readonly ?string $request_id
	) {}

	public static function ready( WordifyAccount $account, WordifyTeam $team, WordifySiteList $sites ): self {
		return new self( ConnectionOutcome::READY, $account, $team, $sites, null );
	}

	public static function no_credential(): self {
		return new self( ConnectionOutcome::NO_CREDENTIAL, null, null, null, null );
	}

	/**
	 * Authenticated, and no single team follows from that.
	 *
	 * The account travels with it, because the teams it names are exactly the
	 * choices an operator may be offered — and the only ones. A team that did
	 * not come back from `GET /me` is not a team this token can act for.
	 */
	public static function no_team( WordifyAccount $account ): self {
		return new self( ConnectionOutcome::NO_TEAM, $account, null, null, null );
	}

	public static function no_sites( WordifyAccount $account, WordifyTeam $team ): self {
		return new self( ConnectionOutcome::NO_SITES, $account, $team, null, null );
	}

	/** Maps a provider failure onto the outcome an operator can act on. */
	public static function from_failure( WordifyFailure $failure ): self {
		$outcome = match ( $failure->kind ) {
			WordifyFailureKind::UNAUTHENTICATED      => ConnectionOutcome::REJECTED,
			WordifyFailureKind::INSUFFICIENT_ABILITY => ConnectionOutcome::NOT_PERMITTED,
			WordifyFailureKind::NOT_CONFIGURED       => ConnectionOutcome::NO_CREDENTIAL,
			default                                  => ConnectionOutcome::UNREACHABLE,
		};

		return new self( $outcome, null, null, null, $failure->request_id );
	}

	public function is_ready(): bool {
		return $this->outcome->is_ready();
	}

	/** True when the operator still has to pick which team to work in. */
	public function needs_team(): bool {
		return ConnectionOutcome::NO_TEAM === $this->outcome
			&& null !== $this->account
			&& array() !== $this->account->teams;
	}

	/** True when the operator still has to pick between several sites. */
	public function needs_selection(): bool {
		return $this->is_ready() && null !== $this->sites && ! $this->sites->is_empty();
	}
}
