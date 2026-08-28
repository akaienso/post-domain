<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

interface Scheduler {

	/** @param array<int, mixed> $args */
	public function schedule( string $hook, \DateTimeImmutable $at, array $args = array() ): void;

	/** @param array<int, mixed> $args */
	public function unschedule( string $hook, array $args = array() ): void;

	/** @param array<int, mixed> $args */
	public function next( string $hook, array $args = array() ): ?\DateTimeImmutable;
}
