<?php
/**
 * Checks the PHP examples in the plan documents: that they parse, that every
 * PostDomain symbol they name resolves, that no type is declared twice outside an
 * explicit replacement, and that calls to the pinned lease APIs have the right
 * arity.
 *
 * Developer tool. Never loaded by the plugin, never reaches the network.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

$pd_root = dirname( __DIR__ );
$pd_only = null;

foreach ( array_slice( $argv, 1 ) as $pd_arg ) {
	if ( str_starts_with( $pd_arg, '--only=' ) ) {
		$pd_only = substr( $pd_arg, strlen( '--only=' ) );
	}
}

/**
 * Argument counts for the APIs this correction series changed. A drift between
 * two plans is otherwise invisible until someone pastes the older one.
 */
$pd_pinned = array(
	'acquire'                => array( 4 ),
	'consume'                => array( 1 ),
	'finalize'               => array( 2 ),
	'delete_row'             => array( 1 ),
	'release_reserved'       => array( 1 ),
	'clear_expired_reserved' => array( 1 ),
	'claim_recovery'         => array( 1 ),
	'extend_recovery'        => array( 2 ),
	'from_mapping'           => array( 4, 5 ),
	'binding_for'            => array( 3 ),
	'open_window'            => array( 4 ),
	'commit'                 => array( 2 ),
);

$pd_files = null === $pd_only
	? glob( $pd_root . '/docs/superpowers/plans/*.md' )
	: array( $pd_only );

$pd_blocks = array();

foreach ( (array) $pd_files as $pd_file ) {
	$pd_body = (string) file_get_contents( (string) $pd_file );

	preg_match_all( '/^```php\R(.*?)^```/ms', $pd_body, $pd_found, PREG_OFFSET_CAPTURE );

	foreach ( $pd_found[1] as $pd_index => $pd_capture ) {
		list( $pd_code, $pd_offset ) = $pd_capture;

		$pd_lead  = substr( $pd_body, max( 0, $pd_offset - 240 ), 240 );
		$pd_lines = array_values( array_filter( array_map( 'trim', explode( "\n", $pd_lead ) ) ) );

		// A listing introduced as a "checker fixture" is a deliberately broken
		// example this tool's own self-tests use. Scanning it would fail the run
		// on defects that are the point. It is still reported as skipped.
		$pd_fixture = str_contains( $pd_lead, 'checker fixture' );

		$pd_blocks[] = array(
			'file'     => basename( (string) $pd_file ),
			'index'    => $pd_index + 1,
			'code'     => $pd_code,
			'complete' => str_starts_with( ltrim( $pd_code ), '<?php' ) && ! $pd_fixture,
			'replace'  => str_contains( $pd_lead, 'Replace `' ),
			'intro'    => substr( (string) end( $pd_lines ), 0, 70 ),
			'lead'     => $pd_lead,
		);
	}
}

$pd_errors   = array();
$pd_declared = array();
$pd_short    = array();
$pd_complete = array();
$pd_skipped  = array();

foreach ( $pd_blocks as $pd_block ) {
	if ( $pd_block['complete'] ) {
		$pd_complete[] = $pd_block;
	} else {
		$pd_skipped[] = $pd_block;
	}
}

/**
 * Logic a skipped fragment must not carry silently. A fragment cannot be linted,
 * so one containing concurrency, authorization, transaction, deletion, or
 * provider-binding code must either be promoted to a complete example or name the
 * test that covers it, in a `<!-- covered-by: … -->` line just above its fence.
 */
$pd_critical = array(
	'AtomicTransition',
	'TransitionResult',
	'MutationLease',
	'LeaseOwner',
	'claim_recovery',
	'delete_row',
	'finalize(',
	'->delete(',
	'ssl_mutation_',
	'ssl_provider_environment',
	'DriverFactory',
	'BoundResource',
	'permission_callback',
);

foreach ( $pd_skipped as $pd_block ) {
	// This tool's own defect fixtures are deliberately broken and deliberately
	// not inspected; they must not also be demanded to name a covering test.
	if ( str_contains( $pd_block['lead'], 'checker fixture' ) ) {
		continue;
	}

	$pd_hits = array();

	foreach ( $pd_critical as $pd_marker ) {
		if ( str_contains( $pd_block['code'], $pd_marker ) ) {
			$pd_hits[] = $pd_marker;
		}
	}

	if ( array() === $pd_hits || str_contains( $pd_block['lead'], 'covered-by:' ) ) {
		continue;
	}

	$pd_errors[] = sprintf(
		'[uncovered-critical-fragment] %s block %d carries %s — promote it or add a covered-by marker',
		$pd_block['file'],
		$pd_block['index'],
		implode( ', ', $pd_hits )
	);
}

// Pass one: what does the suite declare, and where?
foreach ( $pd_complete as $pd_key => $pd_block ) {
	preg_match( '/^namespace\s+([^;]+);/m', $pd_block['code'], $pd_ns );

	$pd_namespace                 = isset( $pd_ns[1] ) ? trim( $pd_ns[1] ) : '';
	$pd_complete[ $pd_key ]['ns'] = $pd_namespace;
	$pd_where                     = sprintf( '%s block %d', $pd_block['file'], $pd_block['index'] );

	preg_match_all( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $pd_block['code'], $pd_types );

	$pd_complete[ $pd_key ]['types'] = $pd_types[1];

	foreach ( $pd_types[1] as $pd_type ) {
		$pd_fqn = '' === $pd_namespace ? $pd_type : $pd_namespace . '\\' . $pd_type;

		if ( isset( $pd_declared[ $pd_fqn ] ) && ! $pd_block['replace'] ) {
			$pd_errors[] = sprintf(
				'[duplicate-declaration] %s redeclares %s, first declared in %s',
				$pd_where,
				$pd_fqn,
				$pd_declared[ $pd_fqn ]
			);
		}

		$pd_declared[ $pd_fqn ] = $pd_where;
		$pd_short[ $pd_type ][] = $pd_fqn;
	}
}

// Pass two: parse, resolve, and count arguments.
$pd_tmp = tempnam( sys_get_temp_dir(), 'pd-example-' ) . '.php';

foreach ( $pd_complete as $pd_block ) {
	$pd_where = sprintf( '%s block %d', $pd_block['file'], $pd_block['index'] );

	file_put_contents( $pd_tmp, $pd_block['code'] );

	$pd_lint = array();
	$pd_code = 0;
	exec( 'php -l ' . escapeshellarg( $pd_tmp ) . ' 2>&1', $pd_lint, $pd_code );

	if ( 0 !== $pd_code ) {
		$pd_errors[] = sprintf( '[syntax] %s: %s', $pd_where, (string) reset( $pd_lint ) );
	}

	// Comments first: an apostrophe in prose would otherwise open a phantom
	// string and swallow the code after it. Every reference scan below runs on
	// this stripped form, so a class named in a comment or a string is not
	// mistaken for a use of it.
	$pd_bare = (string) preg_replace( '#/\*.*?\*/#s', '', $pd_block['code'] );
	$pd_bare = (string) preg_replace( '#//[^\n]*#', '', $pd_bare );
	$pd_bare = (string) preg_replace( "#'(?:[^'\\\\]|\\\\.)*'#", "''", $pd_bare );

	preg_match_all( '/^use\s+([^;]+);/m', $pd_block['code'], $pd_uses );

	$pd_imported = array();

	foreach ( $pd_uses[1] as $pd_use ) {
		$pd_use   = trim( $pd_use );
		$pd_alias = substr( (string) strrchr( '\\' . $pd_use, '\\' ), 1 );

		if ( isset( $pd_imported[ $pd_alias ] ) ) {
			$pd_errors[] = sprintf( '[duplicate-import] %s: %s', $pd_where, $pd_use );
		}

		$pd_imported[ $pd_alias ] = $pd_use;

		if ( str_starts_with( $pd_use, 'PostDomain\\' ) && ! isset( $pd_declared[ $pd_use ] ) ) {
			$pd_errors[] = sprintf( '[unresolved-import] %s: %s', $pd_where, $pd_use );
		}
	}

	preg_match_all( '/\\\\(PostDomain\\\\[A-Za-z0-9_\\\\]+)/', $pd_bare, $pd_inline );

	foreach ( $pd_inline[1] as $pd_symbol ) {
		$pd_symbol = rtrim( $pd_symbol, '\\' );

		if ( ! isset( $pd_declared[ $pd_symbol ] ) ) {
			$pd_errors[] = sprintf( '[unresolved-fq] %s: %s', $pd_where, $pd_symbol );
		}
	}

	// A bare short name that the suite declares elsewhere, neither imported here
	// nor living in this block's own namespace. This is the Reconciler defect.
	$pd_reference = '/(?<![\\\\\w$>])(?:(?:new|instanceof|extends|implements)\s+([A-Z]\w+)|([A-Z]\w+)::|\(\s*([A-Z]\w+)\s+\$|:\s*\??([A-Z]\w+)[\s{;)]|\|\s*([A-Z]\w+)\b)/';

	preg_match_all( $pd_reference, $pd_bare, $pd_refs, PREG_SET_ORDER );

	foreach ( $pd_refs as $pd_match ) {
		$pd_name = '';

		foreach ( array_slice( $pd_match, 1 ) as $pd_group ) {
			if ( '' !== $pd_group ) {
				$pd_name = $pd_group;

				break;
			}
		}

		if ( '' === $pd_name || ! isset( $pd_short[ $pd_name ] ) ) {
			continue;
		}

		if ( isset( $pd_imported[ $pd_name ] ) || in_array( $pd_name, $pd_block['types'], true ) ) {
			continue;
		}

		$pd_local = '' === $pd_block['ns'] ? $pd_name : $pd_block['ns'] . '\\' . $pd_name;

		if ( isset( $pd_declared[ $pd_local ] ) ) {
			continue;
		}

		$pd_errors[] = sprintf(
			'[missing-import] %s: %s is declared as %s and is neither imported nor local',
			$pd_where,
			$pd_name,
			$pd_short[ $pd_name ][0]
		);
	}

	foreach ( $pd_pinned as $pd_method => $pd_allowed ) {
		preg_match_all( '/(?:->|::)' . $pd_method . '\(/', $pd_bare, $pd_calls, PREG_OFFSET_CAPTURE );

		foreach ( $pd_calls[0] as $pd_call ) {
			$pd_start = $pd_call[1] + strlen( $pd_call[0] );
			$pd_depth = 1;
			$pd_at    = $pd_start;

			while ( $pd_at < strlen( $pd_bare ) && $pd_depth > 0 ) {
				if ( str_contains( '([', $pd_bare[ $pd_at ] ) ) {
					++$pd_depth;
				} elseif ( str_contains( ')]', $pd_bare[ $pd_at ] ) ) {
					--$pd_depth;
				}

				++$pd_at;
			}

			$pd_count = pd_count_arguments( substr( $pd_bare, $pd_start, $pd_at - $pd_start - 1 ) );

			if ( ! in_array( $pd_count, $pd_allowed, true ) ) {
				$pd_errors[] = sprintf(
					'[arity] %s: %s() called with %d argument(s), expected %s',
					$pd_where,
					$pd_method,
					$pd_count,
					implode( ' or ', $pd_allowed )
				);
			}
		}
	}
}

if ( file_exists( $pd_tmp ) ) {
	unlink( $pd_tmp );
}

function pd_count_arguments( string $list ): int {
	$depth = 0;
	$count = 0;
	$seen  = false;

	foreach ( str_split( $list ) as $char ) {
		if ( str_contains( '([', $char ) ) {
			++$depth;
		} elseif ( str_contains( ')]', $char ) ) {
			--$depth;
		}

		if ( ! ctype_space( $char ) ) {
			$seen = true;
		}

		if ( ',' === $char && 0 === $depth ) {
			++$count;
		}
	}

	return $seen ? $count + 1 : 0;
}

printf( "complete examples inspected: %d\n", count( $pd_complete ) );
printf( "fragments NOT inspected: %d\n", count( $pd_skipped ) );
printf( "types declared: %d\n", count( $pd_declared ) );

foreach ( $pd_skipped as $pd_block ) {
	printf(
		"  skipped %s block %d — %s%s\n",
		$pd_block['file'],
		$pd_block['index'],
		$pd_block['intro'],
		str_contains( $pd_block['lead'], 'covered-by:' ) ? ' [covered]' : ''
	);
}

$pd_errors = array_values( array_unique( $pd_errors ) );

if ( array() !== $pd_errors ) {
	sort( $pd_errors );
	fwrite( STDERR, implode( "\n", $pd_errors ) . "\n" );

	exit( 1 );
}

exit( 0 );
