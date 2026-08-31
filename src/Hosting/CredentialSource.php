<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * Where a credential the plugin is currently using came from.
 *
 * `CONSTANT` and `FILTER` are "externally provided": the operator supplied the
 * value outside the database, and the plugin must neither store it nor offer to
 * replace it.
 *
 * @package PostDomain
 */
enum CredentialSource: string {

	case NONE     = 'none';
	case CONSTANT = 'constant';
	case FILTER   = 'filter';
	case DATABASE = 'database';

	public function is_external(): bool {
		return self::CONSTANT === $this || self::FILTER === $this;
	}
}
