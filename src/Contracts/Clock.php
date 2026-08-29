<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

interface Clock {

	public function now(): \DateTimeImmutable;

	/** UTC, in the shape every DATETIME column stores. */
	public function mysql(): string;
}
