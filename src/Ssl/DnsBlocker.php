<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Something the operator cannot resolve by publishing a record.
 *
 * A blocker carries the phase it belongs to as structured metadata. It used to
 * carry none, and the workflow inferred ownership by looking for the purpose
 * string inside the code or the message. That is not a contract: it missed every
 * blocker whose code does not happen to embed the purpose — the malformed
 * ownership record is `provider_record_malformed` — and it would have failed
 * outright the moment the messages were translated.
 */
final class DnsBlocker {

	/**
	 * @param string      $purpose The plan purpose this blocker belongs to
	 *                             (`provider_ownership`, `ssl_validation`,
	 *                             `routing`, `ownership`), or null when it is
	 *                             global: the read itself failed, so nothing is
	 *                             known about any phase.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly string $remedy,
		public readonly string $source,
		public readonly ?string $purpose = null
	) {}

	/** Whether this blocker bears on the given purpose, globals included. */
	public function affects( string $purpose ): bool {
		return null === $this->purpose || $this->purpose === $purpose;
	}

	/** A failed read says nothing about any one phase, so it bears on all of them. */
	public function is_global(): bool {
		return null === $this->purpose;
	}
}
