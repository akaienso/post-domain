<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/** In-process only: never persisted, serialized, or logged. */
final class MutationAuthorization {

	public function __construct(
		public readonly MutationOperation $operation,
		public readonly LeaseBinding $binding,
		public readonly bool $override_foreign_marker,
		public readonly \DateTimeImmutable $expires_at
	) {}

	public function is_expired( \DateTimeImmutable $now ): bool {
		return $this->expires_at <= $now;
	}
}
