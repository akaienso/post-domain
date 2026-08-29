<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;
use PostDomain\Url\UrlKind;
use PostDomain\Url\UrlPolicy;

final class EmbedLinks {

	public function __construct(
		private readonly ContextHolder $context,
		private readonly UrlPolicy $policy
	) {}

	public function register(): void {
		add_filter( 'oembed_response_data', array( $this, 'filter_response' ) );
		add_filter( 'embed_html', array( $this, 'filter_html' ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function filter_response( array $data ): array {
		$serving = $this->context->serving();

		if ( null === $serving ) {
			return $data;
		}

		foreach ( array( 'url', 'provider_url' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$data[ $key ] = $this->policy->rebase( $data[ $key ], $serving, UrlKind::EMBED );
			}
		}

		return $data;
	}

	public function filter_html( string $html ): string {
		$serving = $this->context->serving();

		if ( null === $serving ) {
			return $html;
		}

		return str_replace(
			'https://' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'https://' . $serving->requested_host,
			$html
		);
	}
}
