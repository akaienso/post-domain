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
		public readonly ?string $operation
	) {}

	public static function endpoint_unverified( string $operation ): self {
		return new self(
			WordifyFailureKind::ENDPOINT_UNVERIFIED,
			null,
			'This Wordify operation has no verified HTTP path. Supply one through the pd_wordify_endpoints filter.',
			$operation
		);
	}

	public static function auth_unverified( string $operation ): self {
		return new self(
			WordifyFailureKind::AUTH_UNVERIFIED,
			null,
			'The Wordify authentication header is not known. Supply it through the pd_wordify_endpoints filter.',
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
