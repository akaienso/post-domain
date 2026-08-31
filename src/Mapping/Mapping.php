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
		public readonly ?int $ssl_adopted_by = null,
		public readonly ?string $ssl_error = null,
		public readonly ?string $ssl_checked_at = null,
		public readonly ?string $deletion_requested_at = null,
		public readonly int $deletion_attempts = 0,
		public readonly ?string $ssl_removal_scope = null,
		// Read-only for the admin: what the verification sweep last observed and
		// when it will look again. The screen tells an operator when something
		// will happen next, and it must read that from the state the server acts
		// on rather than restating a schedule from memory.
		public readonly ?string $last_checked_at = null,
		public readonly ?string $verify_next_attempt_at = null,
		public readonly ?string $verified_at = null,
		// The hosting/origin side of a mapping. Separate from the five SSL
		// binding columns because it is a different account with a different
		// credential: a site can have a certificate and still not be routed.
		public readonly ?string $hosting_provider = null,
		public readonly ?string $hosting_environment = null,
		public readonly ?string $hosting_ref = null,
		public readonly ?string $hosting_state = null,
		public readonly ?string $hosting_registered_at = null
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
			null === $row['ssl_adopted_by'] ? null : (int) $row['ssl_adopted_by'],
			$row['ssl_error'],
			$row['ssl_checked_at'],
			$row['deletion_requested_at'],
			(int) ( $row['deletion_attempts'] ?? 0 ),
			$row['ssl_removal_scope'] ?? null,
			$row['last_checked_at'] ?? null,
			$row['verify_next_attempt_at'] ?? null,
			$row['verified_at'] ?? null,
			$row['hosting_provider'] ?? null,
			$row['hosting_environment'] ?? null,
			$row['hosting_ref'] ?? null,
			$row['hosting_state'] ?? null,
			$row['hosting_registered_at'] ?? null
		);
	}

	public function is_alias(): bool {
		return null !== $this->alias_of;
	}
}
