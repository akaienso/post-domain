<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\DbRepository;
use PostDomain\Support\IdnaNormalizer;

final class MappingListTable {

	/** @return array<int, array<string, mixed>> */
	public static function rows(): array {
		$idna = new IdnaNormalizer();
		$rows = array();

		foreach ( ( new DbRepository() )->all() as $mapping ) {
			$rows[] = array(
				'id'           => $mapping->id,
				'host'         => $mapping->host,
				'host_display' => $idna->to_display( $mapping->host ),
				'target'       => $mapping->post_id,
				'verification' => $mapping->verification_state->value,
				'activation'   => $mapping->activation_state->value,
				'ssl'          => $mapping->ssl_state->value,
				'lease'        => null === $mapping->ssl_mutation_phase
					? null
					: $mapping->ssl_mutation_kind?->value . ':' . $mapping->ssl_mutation_phase->value,
			);
		}

		return $rows;
	}
}
