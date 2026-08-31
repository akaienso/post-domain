<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * A guess at which hosting platform this installation runs on.
 *
 * Advisory, and only ever advisory. Detection chooses which setup path to show
 * first; it authorizes nothing, and an administrator can always overrule it. A
 * hostname containing "wordify" is the weakest possible signal — a customer's
 * own domain will not contain it, and someone else's might — so it is one
 * signal among several and never sufficient on its own.
 */
final class HostingDetection {

	public const WORDIFY = 'wordify';
	public const MANUAL  = 'manual';

	/**
	 * @return array{provider: string, confident: bool, signals: string[]}
	 */
	public static function detect(): array {
		$signals = array();

		// A platform marker placed by the host itself. The strongest signal
		// available, because nothing else writes it.
		if ( defined( 'WORDIFY_SITE_ID' ) || defined( 'WORDIFY' ) ) {
			$signals[] = 'platform_constant';
		}

		if ( '' !== (string) getenv( 'WORDIFY_SITE_ID' ) ) {
			$signals[] = 'platform_environment';
		}

		// The provisioning subdomain a site is created on. Present on a fresh
		// site, usually gone once a real domain is primary, so its absence
		// proves nothing.
		$host = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );

		if ( '' !== $host && ( str_ends_with( $host, '.wordifysites.com' ) || str_ends_with( $host, '.wordify.io' ) ) ) {
			$signals[] = 'provisioning_hostname';
		}

		// A filter so a host, or an operator who knows better, can say outright.
		/** @var string|null $declared */
		$declared = apply_filters( 'pd_hosting_platform', null );

		if ( is_string( $declared ) && '' !== $declared ) {
			return array(
				'provider'  => $declared,
				'confident' => true,
				'signals'   => array( 'declared_by_filter' ),
			);
		}

		return array(
			'provider'  => array() === $signals ? self::MANUAL : self::WORDIFY,
			// One weak signal is a suggestion; a platform marker is evidence.
			'confident' => in_array( 'platform_constant', $signals, true )
				|| in_array( 'platform_environment', $signals, true ),
			'signals'   => $signals,
		);
	}

	/**
	 * What the operator chose, falling back to what was detected.
	 *
	 * An explicit choice is returned verbatim, including one this class has
	 * never heard of — a third party may register its own provider. Deciding
	 * that an unrecognised name means "manual" here is exactly the silent
	 * fall-through the factory exists to refuse: it would quietly hand a site to
	 * the do-nothing provider and let a domain be added that nothing routes.
	 * Whether a name resolves is the factory's question, and it answers it by
	 * refusing rather than substituting.
	 */
	public static function selected(): string {
		$settings = get_option( 'pd_settings', array() );
		$chosen   = is_array( $settings ) ? ( $settings['hosting_provider'] ?? null ) : null;

		if ( is_string( $chosen ) && '' !== $chosen ) {
			return $chosen;
		}

		return self::detect()['provider'];
	}

	public static function is_chosen_explicitly(): bool {
		$settings = get_option( 'pd_settings', array() );

		return is_array( $settings ) && isset( $settings['hosting_provider'] );
	}
}
