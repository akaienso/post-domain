<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class SitemapLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'wp_sitemaps_index_entry', array( $this, 'filter_entry' ) );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_entry' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public function filter_entry( array $entry ): array {
		$serving = $this->context->serving();

		if ( null === $serving || ! isset( $entry['loc'] ) ) {
			return $entry;
		}

		$entry['loc'] = $this->policy->rebase( (string) $entry['loc'], $serving, UrlKind::SITEMAP );

		return $entry;
	}
}
