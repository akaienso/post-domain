<?php
declare( strict_types = 1 );

namespace PostDomain\Contracts;

use PostDomain\Mapping\Mapping;

interface MappingRepository {

	public function by_host( string $ascii_host ): ?Mapping;

	public function by_id( int $id ): ?Mapping;

	/**
	 * @param array<string, mixed> $args
	 * @return Mapping[]
	 */
	public function all( array $args = array() ): array;

	public function save( Mapping $m ): Mapping;

	public function delete( int $id ): void;
}
