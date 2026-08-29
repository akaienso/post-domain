<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

/**
 * Resolves an ambiguous first create by reading, never by repeating the POST.
 * Only one outcome binds a reference without an explicit adoption, and it needs
 * a marker naming this installation AND this mapping.
 */
final class CreateRecovery {

	public const BIND           = 'bind';
	public const RETRY          = 'retry';
	public const ADOPT_REQUIRED = 'adopt_required';
	public const UNOWNED        = 'unowned';
	public const WAIT           = 'wait';

	public static function decide( IdentityResult $identity, SslResourceContext $ctx ): string {
		if ( ! $identity->read_complete || $identity->transient ) {
			return self::WAIT;
		}

		if ( null !== $ctx->provider_ref ) {
			// Already bound: the strict MATCH rule applies, not recovery.
			return self::WAIT;
		}

		if ( IdentityVerdict::ABSENT === $identity->verdict ) {
			return self::RETRY;
		}

		if ( $identity->is_recoverable_create( $ctx->installation_id, $ctx->mapping_id, $ctx->host ) ) {
			return self::BIND;
		}

		if ( null !== $identity->marker ) {
			// A marker that does not name this installation and mapping.
			return self::UNOWNED;
		}

		return self::ADOPT_REQUIRED;
	}
}
