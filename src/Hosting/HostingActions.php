<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Admin\Notices;

/**
 * The hosting half of the settings screen's POST handling.
 *
 * Reached only from `Admin\Actions::dispatch()`, which has already verified the
 * nonce for this exact action and the administrator's capability. Nothing here
 * re-decides authorization; it decides what the action means.
 */
final class HostingActions {

	public static function dispatch( string $action ): void {
		match ( $action ) {
			'pd_set_hosting'         => self::set_provider(),
			'pd_set_wordify_token'   => self::set_token(),
			'pd_test_wordify'        => self::test_connection(),
			'pd_select_wordify_team' => self::select_team(),
			'pd_clear_wordify_team'  => self::clear_team(),
			'pd_select_wordify_site' => self::select_site(),
			'pd_disconnect_wordify'  => self::disconnect(),
			default                  => null,
		};
	}

	private static function set_provider(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Admin\Actions::handle() verified the nonce for this action before dispatching.
		$requested = isset( $_POST['pd_hosting_provider'] ) ? sanitize_key( wp_unslash( $_POST['pd_hosting_provider'] ) ) : '';

		if ( ! in_array( $requested, array( HostingDetection::WORDIFY, HostingDetection::MANUAL ), true ) ) {
			Notices::failure( __( 'That is not a hosting provider Post Domain supports.', 'post-domain' ) );

			return;
		}

		$settings = get_option( 'pd_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['hosting_provider'] = $requested;

		update_option( 'pd_settings', $settings, false );

		Notices::success(
			HostingDetection::WORDIFY === $requested
				? __( 'Hosting set to Wordify. Add an API token to finish connecting.', 'post-domain' )
				: __( 'Hosting set to manual. Post Domain will not contact a hosting API.', 'post-domain' )
		);
	}

	private static function set_token(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified before dispatch.
		$token = isset( $_POST['pd_wordify_token'] ) ? trim( (string) wp_unslash( $_POST['pd_wordify_token'] ) ) : '';

		if ( '' === $token ) {
			Notices::failure( __( 'Enter a Wordify API token.', 'post-domain' ) );

			return;
		}

		$stored = apply_filters( 'pd_hosting_store_credential', null, $token );

		// The token is not echoed back, not logged, and not included in any
		// failure message — only whether storing it worked.
		unset( $token );

		if ( true !== $stored ) {
			Notices::failure(
				__( 'That token could not be stored securely, so nothing was saved. Check that your site can encrypt secrets.', 'post-domain' )
			);

			return;
		}

		// A new credential is not a validated connection. The site choice is
		// kept; the authority to act on it is not, until it is proved again.
		HostingBinding::invalidate();

		Notices::success( __( 'Token saved. Choose Test connection to validate it.', 'post-domain' ) );
	}

	/**
	 * Reads the connection and remembers what it found, without binding.
	 *
	 * A passing test proves the token works. It does not decide which Wordify
	 * site this installation is, and it never writes a valid binding — that is
	 * `select_site()` below, after the operator has said which site and the
	 * plugin has read that exact site back.
	 */
	private static function test_connection(): void {
		/** @var mixed $result */
		$result = apply_filters( 'pd_hosting_test_connection', null );

		// An answer that is not the agreed shape is not an answer. With the
		// production listener registered this is unreachable, and it stays as a
		// floor rather than as an expected state.
		if ( ! is_array( $result ) || ! array_key_exists( 'ok', $result ) || ! array_key_exists( 'message', $result ) ) {
			Notices::failure(
				__( 'The Wordify connection could not be tested on this site.', 'post-domain' )
			);

			return;
		}

		if ( true === $result['ok'] ) {
			Notices::success( (string) $result['message'] );

			return;
		}

		Notices::failure( (string) $result['message'] );
	}

	/**
	 * Puts the operator back at the team choice.
	 *
	 * A separate action from `select_team()` rather than an empty team id posted
	 * to it: "which team" and "none yet" are different requests, and a blank
	 * value arriving at the selector is a mistake worth rejecting. Rendering the
	 * change-team control as an empty selection made the one legitimate way to
	 * change team indistinguishable from that mistake.
	 *
	 * Clearing is purely local. Nothing is detached, nothing at Wordify is
	 * asked, and the site chosen under the old team goes with it, because a site
	 * in one team is not a site in another. Validation goes through the same
	 * safe path everything else does — `store()` cannot write it back.
	 */
	private static function clear_team(): void {
		HostingBinding::store(
			array(
				'team_id'     => null,
				'team_name'   => null,
				'site_id'     => null,
				'site_name'   => null,
				'site_domain' => null,
			)
		);

		HostingProviderFactory::reset();

		Notices::success(
			__( 'Cleared. Choose which Wordify team this WordPress installation belongs to.', 'post-domain' )
		);
	}

	/**
	 * Records which Wordify team the operator is working in.
	 *
	 * Only a team the authenticated `GET /me` named may be chosen — a posted id
	 * is caller input, and a team the token cannot act for is refused rather
	 * than stored and discovered later. The choice is ordinary configuration and
	 * confers no authority: it goes through `store()`, which cannot make a
	 * binding valid, so the site still has to be chosen and read back.
	 */
	private static function select_team(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Admin\Actions::handle() verified the nonce for this action before dispatching.
		$team_id = isset( $_POST['pd_wordify_team'] ) ? sanitize_text_field( wp_unslash( $_POST['pd_wordify_team'] ) ) : '';

		if ( '' === $team_id ) {
			Notices::failure( __( 'Choose which Wordify team this WordPress installation belongs to.', 'post-domain' ) );

			return;
		}

		// Read the account with no preference, so the answer is the whole set of
		// teams this token can act for rather than a confirmation of the guess.
		$account = WordifyConnectionService::production()->account();

		if ( $account instanceof WordifyFailure ) {
			Notices::failure(
				__( 'Wordify could not be read with this token, so no team was chosen.', 'post-domain' )
			);

			return;
		}

		$team = $account->team( $team_id );

		if ( null === $team ) {
			Notices::failure(
				__( 'That Wordify team is not one this token can act for, so nothing was changed.', 'post-domain' )
			);

			return;
		}

		// A different team means a different set of sites, so any site already
		// chosen under the previous team stops being the site.
		HostingBinding::store(
			array(
				'team_id'   => $team->id,
				'team_name' => $team->name,
				'site_id'   => null,
				'site_name' => null,
			)
		);

		HostingProviderFactory::reset();

		Notices::success(
			sprintf(
				/* translators: %s: Wordify team name. */
				__( 'Working in Wordify team %s. Now choose which site this WordPress installation is.', 'post-domain' ),
				$team->label()
			)
		);
	}

	/**
	 * Binds this installation to exactly one Wordify site.
	 *
	 * The posted id is treated as caller input, not as proof: the site is read
	 * back with the credential, in the team it is claimed to be in, and the
	 * binding is written only if that read succeeds. A matching domain string is
	 * never enough on its own — an operator can own the same name on two sites,
	 * and choosing the wrong one silently sends a domain to the wrong install.
	 */
	private static function select_site(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Admin\Actions::handle() verified the nonce for this action before dispatching.
		$site_id  = isset( $_POST['pd_wordify_site'] ) ? sanitize_text_field( wp_unslash( $_POST['pd_wordify_site'] ) ) : '';
		$team_id  = isset( $_POST['pd_wordify_team'] ) ? sanitize_text_field( wp_unslash( $_POST['pd_wordify_team'] ) ) : '';
		$confirms = isset( $_POST['pd_wordify_confirm'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $site_id || '' === $team_id ) {
			Notices::failure( __( 'Choose which Wordify site this WordPress installation is.', 'post-domain' ) );

			return;
		}

		if ( ! $confirms ) {
			// An explicit statement, because the consequence of getting it wrong
			// is a mapped domain pointed at somebody else's site.
			Notices::failure(
				__( 'Confirm that the site you chose is this WordPress installation before binding it.', 'post-domain' )
			);

			return;
		}

		$service    = WordifyConnectionService::production( $team_id );
		$connection = $service->probe( $team_id );

		if ( ! $connection->is_ready() || null === $connection->team ) {
			Notices::failure( HostingMessages::for_connection( $connection ) );

			return;
		}

		if ( ! hash_equals( $connection->team->id, $team_id ) ) {
			// The token can no longer act for the team the form was drawn for.
			Notices::failure(
				__( 'That Wordify team is no longer available to this token. Test the connection again.', 'post-domain' )
			);

			return;
		}

		// The confirming read: addressing the resource, not just finding it in a
		// collection. This is the authority a binding claims to have.
		$site = $service->confirm_site( $connection->team, $site_id );

		if ( $site instanceof WordifyFailure ) {
			Notices::failure(
				__( 'That Wordify site could not be read with this token, so nothing was bound.', 'post-domain' )
			);

			return;
		}

		if ( ! hash_equals( $site->id, $site_id ) ) {
			Notices::failure( __( 'Wordify answered with a different site, so nothing was bound.', 'post-domain' ) );

			return;
		}

		if ( ! HostingBinding::bind( $connection->team, $site ) ) {
			Notices::failure(
				__( 'The Wordify token could not be read back, so the connection was not bound.', 'post-domain' )
			);

			return;
		}

		HostingProviderFactory::reset();

		Notices::success(
			sprintf(
				/* translators: 1: Wordify site name, 2: Wordify team name. */
				__( 'Connected to Wordify site %1$s in team %2$s. You can now add a domain.', 'post-domain' ),
				$site->label(),
				$connection->team->label()
			)
		);
	}

	/**
	 * Removes local authority only.
	 *
	 * Nothing is detached at Wordify and no mapping is deleted: a domain that is
	 * serving keeps serving. All this withdraws is the plugin's permission to
	 * make further changes on the operator's behalf.
	 */
	private static function disconnect(): void {
		do_action( 'pd_hosting_forget_credential' );

		HostingBinding::forget();

		Notices::success(
			__( 'Wordify disconnected. No domains were detached and no mappings were deleted.', 'post-domain' )
		);
	}
}
