<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class FeedLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'feed_link', array( $this, 'rebase' ) );
		add_filter( 'post_comments_feed_link', array( $this, 'rebase' ) );
	}

	public function rebase( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::FEED );
	}
}
