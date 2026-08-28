<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class CommentLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'comment_form_defaults', array( $this, 'filter_defaults' ) );
		add_filter( 'comment_post_redirect', array( $this, 'filter_redirect' ) );
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	public function filter_defaults( array $defaults ): array {
		$serving = $this->context->serving();

		if ( null === $serving || ! isset( $defaults['action'] ) ) {
			return $defaults;
		}

		$defaults['action'] = $this->policy->rebase( (string) $defaults['action'], $serving, UrlKind::COMMENT );

		return $defaults;
	}

	public function filter_redirect( string $url ): string {
		$serving = $this->context->serving();

		return null === $serving ? $url : $this->policy->rebase( $url, $serving, UrlKind::COMMENT );
	}
}
