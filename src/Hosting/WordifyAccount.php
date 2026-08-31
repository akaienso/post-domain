<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/** The authenticated identity, and the teams it can act for. */
final class WordifyAccount {

	/**
	 * @param string[] $team_ids
	 */
	public function __construct(
		public readonly ?string $user_id,
		public readonly array $team_ids
	) {}

	public function can_act_for( string $team_id ): bool {
		return in_array( $team_id, $this->team_ids, true );
	}
}
