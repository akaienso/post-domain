<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Contracts\MappingRepository;

final class AliasResolver {

	public function __construct( private readonly MappingRepository $repo ) {}

	public function canonical_for( Mapping $m ): ?Mapping {
		return $m->is_alias() ? $this->repo->by_id( (int) $m->alias_of ) : $m;
	}

	public function canonical_host( Mapping $m ): string {
		return $this->canonical_for( $m )?->host ?? $m->host;
	}

	public function effective_post_id( Mapping $m ): ?int {
		return $this->canonical_for( $m )?->post_id;
	}

	/** @return Mapping[] */
	public function aliases_of( int $canonical_id ): array {
		return array_values(
			array_filter(
				$this->repo->all(),
				static fn( Mapping $m ): bool => $m->alias_of === $canonical_id
			)
		);
	}
}
