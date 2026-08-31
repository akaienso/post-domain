<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/** The authenticated identity, and the teams it can act for. */
final class WordifyAccount {

	/**
	 * @param WordifyTeam[] $teams
	 */
	public function __construct(
		public readonly ?string $user_id,
		public readonly array $teams,
		public readonly ?string $current_team_id = null
	) {}

	/** @return string[] */
	public function team_ids(): array {
		return array_map( static fn ( WordifyTeam $team ): string => $team->id, $this->teams );
	}

	public function can_act_for( string $team_id ): bool {
		return in_array( $team_id, $this->team_ids(), true );
	}

	public function team( string $team_id ): ?WordifyTeam {
		foreach ( $this->teams as $team ) {
			if ( hash_equals( $team->id, $team_id ) ) {
				return $team;
			}
		}

		return null;
	}

	/**
	 * The team to work in when the operator has not chosen one.
	 *
	 * The provider's own `current_team_id` when it names a team this token can
	 * act for, otherwise the only team there is. Never a guess between several.
	 */
	public function default_team(): ?WordifyTeam {
		if ( null !== $this->current_team_id ) {
			$named = $this->team( $this->current_team_id );

			if ( null !== $named ) {
				return $named;
			}
		}

		return 1 === count( $this->teams ) ? $this->teams[0] : null;
	}
}
