<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Per-request record of collisions, for diagnostics. Not persisted: a collision
 * is a property of the current content tree, not history.
 */
final class AmbiguousPath {

	/** @var array<int, array{mapping_id: int, segment: string, candidates: int[]}> */
	private static array $records = array();

	/** @param int[] $candidates */
	public static function record( int $mapping_id, string $segment, array $candidates ): void {
		self::$records[] = array(
			'mapping_id' => $mapping_id,
			'segment'    => $segment,
			'candidates' => $candidates,
		);
	}

	/** @return array<int, array{mapping_id: int, segment: string, candidates: int[]}> */
	public static function all(): array {
		return self::$records;
	}

	public static function reset(): void {
		self::$records = array();
	}
}
