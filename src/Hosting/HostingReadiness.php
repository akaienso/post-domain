<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * Whether a domain may be added at all, and if not, what is missing.
 *
 * Adding a mapping on a Wordify site that the plugin cannot tell Wordify about
 * produces a domain that verifies, gets a certificate, and then serves the
 * host's placeholder — the exact failure this plugin already exists to make
 * visible. So the form is withheld until the connection is real, and the reason
 * is stated rather than implied by an absence.
 *
 * Existing mappings are unaffected by any of this. A credential that stops
 * working blocks new registrations; it does not stop a domain that already
 * serves from serving, and it never changes stored state.
 */
final class HostingReadiness {

	private function __construct(
		public readonly bool $may_add_domains,
		public readonly string $provider,
		public readonly ?string $blocker,
		public readonly ?string $remedy
	) {}

	public static function evaluate(): self {
		$provider = HostingDetection::selected();

		if ( HostingDetection::WORDIFY !== $provider ) {
			// Manual hosting asks nothing of the plugin: the operator arranges
			// their own origin, exactly as before.
			return new self( true, $provider, null, null );
		}

		$binding = HostingBinding::current();

		if ( ! $binding->has_credential() ) {
			return new self(
				false,
				$provider,
				__( 'This site is hosted on Wordify, and Post Domain has not been given a Wordify API token yet.', 'post-domain' ),
				__( 'Add a Wordify API token below, then choose Test connection. Until then a new domain could be set up here and still not reach this site.', 'post-domain' )
			);
		}

		if ( ! $binding->is_valid() ) {
			// A token that was never validated is not a token that stopped
			// working. Telling an operator mid-setup that their brand-new token
			// is broken sends them to replace something that is fine.
			$was_validated = null !== $binding->site_id;

			return new self(
				false,
				$provider,
				$was_validated
					? __( 'The Wordify connection needs to be confirmed again.', 'post-domain' )
					: __( 'The Wordify connection has not been confirmed yet.', 'post-domain' ),
				$was_validated
					? __( 'The API token, the site, or this installation changed, so the connection has to be proved again. Choose Test connection, then confirm which Wordify site this is. Domains already set up keep serving in the meantime.', 'post-domain' )
					: __( 'Choose Test connection, then choose which Wordify site this WordPress installation is.', 'post-domain' )
			);
		}

		if ( ! $binding->is_bound() ) {
			return new self(
				false,
				$provider,
				__( 'The Wordify connection is not yet bound to a single site.', 'post-domain' ),
				__( 'Choose which Wordify site this WordPress installation is, below.', 'post-domain' )
			);
		}

		return new self( true, $provider, null, null );
	}
}
