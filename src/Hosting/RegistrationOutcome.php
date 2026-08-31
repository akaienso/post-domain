<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * What happened when a hostname was offered to a hosting provider.
 *
 * `AMBIGUOUS` is deliberately a first-class outcome rather than an error: a
 * timed-out write may or may not have landed, and the only safe next step is a
 * read. Collapsing it into failure is what produces duplicate mutations.
 */
final class RegistrationOutcome {

	private function __construct(
		public readonly HostingRegistrationState $state,
		public readonly ?string $reference,
		public readonly ?string $environment_id,
		public readonly ?string $message
	) {}

	public static function registered( string $reference, string $environment_id ): self {
		return new self( HostingRegistrationState::REGISTERED, $reference, $environment_id, null );
	}

	public static function already_mine( ?string $reference, string $environment_id ): self {
		return new self( HostingRegistrationState::ALREADY_MINE, $reference, $environment_id, null );
	}

	/** Attached, but to a site this installation is not bound to. Never adopted. */
	public static function foreign( string $message ): self {
		return new self( HostingRegistrationState::FOREIGN, null, null, $message );
	}

	public static function refused( string $message ): self {
		return new self( HostingRegistrationState::REFUSED, null, null, $message );
	}

	public static function ambiguous( string $message ): self {
		return new self( HostingRegistrationState::AMBIGUOUS, null, null, $message );
	}

	/** The provider has no such concept — the manual provider's ordinary answer. */
	public static function unsupported(): self {
		return new self( HostingRegistrationState::UNSUPPORTED, null, null, null );
	}

	/**
	 * The provider answered and the answer could not be written.
	 *
	 * Never a success and never terminal: the durable claim survives, so a
	 * read-only recovery pass settles it without another write.
	 */
	public static function fenced(): self {
		return new self(
			HostingRegistrationState::FENCED,
			null,
			null,
			'The hosting result could not be recorded because the domain changed at the same moment. This will be settled by reading.'
		);
	}

	public function succeeded(): bool {
		return in_array(
			$this->state,
			array(
				HostingRegistrationState::REGISTERED,
				HostingRegistrationState::ALREADY_MINE,
				HostingRegistrationState::UNSUPPORTED,
			),
			true
		);
	}
}
