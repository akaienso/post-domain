<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Exact-match only. No wildcards, no suffixes: an allowlist entry is a host,
 * not an authority.
 */
final class InfrastructureAllowlist {

	/** @var string[] */
	private array $entries;

	/**
	 * @param string[] $entries
	 */
	public function __construct( array $entries ) {
		$parser        = new AuthorityParser();
		$this->entries = array();

		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) || str_contains( $entry, '*' ) ) {
				continue;
			}

			$authority = $parser->parse( $entry );

			if ( null === $authority || null !== $authority->port ) {
				continue;
			}

			$this->entries[] = strtolower( $entry );
		}

		$this->entries = array_values( array_unique( $this->entries ) );
	}

	public function allows( Authority $authority ): bool {
		$candidate = strtolower(
			$authority->is_ipv6_literal ? $authority->bracketed_form : $authority->host
		);

		return in_array( $candidate, $this->entries, true );
	}

	/** @return string[] */
	public function entries(): array {
		return $this->entries;
	}
}
