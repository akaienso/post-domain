<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use JsonSerializable;

/**
 * Everything the admin screen, a REST response or a diagnostics dump is allowed
 * to know about a stored credential.
 *
 * Deliberately not a redacted token, not a masked token, not a length, not a
 * prefix — a saved credential is never redisplayed in any form. What is on
 * offer is a boolean and, at most, a keyed fingerprint (see
 * `CredentialKeyring::fingerprint()` for why that one is safe to show).
 *
 * Because it holds no secret, this object is safe to dump, encode and log. It
 * exists so that the things which *are* unsafe never have to be.
 *
 * @package PostDomain
 */
final class CredentialStatus implements JsonSerializable {

	private function __construct(
		public readonly bool $configured,
		public readonly CredentialSource $source,
		public readonly ?string $fingerprint
	) {}

	public static function absent(): self {
		return new self( false, CredentialSource::NONE, null );
	}

	public static function configured( CredentialSource $source, ?string $fingerprint ): self {
		return new self( true, $source, $fingerprint );
	}

	/** Supplied outside the database, so the plugin will not overwrite it. */
	public function is_external(): bool {
		return $this->source->is_external();
	}

	/** Whether the admin UI may offer to save or clear a value. */
	public function is_editable(): bool {
		return ! $this->is_external();
	}

	public function __toString(): string {
		return 'CredentialStatus(' . ( $this->configured ? 'configured' : 'absent' ) . ',' . $this->source->value . ')';
	}

	/** @return array{configured: bool, source: string, fingerprint: string|null, editable: bool} */
	public function __debugInfo(): array {
		return $this->jsonSerialize();
	}

	/** @return array{configured: bool, source: string, fingerprint: string|null, editable: bool} */
	public function jsonSerialize(): array {
		return array(
			'configured'  => $this->configured,
			'source'      => $this->source->value,
			'fingerprint' => $this->fingerprint,
			'editable'    => $this->is_editable(),
		);
	}
}
