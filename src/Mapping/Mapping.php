<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Ssl\MutationKind;
use PostDomain\Ssl\MutationPhase;

final class Mapping {

	public function __construct(
		public readonly int $id,
		public readonly string $host,
		public readonly ?int $alias_of,
		public readonly ?int $post_id,
		public readonly int $revision,
		public readonly VerificationState $verification_state,
		public readonly ActivationState $activation_state,
		public readonly SslState $ssl_state,
		public readonly ?string $integrity_error,
		public readonly string $challenge,
		public readonly string $challenge_label,
		public readonly ?OwnershipOrigin $ssl_ownership_origin = null,
		public readonly ?string $ssl_owner_installation_id = null,
		public readonly ?string $ssl_provider = null,
		public readonly ?string $ssl_provider_environment = null,
		public readonly ?string $ssl_ref = null,
		public readonly ?string $ssl_method = null,
		public readonly ?string $ssl_mutation_token = null,
		public readonly ?MutationKind $ssl_mutation_kind = null,
		public readonly ?MutationPhase $ssl_mutation_phase = null,
		public readonly ?string $ssl_mutation_expires_at = null,
		public readonly ?string $ssl_mutation_driver = null,
		public readonly ?string $ssl_mutation_environment = null,
		public readonly ?string $ssl_next_attempt_at = null,
		public readonly int $ssl_transient_count = 0,
		public readonly ?string $ssl_adopted_at = null,
		public readonly ?int $ssl_adopted_by = null
	) {}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['host'],
			null === $row['alias_of'] ? null : (int) $row['alias_of'],
			null === $row['post_id'] ? null : (int) $row['post_id'],
			(int) $row['revision'],
			VerificationState::from( (string) $row['verification_state'] ),
			ActivationState::from( (string) $row['activation_state'] ),
			SslState::from( (string) $row['ssl_state'] ),
			$row['integrity_error'],
			(string) $row['challenge'],
			(string) $row['challenge_label'],
			null === $row['ssl_ownership_origin'] ? null : OwnershipOrigin::from( (string) $row['ssl_ownership_origin'] ),
			$row['ssl_owner_installation_id'],
			$row['ssl_provider'],
			$row['ssl_provider_environment'],
			$row['ssl_ref'],
			$row['ssl_method'],
			$row['ssl_mutation_token'],
			null === $row['ssl_mutation_kind'] ? null : MutationKind::from( (string) $row['ssl_mutation_kind'] ),
			null === $row['ssl_mutation_phase'] ? null : MutationPhase::from( (string) $row['ssl_mutation_phase'] ),
			$row['ssl_mutation_expires_at'],
			$row['ssl_mutation_driver'],
			$row['ssl_mutation_environment'],
			$row['ssl_next_attempt_at'],
			(int) ( $row['ssl_transient_count'] ?? 0 ),
			$row['ssl_adopted_at'],
			null === $row['ssl_adopted_by'] ? null : (int) $row['ssl_adopted_by']
		);
	}

	public function is_alias(): bool {
		return null !== $this->alias_of;
	}
}
