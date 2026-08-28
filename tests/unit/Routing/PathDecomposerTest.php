<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PostDomain\Routing\PathDecomposer;
use PostDomain\Routing\Representation;

final class PathDecomposerTest extends TestCase {

	private function decompose( string $uri ): \PostDomain\Routing\PathDecomposition {
		return ( new PathDecomposer() )->decompose( $uri );
	}

	public function test_a_plain_path_is_html(): void {
		$d = $this->decompose( '/events/gala/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::HTML, $d->rep );
		$this->assertNull( $d->paged );
		$this->assertSame( '', $d->raw_query );
	}

	public function test_a_descendant_feed_keeps_its_base_path(): void {
		$d = $this->decompose( '/events/gala/feed/atom/?utm_source=x' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::FEED, $d->rep );
		$this->assertSame( 'atom', $d->feed_type );
		$this->assertSame( 'utm_source=x', $d->raw_query );
	}

	public function test_a_bare_feed_suffix_has_no_type(): void {
		$d = $this->decompose( '/events/gala/feed/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::FEED, $d->rep );
		$this->assertNull( $d->feed_type );
	}

	public function test_an_embed_suffix_is_split(): void {
		$d = $this->decompose( '/events/gala/embed/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( Representation::EMBED, $d->rep );
	}

	public function test_pagination_is_split(): void {
		$d = $this->decompose( '/events/page/3/' );

		$this->assertSame( 'events', $d->base );
		$this->assertSame( 3, $d->paged );
	}

	public function test_comment_pagination_is_split(): void {
		$d = $this->decompose( '/events/gala/comment-page-2/' );

		$this->assertSame( 'events/gala', $d->base );
		$this->assertSame( 2, $d->comment_page );
	}

	public function test_a_feed_after_pagination_splits_both(): void {
		$d = $this->decompose( '/events/page/2/feed/rss2/' );

		$this->assertSame( 'events', $d->base );
		$this->assertSame( 2, $d->paged );
		$this->assertSame( Representation::FEED, $d->rep );
		$this->assertSame( 'rss2', $d->feed_type );
	}

	public function test_the_root_decomposes_to_an_empty_base(): void {
		$this->assertSame( '', $this->decompose( '/' )->base );
	}

	public function test_the_raw_query_is_preserved_verbatim(): void {
		$d = $this->decompose( '/x/?a=1&b=%20two&utm_campaign=spring+sale' );

		$this->assertSame( 'a=1&b=%20two&utm_campaign=spring+sale', $d->raw_query );
	}
}
