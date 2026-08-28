<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

/**
 * Splits representation and pagination suffixes off the path so the subtree walk
 * only ever sees a content path.
 */
final class PathDecomposer {

	public function decompose( string $request_uri ): PathDecomposition {
		$query = '';
		$path  = $request_uri;

		if ( str_contains( $request_uri, '?' ) ) {
			[ $path, $query ] = explode( '?', $request_uri, 2 );
		}

		$path = strtok( $path, '#' );
		$path = trim( (string) $path, '/' );

		$segments     = '' === $path ? array() : explode( '/', $path );
		$rep          = Representation::HTML;
		$feed_type    = null;
		$paged        = null;
		$comment_page = null;

		// Suffixes are trailing, so consume from the end.
		while ( array() !== $segments ) {
			$last = end( $segments );

			if ( 1 === preg_match( '/^comment-page-([0-9]+)$/', (string) $last, $m ) ) {
				$comment_page = (int) $m[1];
				array_pop( $segments );
				continue;
			}

			if ( Representation::HTML === $rep && in_array( $last, array( 'feed', 'embed' ), true ) ) {
				$rep = 'feed' === $last ? Representation::FEED : Representation::EMBED;
				array_pop( $segments );
				continue;
			}

			if ( Representation::HTML === $rep
				&& in_array( $last, array( 'rss', 'rss2', 'atom', 'rdf' ), true )
				&& count( $segments ) >= 2
				&& 'feed' === $segments[ count( $segments ) - 2 ] ) {
				$feed_type = (string) $last;
				$rep       = Representation::FEED;
				array_pop( $segments );
				array_pop( $segments );
				continue;
			}

			if ( null === $paged
				&& count( $segments ) >= 2
				&& 'page' === $segments[ count( $segments ) - 2 ]
				&& 1 === preg_match( '/^[0-9]+$/', (string) $last ) ) {
				$paged = (int) $last;
				array_pop( $segments );
				array_pop( $segments );
				continue;
			}

			break;
		}

		return new PathDecomposition(
			implode( '/', $segments ),
			$rep,
			$feed_type,
			$paged,
			$comment_page,
			$query
		);
	}
}
