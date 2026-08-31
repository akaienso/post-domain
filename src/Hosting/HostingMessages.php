<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

/**
 * The admin boundary: typed hosting results become sentences here and nowhere
 * else.
 *
 * Keeping the wording in one place is what stops a provider's own prose leaking
 * into a notice. Every string below is written by this plugin; none of them
 * interpolates a response body, an error message, or anything else the provider
 * said. Wordify's request id is the single exception, and it is a correlation
 * id that names no account, site or person.
 *
 * @package PostDomain
 */
final class HostingMessages {

	public static function for_connection( ConnectionResult $result ): string {
		$message = match ( $result->outcome ) {
			ConnectionOutcome::READY => sprintf(
				/* translators: %s: Wordify team name. */
				__( 'Connected to Wordify as team %s. Choose which site this WordPress installation is, below.', 'post-domain' ),
				null === $result->team ? '' : $result->team->label()
			),
			ConnectionOutcome::NO_CREDENTIAL => __( 'No Wordify token is configured, so there was nothing to test.', 'post-domain' ),
			ConnectionOutcome::REJECTED      => __( 'Wordify did not accept that token. Check that it has not been revoked, then paste a new one.', 'post-domain' ),
			ConnectionOutcome::NOT_PERMITTED => __( 'That token cannot read your sites. Give it the Read Sites ability in the Wordify console, and Manage Sites as well so domains can be attached later.', 'post-domain' ),
			ConnectionOutcome::NO_TEAM       => $result->needs_team()
				? __( 'That token works and can act for more than one Wordify team. Choose which one this WordPress installation belongs to.', 'post-domain' )
				: __( 'That token works, but it can act for no Wordify team, so there is nothing to bind. Check the token in the Wordify console.', 'post-domain' ),
			ConnectionOutcome::NO_SITES      => __( 'That token works, and the team has no sites to choose from.', 'post-domain' ),
			ConnectionOutcome::UNREACHABLE   => __( 'Wordify could not be reached, so nothing was changed. Try again shortly.', 'post-domain' ),
		};

		return self::with_reference( $message, $result->request_id );
	}

	/** What an administrator is told after a mapping's origin registration. */
	public static function for_registration( RegistrationOutcome $outcome, string $host ): string {
		return match ( $outcome->state ) {
			HostingRegistrationState::REGISTERED   => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%s was added and your hosting has been told to accept it.', 'post-domain' ),
				$host
			),
			HostingRegistrationState::ALREADY_MINE => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%s was added. Your hosting already accepted this domain.', 'post-domain' ),
				$host
			),
			HostingRegistrationState::UNSUPPORTED  => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%s was added. Set your web server up to accept it, as the guide describes.', 'post-domain' ),
				$host
			),
			HostingRegistrationState::FOREIGN      => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%s was added here, but your hosting reports it attached to a different site on the same account. Nothing was changed at your host, and this domain will not serve until that is resolved there.', 'post-domain' ),
				$host
			),
			HostingRegistrationState::AMBIGUOUS    => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%s was added here, but your hosting did not confirm it. Post Domain will keep checking and will not ask again. Do not add it by hand yet.', 'post-domain' ),
				$host
			),
			HostingRegistrationState::FENCED       => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%s was added here. Its hosting result arrived while the domain was being changed, so it was not recorded. Post Domain will settle it by checking, and will not ask your host again.', 'post-domain' ),
				$host
			),
			HostingRegistrationState::REFUSED      => sprintf(
				/* translators: %s: the mapped hostname. */
				__( '%1$s was added here, but your hosting refused to accept it, so it will not serve yet. %2$s', 'post-domain' ),
				$host,
				(string) $outcome->message
			),
		};
	}

	/**
	 * What deletion tells an operator about work only they can do.
	 *
	 * No detachment operation exists on the verified Wordify surface, so the
	 * hostname stays attached there. Saying so precisely — which hostname, which
	 * site — is the difference between a warning and a chore.
	 */
	public static function for_deletion( string $host, ?string $site_label ): string {
		return sprintf(
			/* translators: 1: the mapped hostname, 2: the Wordify site name. */
			__( 'The mapping for %1$s was deleted here. It is still attached to your Wordify site %2$s — Wordify offers no way to detach a domain through its API, so remove it in the Wordify console yourself.', 'post-domain' ),
			$host,
			null === $site_label || '' === $site_label ? __( 'this site', 'post-domain' ) : $site_label
		);
	}

	/** Appends the provider's correlation id, when there is one to quote. */
	private static function with_reference( string $message, ?string $request_id ): string {
		if ( null === $request_id ) {
			return $message;
		}

		return $message . ' ' . sprintf(
			/* translators: %s: an opaque request identifier from the hosting provider. */
			__( 'Wordify reference: %s.', 'post-domain' ),
			$request_id
		);
	}
}
