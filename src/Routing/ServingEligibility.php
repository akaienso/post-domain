<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\Mapping;

/**
 * Phase B, frozen at plugins_loaded : 11. Answers only "is this host permitted to
 * serve?" — no content-model question is asked here.
 */
final class ServingEligibility {

	public function __construct(
		public readonly Mapping $mapping,
		public readonly string $requested_host,
		public readonly string $canonical_host,
		public readonly bool $is_active
	) {}

	public static function decide( HostContext $context, AliasResolver $aliases ): ?self {
		$mapping = $context->mapping;

		if ( HostKind::MAPPED !== $context->kind || null === $mapping ) {
			return null;
		}

		$stored = $context->may_serve();

		/** Veto only: the filter can reduce, never grant. */
		$active = $stored && (bool) apply_filters( 'pd_mapping_is_active', $stored, $mapping, $context );

		return new self(
			$mapping,
			$mapping->host,
			$aliases->canonical_host( $mapping ),
			$active
		);
	}
}
