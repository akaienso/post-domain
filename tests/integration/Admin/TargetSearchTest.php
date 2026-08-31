<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\TargetSearch;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;

/**
 * The search behind the combobox, past its first twenty answers.
 *
 * The endpoint used to return page one and nothing else — no total, no signal
 * that there was more. On a site with more than twenty similarly-titled pages,
 * every match after the twentieth was unreachable and the screen said nothing
 * about it, so the operator concluded the plugin could not map that page.
 *
 * These tests are about the two things that fixes: a response says whether more
 * exists, and asking for the next page returns different rows — while `more`,
 * the total and the rows all keep describing the same readable set.
 */
final class TargetSearchTest extends OwnedSessionTestCase {

	private string $probe_type = '';

	private static int $probes = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();

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

		parent::tear_down();
	}

	/**
	 * A post type of this test's own, and the selector pointed at it.
	 *
	 * This session commits, so counting against a shared type would depend on
	 * whatever every other test left behind.
	 */
	private function own_type(): string {
		++self::$probes;

		$this->probe_type = 'pd_search_probe_' . self::$probes;

		register_post_type(
			$this->probe_type,
			array(
				'public' => true,
				'label'  => 'Probe',
			)
		);

		$type = $this->probe_type;
		add_filter( 'pd_admin_target_post_types', static fn(): array => array( $type ) );

		return $this->probe_type;
	}

	/**
	 * Posts dated explicitly, newest first.
	 *
	 * The production query sorts by modified date, and rows written in the same
	 * second sort arbitrarily — which would make a paging test pass or fail by
	 * luck rather than by behaviour.
	 *
	 * @return int[] ids in the order the search will return them.
	 */
	private function seed( int $count, string $prefix = 'Pavilion' ): array {
		$type = $this->own_type();
		$ids  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$when = gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00' ) - $i );

			$ids[] = self::factory()->post->create(
				array(
					'post_type'         => $type,
					'post_status'       => 'publish',
					'post_title'        => sprintf( '%s %03d', $prefix, $i ),
					'post_date'         => $when,
					'post_date_gmt'     => $when,
					'post_modified'     => $when,
					'post_modified_gmt' => $when,
				)
			);
		}

		return $ids;
	}

	/** @param array<int, array{id: int, title: string, type: string}> $rows */
	private function ids( array $rows ): array {
		return array_column( $rows, 'id' );
	}

	public function test_more_is_true_while_pages_remain_and_false_on_the_last_one(): void {
		$this->seed( 45 );

		$first = TargetSearch::search( '', 1 );

		$this->assertCount( TargetSearch::LIMIT, $first['results'], 'each response stays bounded' );
		$this->assertSame( 1, $first['page'] );
		$this->assertSame( 45, $first['total'], 'the response says how much there is' );
		$this->assertTrue( $first['more'], 'twenty-five matches are still unreached; the cutoff must not be silent' );

		$this->assertTrue( TargetSearch::search( '', 2 )['more'], 'still more after the second page' );

		$last = TargetSearch::search( '', 3 );

		$this->assertCount( 5, $last['results'] );
		$this->assertFalse( $last['more'], 'the last page must not offer a page that is not there' );
	}

	public function test_the_second_page_returns_different_readable_rows(): void {
		$this->seed( 45 );

		$first  = $this->ids( TargetSearch::search( '', 1 )['results'] );
		$second = $this->ids( TargetSearch::search( '', 2 )['results'] );

		$this->assertCount( 20, $first );
		$this->assertCount( 20, $second );
		$this->assertSame(
			array(),
			array_intersect( $first, $second ),
			'page two must advance through the set, not repeat page one'
		);
	}

	public function test_a_match_past_the_first_response_is_reachable_by_paging(): void {
		// Oldest, so it sorts last: exactly the match the twenty-item cutoff
		// used to make unreachable.
		$ids    = $this->seed( 25 );
		$needle = $ids[24];

		$this->assertNotContains( $needle, $this->ids( TargetSearch::search( '', 1 )['results'] ) );
		$this->assertContains(
			$needle,
			$this->ids( TargetSearch::search( '', 2 )['results'] ),
			'the twenty-first match has to be reachable, or the domain cannot be mapped to it at all'
		);
	}

	public function test_a_page_number_below_one_is_read_as_the_first_page(): void {
		$this->seed( 25 );

		$this->assertSame( 1, TargetSearch::search( '', 0 )['page'] );
		$this->assertSame(
			$this->ids( TargetSearch::search( '', 1 )['results'] ),
			$this->ids( TargetSearch::search( '', -3 )['results'] )
		);
	}

	public function test_a_page_past_the_end_is_empty_and_offers_nothing_further(): void {
		$this->seed( 25 );

		$beyond = TargetSearch::search( '', 9 );

		// WP_Query reports no found rows for a page that does not exist, so the
		// answer here is emptiness with no invitation to go further — never a
		// `more` that walks the combobox off the end of the set.
		$this->assertSame( array(), $beyond['results'] );
		$this->assertFalse( $beyond['more'] );
	}

	public function test_paging_and_totals_describe_only_what_the_operator_can_read(): void {
		$readable = $this->seed_unreadable_block();

		wp_set_current_user( $this->manager_who_cannot_read_private() );

		$first = TargetSearch::search( '', 1 );

		$this->assertSame( 1, $first['total'], 'one of the fifty-six is readable, and the total must say so' );
		$this->assertSame( array( $readable ), $this->ids( $first['results'] ) );
		$this->assertFalse(
			$first['more'],
			'fifty-five unreadable posts must not manufacture a second page of nothing'
		);

		foreach ( TargetSearch::search( 'Confidential dossier', 1 )['results'] as $hit ) {
			$this->fail( 'paging named content this operator cannot read: ' . $hit['title'] );
		}

		// The fifty-five unreadable posts would have filled pages two and three
		// had they been filtered after paging instead of excluded by the query.
		$second = TargetSearch::search( '', 2 );

		$this->assertSame( array(), $second['results'] );
		$this->assertFalse( $second['more'] );
	}

	/**
	 * A user who may manage mappings but may not read a block of private posts.
	 *
	 * The capability that gates the screen and the capability that decides what
	 * content is readable are different things, and a list filtered after paging
	 * conflates them.
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

	/** One readable target, then enough unreadable ones to fill several pages. */
	private function seed_unreadable_block(): int {
		$type  = $this->own_type();
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$readable = self::factory()->post->create(
			array(
				'post_type'         => $type,
				'post_status'       => 'publish',
				'post_title'        => 'Quokka reading room',
				'post_date'         => '2020-01-01 00:00:00',
				'post_date_gmt'     => '2020-01-01 00:00:00',
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			)
		);

		for ( $i = 0; $i < 55; $i++ ) {
			$when = gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00' ) + $i );

			self::factory()->post->create(
				array(
					'post_type'         => $type,
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
}
