<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

/**
 * Carries one message across the redirect that follows a mutation.
 *
 * Every admin mutation answers with a redirect so a browser refresh re-runs a
 * GET rather than the POST (Post/Redirect/Get). The notice therefore cannot
 * live in the response; it is held per user for one page load.
 */
final class Notices {

	private const KEY = 'pd_admin_notice_';

	public static function success( string $message ): void {
		self::store( 'success', $message );
	}

	public static function failure( string $message ): void {
		self::store( 'error', $message );
	}

	/**
	 * Something happened, and it is not finished.
	 *
	 * Distinct from both: an accepted-but-unconfirmed hosting attachment is not
	 * a failure, and painting it green would tell an operator the origin has
	 * accepted a hostname nothing has confirmed.
	 */
	public static function pending( string $message ): void {
		self::store( 'warning', $message );
	}

	/** @return array{type: string, message: string}|null */
	public static function take(): ?array {
		$key    = self::KEY . get_current_user_id();
		$notice = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return null;
		}

		return array(
			'type'    => (string) $notice['type'],
			'message' => (string) $notice['message'],
		);
	}

	public static function render(): string {
		$notice = self::take();

		if ( null === $notice ) {
			return '';
		}

		return sprintf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	private static function store( string $type, string $message ): void {
		set_transient(
			self::KEY . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}
}
