<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Application\CommandResult;
use PostDomain\Application\MappingCommands;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;

/**
 * Every admin mutation, in one place, behind one set of checks.
 *
 * The order is deliberate and each step is a refusal, not a warning: the
 * request must be a POST, carry the nonce minted for that exact action and
 * mapping, come from a user with the capability, and name the revision the page
 * was drawn from. Only then does it reach `MappingCommands`, which is the same
 * code the REST API runs — so an admin button cannot do anything a REST call
 * could not, and cannot skip a check a REST call would have made.
 *
 * Every path ends in a redirect. A refresh then repeats a GET rather than the
 * mutation (Post/Redirect/Get).
 */
final class Actions {

	public const ACTIONS = array(
		'pd_add_mapping',
		'pd_set_driver',
		'pd_activate',
		'pd_deactivate',
		'pd_verify',
		'pd_rotate_challenge',
		'pd_provision_ssl',
		'pd_remove_ssl',
		'pd_delete_mapping',
	);

	public static function capability(): string {
		$capability = (string) apply_filters( 'pd_rest_capability', 'manage_options', 'admin' );

		return '' === $capability ? 'manage_options' : $capability;
	}

	/** The nonce is bound to the action and the row it acts on. */
	public static function nonce_action( string $action, int $mapping_id = 0 ): string {
		return $action . ':' . $mapping_id;
	}

	/** The `admin_init` callback. An action callback returns nothing. */
	public static function on_admin_init(): void {
		self::handle();
	}

	/**
	 * Handles a posted action, if there is one. Returns true when it redirected
	 * (or would have), so the caller knows not to render.
	 */
	public static function handle(): bool {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return false;
		}

		$action = isset( $_POST['pd_action'] ) ? sanitize_key( wp_unslash( $_POST['pd_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this action is checked immediately below, and the action name is what selects which nonce to check.

		if ( '' === $action || ! in_array( $action, self::ACTIONS, true ) ) {
			return false;
		}

		$mapping_id = isset( $_POST['pd_mapping'] ) ? absint( wp_unslash( $_POST['pd_mapping'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same: the id selects the nonce that is then verified.

		// Nonce first, and specific to this action and row: a nonce for one
		// button must not authorize another.
		check_admin_referer( self::nonce_action( $action, $mapping_id ) );

		if ( ! current_user_can( self::capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage domain mappings.', 'post-domain' ),
				'',
				array( 'response' => 403 )
			);
		}

		self::dispatch( $action, $mapping_id );

		return true;
	}

	/**
	 * Reached only after `handle()` has verified the nonce and the capability.
	 *
	 * phpcs cannot follow that across a method boundary, so every superglobal
	 * read below carries an ignore naming where the check actually happened.
	 */
	private static function dispatch( string $action, int $mapping_id ): void {
		$repo     = new DbRepository();
		$commands = MappingCommands::production( $repo );

		if ( 'pd_set_driver' === $action ) {
			self::set_driver();
			self::redirect( 0 );
		}

		if ( 'pd_add_mapping' === $action ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() verified the nonce for this action before dispatching.
			$host = isset( $_POST['pd_host'] ) ? sanitize_text_field( wp_unslash( $_POST['pd_host'] ) ) : '';
			$post = isset( $_POST['pd_post_id'] ) ? absint( wp_unslash( $_POST['pd_post_id'] ) ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$result = $commands->create_mapping( $host, null, 0 === $post ? null : $post );

			self::report(
				$result,
				__( 'Domain added. Publish its verification record, then check verification.', 'post-domain' )
			);
			self::redirect( $result->succeeded ? (int) $result->mapping?->id : 0 );
		}

		$mapping = $repo->by_id( $mapping_id );

		if ( null === $mapping ) {
			Notices::failure( __( 'That mapping no longer exists.', 'post-domain' ) );
			self::redirect( 0 );
		}

		// Optimistic concurrency. The page rendered a revision; if the row moved
		// since, the operator is acting on something they never saw.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce for this action before dispatching.
		$revision = isset( $_POST['pd_revision'] ) ? absint( wp_unslash( $_POST['pd_revision'] ) ) : null;
		$stale    = $commands->at_revision( $mapping, $revision );

		if ( null !== $stale ) {
			Notices::failure( (string) $stale->message );
			self::redirect( $mapping->id );
		}

		$result = self::run( $commands, $action, $mapping );

		self::report( $result, self::success_message( $action, $result ) );

		// Where to go next is decided by what happened, not by what was asked.
		// A removal that is merely scheduled leaves the row in place, and sending
		// the operator to a list that still shows it — after telling them it was
		// removed — is how the interface lies about durable deletion.
		self::redirect( self::destination( $mapping, $result ) );
	}

	/**
	 * The list only when the row is actually gone; otherwise the row itself.
	 *
	 * `MappingCommands::delete()` answers 204 with no mapping for a local delete
	 * and 202 with a surviving mapping when §14.15 has scheduled provider
	 * cleanup. Both are successes and they are not the same outcome.
	 */
	private static function destination( Mapping $mapping, CommandResult $result ): int {
		if ( $result->succeeded && null === $result->mapping ) {
			return 0;
		}

		return $mapping->id;
	}

	private static function run( MappingCommands $commands, string $action, Mapping $mapping ): CommandResult {
		return match ( $action ) {
			'pd_activate'         => $commands->set_activation( $mapping, ActivationState::ACTIVE ),
			'pd_deactivate'       => $commands->set_activation( $mapping, ActivationState::INACTIVE ),
			'pd_verify'           => $commands->verify_now( $mapping ),
			'pd_rotate_challenge' => $commands->rotate_challenge( $mapping ),
			'pd_provision_ssl'    => $commands->provision_ssl( $mapping ),
			'pd_remove_ssl'       => $commands->remove_ssl( $mapping ),
			'pd_delete_mapping'   => $commands->delete( $mapping ),
			default               => CommandResult::refused( 'pd_conflict', __( 'Unknown action.', 'post-domain' ), 400 ),
		};
	}

	/**
	 * What to tell the operator, from what actually happened.
	 *
	 * Two removals report completion, and neither is always complete when the
	 * command returns. Durable deletion (§14.15) answers 202 with the row still
	 * present while provider cleanup is scheduled, and an SSL-resource removal
	 * answers 202 for a pending, transient or failed provider outcome that a
	 * later sweep will retry. Choosing the message from the action name alone
	 * announces both as finished, which is the one thing an operator must not be
	 * told about a deletion that has not happened.
	 *
	 * 202 is the shared signal: accepted, not completed.
	 */
	private static function success_message( string $action, CommandResult $result ): string {
		$outstanding = 202 === $result->status;

		if ( 'pd_delete_mapping' === $action ) {
			return $outstanding
				? __( 'Removal has started. The domain stops serving now, and disappears once its certificate is released.', 'post-domain' )
				: __( 'The domain was removed.', 'post-domain' );
		}

		if ( 'pd_remove_ssl' === $action ) {
			return $outstanding
				? __( 'Certificate removal has started. It finishes once the provider confirms.', 'post-domain' )
				: __( 'The certificate was removed. The domain mapping is unchanged.', 'post-domain' );
		}

		return match ( $action ) {
			'pd_activate'         => __( 'This domain is now serving.', 'post-domain' ),
			'pd_deactivate'       => __( 'This domain has stopped serving.', 'post-domain' ),
			'pd_verify'           => __( 'Verification was requested. The result appears here shortly.', 'post-domain' ),
			'pd_rotate_challenge' => __( 'A new verification record was issued. Publish it, then check verification.', 'post-domain' ),
			'pd_provision_ssl'    => __( 'A certificate was requested. Publish any records shown below.', 'post-domain' ),
			default               => __( 'Done.', 'post-domain' ),
		};
	}

	private static function report( CommandResult $result, string $success ): void {
		if ( $result->succeeded ) {
			Notices::success( $success );

			return;
		}

		Notices::failure( (string) $result->message );
	}

	/** The certificate provider selection, which is a setting rather than a mapping. */
	private static function set_driver(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() verified the nonce for this action before dispatching.
		$requested = isset( $_POST['pd_ssl_driver'] )
			? sanitize_text_field( wp_unslash( $_POST['pd_ssl_driver'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A closed list drawn from the registry: an operator cannot name a driver
		// that does not exist and then wonder why nothing provisions.
		if ( ! in_array( $requested, \PostDomain\Ssl\DriverFactory::registry()->ids(), true ) ) {
			Notices::failure( __( 'That certificate provider is not available.', 'post-domain' ) );

			return;
		}

		$settings = get_option( 'pd_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['ssl_driver'] = $requested;

		update_option( 'pd_settings', $settings, false );

		// The registry is memoized per request; the next one must see this.
		\PostDomain\Ssl\DriverFactory::reset();

		Notices::success(
			sprintf(
				/* translators: %s: the provider name. */
				__( 'Certificate provider set to %s.', 'post-domain' ),
				Labels::driver( $requested )
			)
		);
	}

	/**
	 * Post/Redirect/Get. Exits, so a refresh cannot repeat the mutation.
	 *
	 * `wp_safe_redirect` refuses an off-site target, and the URL is built here
	 * from a known page slug and an integer rather than from anything posted.
	 */
	private static function redirect( int $mapping_id ): never {
		$url = admin_url( 'options-general.php?page=' . SettingsPage::SLUG );

		if ( $mapping_id > 0 ) {
			$url .= '&mapping=' . $mapping_id;
		}

		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
		}

		// Tests substitute this so a redirect ends the action without ending the
		// process; in a request it is `exit`.
		do_action( 'pd_admin_redirected', $url );

		if ( ! (bool) apply_filters( 'pd_admin_redirect_should_exit', true ) ) {
			throw new RedirectedAway( $url );
		}

		exit;
	}
}
