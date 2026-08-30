<?php
declare( strict_types = 1 );

namespace PostDomain\Application;

use PostDomain\Mapping\Mapping;

/**
 * What an operator action did, in terms neither REST nor the admin screen owns.
 *
 * The code and status are the REST vocabulary because that contract is
 * published and must not drift; the message is written for a person, because
 * the admin screen shows it to one. A refusal carries both, so the two surfaces
 * cannot disagree about whether something was allowed.
 */
final class CommandResult {

	/** @param array<string, mixed> $payload */
	private function __construct(
		public readonly bool $succeeded,
		public readonly int $status,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?Mapping $mapping = null,
		public readonly array $payload = array()
	) {}

	/** @param array<string, mixed> $payload */
	public static function ok(
		int $status = 200,
		?Mapping $mapping = null,
		array $payload = array()
	): self {
		return new self( true, $status, null, null, $mapping, $payload );
	}

	public static function refused( string $code, string $message, int $status ): self {
		return new self( false, $status, $code, $message );
	}

	public function refused_as( string $code ): bool {
		return ! $this->succeeded && $this->code === $code;
	}
}
