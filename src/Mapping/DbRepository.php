<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Support\Schema;

final class DbRepository implements MappingRepository {

	public function by_host( string $ascii_host ): ?Mapping {
		global $wpdb;

		$table = Schema::domains_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE host = %s", $ascii_host ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return null === $row ? null : Mapping::from_row( $row );
	}

	public function by_id( int $id ): ?Mapping {
		global $wpdb;

		$table = Schema::domains_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return null === $row ? null : Mapping::from_row( $row );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return Mapping[]
	 */
	public function all( array $args = array() ): array {
		global $wpdb;

		unset( $args );
		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	public function save( Mapping $m ): Mapping {
		throw new \RuntimeException( 'save() lands in Task 4.' );
	}

	public function delete( int $id ): void {
		throw new \RuntimeException( 'delete() lands in Task 6.' );
	}
}
