<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Actions;
use PostDomain\Admin\RedirectedAway;
use PostDomain\Admin\Screen;
use PostDomain\Admin\TargetSearch;
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

	private string $probe_type = '';

	private static int $probes = 0;

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
		remove_all_filters( 'pd_rest_capability' );
		remove_role( 'pd_manager' );
		if ( '' !== $this->probe_type ) {
			unregister_post_type( $this->probe_type );
			$this->probe_type = '';
		}
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

		// The page never renders them all; the combobox reaches the rest through
		// the same bounded server-side search the operator types into.
		$hits = TargetSearch::results( 'Zebra pavilion' );
		$ids  = array_column( $hits, 'id' );

		$this->assertContains( $needle, $ids, 'a target past the old cutoff must be findable' );

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






	// -- server-side validation ---------------------------------------------

	public function test_a_submitted_target_is_validated_again_on_the_server(): void {
		$commands = MappingCommands::production( $this->repo );

		$this->assertTrue(
			$commands->create_mapping( 'unknown-target.example', null, 987654 )->refused_as( Errors::POST_INVALID )
		);
	}

	/**
	 * The selector's list is presentation; it is not the rule.
	 *
	 * An earlier version of this test asserted the opposite, because the shared
	 * command consulted `pd_admin_target_post_types`. That narrowed the REST
	 * contract: the specification supports mapping a domain to a private,
	 * non-REST custom post type, and v1.0.0 accepted one.
	 */
	public function test_a_type_the_selector_never_offers_is_still_a_valid_target(): void {
		register_post_type(
			'pd_hidden_type',
			array(
				'public' => false,
				'label'  => 'Hidden',
			)
		);

		$hidden = self::factory()->post->create(
			array(
				'post_type'   => 'pd_hidden_type',
				'post_status' => 'publish',
				'post_title'  => 'Not offered, still mappable',
			)
		);

		$this->assertNotContains(
			'pd_hidden_type',
			Screen::target_post_types(),
			'the selector does not offer a non-public type'
		);

		$result = MappingCommands::production( $this->repo )->create_mapping( 'hidden.example', null, $hidden );

		$this->assertTrue( $result->succeeded, 'and the command still accepts it, as it did in v1.0.0' );
		$this->assertNotNull( $this->repo->by_host( 'hidden.example' ) );

		unregister_post_type( 'pd_hidden_type' );
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

	public function test_the_control_says_how_much_it_is_showing(): void {
		$this->seed_many();

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		$this->assertStringContainsString( 'Showing the most recent', $html );
		$this->assertStringContainsString( 'Type to search the rest', $html );
	}

	public function test_there_is_one_content_control_and_no_separate_search_form(): void {
		$this->seed_many();

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		$this->assertStringNotContainsString( 'pd_target_q', $html, 'no separate search field' );
		$this->assertStringNotContainsString( 'value="Search"', $html, 'no separate Search button' );
		$this->assertStringNotContainsString( 'pd_target_page', $html, 'no paging links beside the control' );
		$this->assertSame( 1, substr_count( $html, 'name="pd_post_id"' ), 'exactly one content control' );
	}

	public function test_the_control_works_with_no_javascript(): void {
		$this->seed_many();

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		// A real select that submits on its own; the script upgrades it in place.
		$this->assertMatchesRegularExpression( '/<select[^>]*name="pd_post_id"/', $html );
		$this->assertStringContainsString( '<label for="pd_post_id">', $html );
		$this->assertStringContainsString( 'aria-describedby="pd_post_id_help"', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_the_search_endpoint_is_bounded_and_covers_every_declared_type(): void {
		register_post_type(
			'pd_venue',
			array(
				'public' => true,
				'label'  => 'Venue',
			)
		);

		self::factory()->post->create(
			array(
				'post_type'   => 'pd_venue',
				'post_status' => 'publish',
				'post_title'  => 'Aardvark hall',
			)
		);

		$hits = TargetSearch::results( 'Aardvark hall' );

		$this->assertNotEmpty( $hits );
		$this->assertSame( 'Aardvark hall', $hits[0]['title'] );
		$this->assertSame( 'Venue', $hits[0]['type'] );

		$this->seed_many();

		$this->assertLessThanOrEqual(
			TargetSearch::LIMIT,
			count( TargetSearch::results( '' ) ),
			'the endpoint never returns an unbounded page'
		);

		unregister_post_type( 'pd_venue' );
	}

	public function test_the_search_endpoint_never_returns_unreadable_content(): void {
		$this->seed_unreadable_block();

		wp_set_current_user( $this->manager_who_cannot_read_private() );

		foreach ( TargetSearch::results( 'Confidential dossier' ) as $hit ) {
			$this->fail( 'the search returned content this operator cannot read: ' . $hit['title'] );
		}

		$this->assertTrue( true, 'no unreadable content was returned' );
	}

	// -- authorization belongs in the query, not after it ---------------------

	/**
	 * A user who may manage mappings but may not read a block of private posts.
	 *
	 * The capability that gates the screen and the capability that decides what
	 * content is readable are different things, and a selector filtered after
	 * the fact conflates them.
	 */
	private function manager_who_cannot_read_private(): int {
		add_role(
			'pd_manager',
			'Domain manager',
			array(
				'read'              => true,
				'pd_manage_domains' => true,
			)
		);
		add_filter( 'pd_rest_capability', static fn(): string => 'pd_manage_domains' );

		return self::factory()->user->create( array( 'role' => 'pd_manager' ) );
	}

	/**
	 * One readable target, then enough unreadable ones to fill the first page.
	 *
	 * Scoped to a post type of its own: this session commits, so counting
	 * against the whole site would depend on what every other test left behind.
	 */
	private function seed_unreadable_block(): int {
		// A post type per test. This session commits, so a shared type would
		// accumulate the previous test's rows and make every count depend on
		// execution order.
		++self::$probes;

		$this->probe_type = 'pd_probe_' . self::$probes;

		register_post_type(
			$this->probe_type,
			array(
				'public' => true,
				'label'  => 'Probe',
			)
		);

		$type = $this->probe_type;
		add_filter( 'pd_admin_target_post_types', static fn(): array => array( $type ) );

		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Dated explicitly, not merely created in order: the production query
		// sorts by modified date, and rows written in the same second sort
		// arbitrarily, which would make this test pass or fail by luck.
		$readable = self::factory()->post->create(
			array(
				'post_type'         => $this->probe_type,
				'post_status'       => 'publish',
				'post_title'        => 'Quokka reading room',
				'post_date'         => '2020-01-01 00:00:00',
				'post_date_gmt'     => '2020-01-01 00:00:00',
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			)
		);

		// Every one of these is newer, so all 55 fill the first page ahead of it.
		for ( $i = 0; $i < 55; $i++ ) {
			$when = gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00' ) + $i );

			self::factory()->post->create(
				array(
					'post_type'         => $this->probe_type,
					'post_status'       => 'private',
					'post_title'        => sprintf( 'Confidential dossier %03d', $i ),
					'post_author'       => $owner,
					'post_date'         => $when,
					'post_date_gmt'     => $when,
					'post_modified'     => $when,
					'post_modified_gmt' => $when,
				)
			);
		}

		return $readable;
	}

	public function test_a_readable_target_behind_an_unreadable_page_is_still_reachable(): void {
		$readable = $this->seed_unreadable_block();

		wp_set_current_user( $this->manager_who_cannot_read_private() );

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		// Filtering after pagination gave a first page of 50 private posts that
		// filtered to nothing, so the screen claimed the site had no content and
		// rendered no pagination at all.
		$this->assertStringContainsString(
			'value="' . $readable . '"',
			$html,
			'the only readable target must appear, not sit behind a page of content this user cannot see'
		);
	}

	public function test_an_empty_filtered_page_cannot_strand_the_operator(): void {
		$this->seed_unreadable_block();

		wp_set_current_user( $this->manager_who_cannot_read_private() );

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		$this->assertStringNotContainsString(
			'There is no published content',
			$html,
			'there is readable content; saying otherwise is what strands the operator'
		);
	}

	public function test_totals_and_paging_describe_only_readable_content(): void {
		$this->seed_unreadable_block();

		wp_set_current_user( $this->manager_who_cannot_read_private() );

		$found = Screen::target_candidates( '', 1 );

		$this->assertSame(
			1,
			$found['total'],
			'exactly one of the 56 posts is readable, and the total must say so'
		);
		$this->assertCount( 1, $found['posts'] );
		$this->assertSame( 1, $found['pages'], '55 unreadable posts must not manufacture pages of nothing' );
	}

	public function test_no_unreadable_title_or_identifier_is_rendered(): void {
		$this->seed_unreadable_block();

		wp_set_current_user( $this->manager_who_cannot_read_private() );

		$html = $this->page( array( 'page' => SettingsPage::SLUG ) );

		$this->assertStringNotContainsString( 'Confidential dossier', $html );

		foreach ( get_posts(
			array(
				'post_type'      => $this->probe_type,
				'post_status'    => 'private',
				'posts_per_page' => 5,
			)
		) as $private ) {
			$this->assertStringNotContainsString(
				'value="' . $private->ID . '"',
				$html,
				'an id the operator cannot read must not be offered'
			);
		}
	}

	public function test_a_user_who_can_read_private_content_still_sees_it(): void {
		$this->seed_unreadable_block();

		// The set_up user is an administrator, who may read those private posts.
		$found = Screen::target_candidates( '', 1 );

		$this->assertSame(
			56,
			$found['total'],
			'readability is decided per user, not by excluding private content outright'
		);
	}
}
