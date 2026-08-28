<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Routing\Representation;
use PostDomain\Routing\ServingContext;

trait ServingContextFactory {

	protected function make_page( string $slug, int $parent_id, string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $status,
				'post_name'   => $slug,
				'post_parent' => $parent_id,
				'post_title'  => $slug,
			)
		);
	}

	/**
	 * @param array{max_depth?: int, host?: string} $overrides
	 */
	protected function serving_context( int $root_id, array $overrides = array() ): ServingContext {
		$host = $overrides['host'] ?? 'mapped.test';

		$mapping = new Mapping(
			1,
			$host,
			null,
			$root_id,
			1,
			VerificationState::VERIFIED,
			ActivationState::ACTIVE,
			SslState::NONE,
			null,
			str_repeat( 'a', 32 ),
			'_post-domain-challenge'
		);

		return new ServingContext(
			$mapping,
			$host,
			$host,
			true,
			$root_id,
			array( 'page' ),
			array( 'publish' ),
			$overrides['max_depth'] ?? 10,
			array( 'paged', 'page', 'cpage', 'replytocom', 'feed', 'embed' ),
			null,
			Representation::HTML
		);
	}
}
