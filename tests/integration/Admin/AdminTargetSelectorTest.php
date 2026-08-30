<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Actions;
use PostDomain\Admin\RedirectedAway;
use PostDomain\Admin\Screen;
use PostDomain\Admin\SettingsPage;
use PostDomain\Application\MappingCommands;
use PostDomain\Mapping\DbRepository;
use PostDomain\Rest\Errors;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * Finding the right target on a site that has more content than fits on a page.
 *
 * The first version asked for 200 posts per type and rendered them all. On an
 * established site everything after the two-hundredth title was simply
 * unreachable, with nothing on screen to say so — the operator would conclude
 * the plugin could not map that page at all.
 */
final class AdminTargetSelectorTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_admin_target_post_types' );
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/** @param array<string, string> $query */
	private function page( array $query = array() ): string {
		$_GET = $query;

		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	/** More content than the old static window held, plus one findable needle. */
	private function seed_many(): int {
		for ( $i = 0; $i < 205; $i++ ) {
			self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => sprintf( 'Bulk filler %03d', $i ),
				)
			);
		}

		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Zebra pavilion',
			)
		);
	}

	public function test_a_target_beyond_the_old_cutoff_can_be_found_and_submitted(): void {
		$needle = $this->seed_many();

		// Searching is how an operator reaches it; the page never renders them all.
		$html = $this->page(
			array(
				'page'        => SettingsPage::SLUG,
				'pd_target_q' => 'Zebra pavilion',
			)
		);

		$this->assertStringContainsString( 'value="' . $needle . '"', $html );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'pd_action'  => 'pd_add_mapping',
			'pd_host'    => 'zebra.example',
			'pd_post_id' => $needle,
			'_wpnonce'   => wp_create_nonce( Actions::nonce_action( 'pd_add_mapping', 0 ) ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- assembling the request the handler verifies.

		add_filter( 'pd_admin_redirect_should_exit', '__return_false' );

		try {
			Actions::handle();
		} catch ( RedirectedAway $e ) {
			unset( $e );
		} finally {
			remove_filter( 'pd_admin_redirect_should_exit', '__return_false' );
		}

		$mapping = $this->repo->by_host( 'zebra.example' );

		$this->assertNotNull( $mapping, 'a target past the old cutoff must be usable' );
		$this->assertSame( $needle, $mapping->post_id );
	}

	public function test_the_page_never_renders_an_unbounded_number_of_options(): void {
		$this->seed_many();

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		preg_match_all( '/<option value="\d+"/', $html, $matches );

		$this->assertLessThanOrEqual(
			Screen::TARGETS_PER_PAGE,
			count( $matches[0] ),
			'one bounded page at a time, never every post on the site'
		);
	}

	public function test_truncation_is_stated_rather_than_silent(): void {
		$this->seed_many();

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		$this->assertStringContainsString( 'Showing', $html );
		$this->assertStringContainsString( 'Next', $html, 'the operator must be able to reach the rest' );
	}

	public function test_paging_reaches_content_the_first_page_omits(): void {
		$this->seed_many();

		$first  = $this->page( array( 'page' => SettingsPage::SLUG ) );
		$second = $this->page(
			array(
				'page'           => SettingsPage::SLUG,
				'pd_target_page' => '2',
			)
		);

		preg_match_all( '/<option value="(\d+)"/', $first, $a );
		preg_match_all( '/<option value="(\d+)"/', $second, $b );

		$this->assertNotEmpty( $b[1] );
		$this->assertSame(
			array(),
			array_intersect( $a[1], $b[1] ),
			'the second page must show different content, not the same window again'
		);
	}

	public function test_a_search_with_no_matches_says_so(): void {
		$this->seed_many();

		$html = $this->page(
			array(
				'page'        => SettingsPage::SLUG,
				'pd_target_q' => 'nothing matches this string at all',
			)
		);

		$this->assertStringContainsString( 'Nothing matched that search', $html );
		$this->assertStringNotContainsString( '<select name="pd_post_id"', $html );
	}

	public function test_the_selector_works_without_javascript(): void {
		$this->seed_many();

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		$this->assertStringNotContainsString( '<script', $html, 'no JavaScript-only path' );
		$this->assertMatchesRegularExpression( '/<form[^>]*method="get"/', $html, 'search is a plain GET form' );
		$this->assertStringContainsString( 'aria-describedby="pd_post_id_help"', $html );
		$this->assertStringContainsString( '<label for="pd_post_id">', $html );
		$this->assertStringContainsString( '<label for="pd_target_q">', $html );
	}

	public function test_every_declared_post_type_is_searchable(): void {
		register_post_type(
			'pd_venue',
			array(
				'public' => true,
				'label'  => 'Venue',
			)
		);

		$venue = self::factory()->post->create(
			array(
				'post_type'   => 'pd_venue',
				'post_status' => 'publish',
				'post_title'  => 'Aardvark hall',
			)
		);

		$html = $this->page(
			array(
				'page'        => SettingsPage::SLUG,
				'pd_target_q' => 'Aardvark hall',
			)
		);

		$this->assertStringContainsString( 'value="' . $venue . '"', $html );

		unregister_post_type( 'pd_venue' );
	}

	// -- server-side validation ---------------------------------------------

	public function test_a_submitted_target_is_validated_again_on_the_server(): void {
		$commands = MappingCommands::production( $this->repo );

		$this->assertTrue(
			$commands->create_mapping( 'unknown-target.example', null, 987654 )->refused_as( Errors::POST_INVALID )
		);
	}

	public function test_a_post_type_outside_the_allowed_list_is_refused(): void {
		register_post_type(
			'pd_secret_type',
			array(
				'public' => false,
				'label'  => 'Secret',
			)
		);

		$hidden = self::factory()->post->create(
			array(
				'post_type'   => 'pd_secret_type',
				'post_status' => 'publish',
				'post_title'  => 'Not mappable',
			)
		);

		$commands = MappingCommands::production( $this->repo );
		$result   = $commands->create_mapping( 'hidden.example', null, $hidden );

		$this->assertTrue(
			$result->refused_as( Errors::POST_INVALID ),
			'a private post type is not a valid target just because an id was posted'
		);
		$this->assertNull( $this->repo->by_host( 'hidden.example' ) );

		unregister_post_type( 'pd_secret_type' );
	}

	public function test_content_the_operator_cannot_read_is_never_offered(): void {
		$private = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_title'  => 'Kestrel private plan',
				'post_author' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$candidates = Screen::target_candidates( 'Kestrel private plan', 1 );
		$ids        = array_map( static fn( \WP_Post $p ): int => $p->ID, $candidates['posts'] );

		// An editor may read others' private pages; a subscriber may not. The
		// point is that the list is filtered by capability at all.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$as_subscriber = Screen::target_candidates( 'Kestrel private plan', 1 );

		$this->assertNotContains(
			$private,
			array_map( static fn( \WP_Post $p ): int => $p->ID, $as_subscriber['posts'] ),
			'a selector must not list content its viewer cannot read'
		);

		unset( $ids );
	}
}
