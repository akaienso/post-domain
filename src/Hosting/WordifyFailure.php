<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * A provider call that produced no usable answer.
 *
 * The message is a fixed sentence chosen by this plugin. The response body is
 * never carried here, never logged and never surfaced: provider responses can
 * contain account data, and the plugin's rule is that they are not shown raw.
 * The status code is kept because it is a number, not content.
 */
final class WordifyFailure {

	private function __construct(
		public readonly WordifyFailureKind $kind,
		public readonly ?int $status,
		public readonly string $message,
		public readonly ?string $operation,
		/** Wordify's X-Request-Id, safe to quote to support; identifies nobody. */
		public readonly ?string $request_id = null
	) {}

	/**
	 * An operation this build has no route for.
	 *
	 * All six routes ship, so this is reachable only if a filter removed one,
	 * which the endpoint map does not permit — it is kept as a fail-closed floor
	 * rather than as an expected state.
	 */
	public static function endpoint_unverified( string $operation ): self {
		return new self(
			WordifyFailureKind::ENDPOINT_UNVERIFIED,
			null,
			'That Wordify operation is not available in this version of the plugin.',
			$operation
		);
	}

	public static function not_configured( string $operation ): self {
		return new self( WordifyFailureKind::NOT_CONFIGURED, null, 'Wordify hosting is not configured.', $operation );
	}

	public static function transport( string $operation, int $status = 0 ): self {
		return new self( WordifyFailureKind::TRANSPORT, $status, 'The hosting provider did not answer.', $operation );
	}

	public static function rate_limited( string $operation ): self {
		return new self( WordifyFailureKind::RATE_LIMITED, 429, 'The hosting provider is rate limiting this account.', $operation );
	}

	/**
	 * The credential was rejected outright: absent, malformed, expired, revoked.
	 *
	 * Wordify answers 401 with a JSON envelope carrying `error.code`,
	 * `error.request_id` and a human message. The message is not carried here —
	 * a provider's own prose is not shown to an administrator or written to a
	 * log — but the request id is, because it is the one thing that makes a
	 * support conversation possible and it identifies no one on its own.
	 */
	public static function unauthenticated( string $operation, ?string $request_id = null ): self {
		return new self(
			WordifyFailureKind::UNAUTHENTICATED,
			401,
			'The Wordify API token was not accepted.',
			$operation,
			$request_id
		);
	}

	/**
	 * Authenticated, and not permitted.
	 *
	 * For this plugin that almost always means a token created with Read Sites
	 * but not Manage Sites. Nothing here can prove that before a mutation is
	 * attempted, so this is where it surfaces, and it must surface as advice
	 * rather than as a retry.
	 */
	public static function insufficient_ability( string $operation, ?string $request_id = null ): self {
		return new self(
			WordifyFailureKind::INSUFFICIENT_ABILITY,
			403,
			'The Wordify API token does not have the Manage Sites ability.',
			$operation,
			$request_id
		);
	}

	public static function refused( string $operation, int $status ): self {
		return new self( WordifyFailureKind::REFUSED, $status, 'The hosting provider rejected the request.', $operation );
	}

	public static function malformed( string $operation, int $status ): self {
		return new self( WordifyFailureKind::MALFORMED, $status, 'The hosting provider answered with something unreadable.', $operation );
	}

	public function is_transient(): bool {
		return $this->kind->is_transient();
	}
}
