<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * One team the authenticated token can act for.
 *
 * `id` is opaque — a string the provider chose, never an integer, never
 * compared numerically and never rendered as one.
 */
final class WordifyTeam {

	public function __construct(
		public readonly string $id,
		public readonly ?string $name
	) {}

	public function label(): string {
		return null === $this->name || '' === $this->name ? $this->id : $this->name;
	}
}
