<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

/**
 * Bounded, authorized search behind the content combobox.
 *
 * The search runs on the server for two reasons. An established site cannot
 * send every post to the browser, and the browser is not allowed to decide what
 * the operator may read: `perm => readable` puts that in the query, so the
 * results, the total and the paging all describe the same readable set. A
 * filtered-after-the-fact list once told an operator their site had no content
 * because the first page happened to be private.
 */
final class TargetSearch {

	public const ACTION = 'pd_search_targets';

	/** Never more than this in one response, whatever is asked for. */
	public const LIMIT = 20;

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'respond' ) );
	}

	public static function nonce_action(): string {
		return self::ACTION;
	}

	public static function respond(): void {
		if ( ! check_ajax_referer( self::nonce_action(), 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That request could not be verified. Reload the page.', 'post-domain' ) ), 403 );
		}

		if ( ! current_user_can( Actions::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage domain mappings.', 'post-domain' ) ), 403 );
		}

		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		wp_send_json_success( array( 'results' => self::results( $search ) ) );
	}

	/**
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	public static function results( string $search ): array {
		$found = Screen::target_candidates( $search, 1, self::LIMIT );
		$out   = array();

		foreach ( $found['posts'] as $post ) {
			$type = get_post_type_object( $post->post_type );

			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => '' === trim( $post->post_title )
					? sprintf( /* translators: %d: post id. */ __( '(no title) #%d', 'post-domain' ), $post->ID )
					: $post->post_title,
				'type'  => (string) ( $type?->labels->singular_name ?? $post->post_type ),
			);
		}

		return $out;
	}
}
