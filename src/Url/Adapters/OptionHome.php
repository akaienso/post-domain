<?php
declare( strict_types = 1 );

namespace PostDomain\Url\Adapters;

use PostDomain\Routing\ContextHolder;

/**
 * Opt-in and default off. It fires for everything that reads the option,
 * including code paths that must stay on the primary host, which is the classic
 * way to corrupt cron and email.
 */
final class OptionHome {

	public function __construct( private readonly ContextHolder $context ) {}

	public function register(): void {
		if ( ! (bool) apply_filters( 'pd_filter_home_option', false ) ) {
			return;
		}

		add_filter( 'pre_option_home', array( $this, 'filter_home' ) );
	}

	/**
	 * @param mixed $value The short-circuit value: false unless another filter acted.
	 * @return mixed
	 */
	public function filter_home( $value ) {
		$serving = $this->context->serving();

		if ( null === $serving || is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $value;
		}

		return 'https://' . $serving->requested_host;
	}
}
