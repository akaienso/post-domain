<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

/**
 * Bounded, authorized, paged search behind the content combobox.
 *
 * The search runs on the server for two reasons. An established site cannot
 * send every post to the browser, and the browser is not allowed to decide what
 * the operator may read: `perm => readable` puts that in the query, so the
 * results, the total and the paging all describe the same readable set. A
 * filtered-after-the-fact list once told an operator their site had no content
 * because the first page happened to be private.
 *
 * Each response stays bounded, but it is no longer the last word. It carries
 * the page it answers, the readable total, and whether another page exists —
 * so the combobox can walk the whole readable set instead of stopping silently
 * at the twentieth match, which made every similarly-titled item after it
 * unreachable with nothing on screen to say so.
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

		// `pd_page` rather than `page`, which means something else everywhere
		// else in wp-admin and would be read by the wrong eyes in a bug report.
		$page = isset( $_GET['pd_page'] ) ? absint( wp_unslash( $_GET['pd_page'] ) ) : 1;

		wp_send_json_success( self::search( $search, $page ) );
	}

	/**
	 * One page of matches, plus what the caller needs to ask for the next one.
	 *
	 * `more` comes from the query's own page count, never from counting the rows
	 * that survived rendering: the count and the list have to describe the same
	 * readable set, or the last page of a search looks like the end of the site.
	 *
	 * @return array{results: array<int, array{id: int, title: string, type: string}>, page: int, more: bool, total: int}
	 */
	public static function search( string $search, int $page = 1 ): array {
		$page  = max( 1, $page );
		$found = Screen::target_candidates( $search, $page, self::LIMIT );
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

		return array(
			'results' => $out,
			'page'    => $page,
			'more'    => $page < (int) $found['pages'],
			// Already the readable total: `perm => readable` is in the query, so
			// this never counts a post whose title we would refuse to send.
			'total'   => (int) $found['total'],
		);
	}

	/**
	 * The rows of one page, for callers that only want the list.
	 *
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	public static function results( string $search, int $page = 1 ): array {
		return self::search( $search, $page )['results'];
	}
}
