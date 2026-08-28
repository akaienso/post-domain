<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;

final class SslResourceContext {

	public function __construct(
		public readonly int $mapping_id,
		public readonly string $host,
		public readonly string $installation_id,
		public readonly string $provider_id,
		public readonly ?string $provider_environment,
		public readonly ?string $provider_ref,
		public readonly ?OwnershipOrigin $ownership_origin,
		public readonly ?string $owner_installation_id,
		public readonly string $challenge_name,
		public readonly string $challenge_value,
		public readonly string $challenge,
		public readonly int $revision,
		public readonly ?string $lease_token = null,
		public readonly ?string $requested_method = null
	) {}

	/**
	 * The driver id is passed in rather than read from the row: during a first
	 * create or an adoption `ssl_provider` is still NULL while a real driver is
	 * already handling the request, and a context that claimed provider 'null'
	 * there is how a recovered create ends up writing the literal string 'null'
	 * into `ssl_provider`.
	 */
	public static function from_mapping(
		Mapping $mapping,
		string $installation_id,
		string $challenge_name,
		string $driver_id,
		?string $lease_token = null
	): self {
		return new self(
			$mapping->id,
			$mapping->host,
			$installation_id,
			$driver_id,
			$mapping->ssl_provider_environment,
			$mapping->ssl_ref,
			$mapping->ssl_ownership_origin,
			$mapping->ssl_owner_installation_id,
			$challenge_name,
			'post-domain-verify=' . $mapping->challenge,
			$mapping->challenge,
			$mapping->revision,
			$lease_token,
			$mapping->ssl_method
		);
	}

	/** Column state only: no event query participates in this answer. */
	public function has_ownership_authority(): bool {
		return null !== $this->ownership_origin
			&& null !== $this->owner_installation_id
			&& $this->owner_installation_id === $this->installation_id;
	}
}
