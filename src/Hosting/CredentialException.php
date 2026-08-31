<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use RuntimeException;

/**
 * The only exception the credential store throws.
 *
 * Its messages are compile-time constants chosen from a fixed set. There is no
 * constructor that accepts caller-supplied text, so no code path — not a
 * mistake in this class, not a well-meaning `catch`/rethrow elsewhere — can
 * interpolate a token into a message that ends up in a log line, an admin
 * notice, a stack trace or a PHPUnit failure diff.
 *
 * @package PostDomain
 */
final class CredentialException extends RuntimeException {

	public const NO_SECURE_PRIMITIVE = 'no_secure_primitive';
	public const EXTERNALLY_PROVIDED = 'externally_provided';
	public const EMPTY_CREDENTIAL    = 'empty_credential';
	public const NO_KEY_MATERIAL     = 'no_key_material';

	private string $reason;

	private function __construct( string $reason, string $message ) {
		parent::__construct( $message );

		$this->reason = $reason;
	}

	public function reason(): string {
		return $this->reason;
	}

	/** No authenticated-encryption primitive is available, so nothing was stored. */
	public static function no_secure_primitive(): self {
		return new self(
			self::NO_SECURE_PRIMITIVE,
			'No authenticated encryption primitive is available; the credential was not stored.'
		);
	}

	/** The credential comes from wp-config.php and is not the database's to change. */
	public static function externally_provided(): self {
		return new self(
			self::EXTERNALLY_PROVIDED,
			'The credential is supplied by a constant or filter and cannot be overwritten.'
		);
	}

	public static function empty_credential(): self {
		return new self(
			self::EMPTY_CREDENTIAL,
			'An empty credential cannot be stored; remove it instead.'
		);
	}

	public static function no_key_material(): self {
		return new self(
			self::NO_KEY_MATERIAL,
			'No WordPress secret material is available to derive an encryption key from.'
		);
	}
}
