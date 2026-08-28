<?php
/**
 * Generates references/cloudflare-status-map.php from the pinned schema snapshot
 * and the human-authored classification policy. Offline by construction.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

$pd_root = dirname( __DIR__ );
$pd_args = array();

foreach ( array_slice( $argv, 1 ) as $pd_arg ) {
	if ( str_starts_with( $pd_arg, '--schema=' ) ) {
		$pd_args['schema'] = substr( $pd_arg, strlen( '--schema=' ) );
	}

	if ( '--stdout' === $pd_arg ) {
		$pd_args['stdout'] = true;
	}
}

/** @var array<string, string> $pd_provenance */
$pd_provenance = json_decode(
	(string) file_get_contents( $pd_root . '/references/cloudflare-schema-provenance.json' ),
	true
);

$pd_schema_path = $pd_args['schema'] ?? $pd_root . '/references/' . $pd_provenance['file'];

if ( ! isset( $pd_args['schema'] ) ) {
	$pd_digest = hash_file( 'sha256', $pd_schema_path );

	if ( $pd_digest !== $pd_provenance['sha256'] ) {
		fwrite( STDERR, "schema digest mismatch: expected {$pd_provenance['sha256']}, got {$pd_digest}\n" );
		exit( 1 );
	}
}

/** @var array<string, string[]> $pd_schema */
$pd_schema = json_decode( (string) file_get_contents( $pd_schema_path ), true );

/** @var array{hostname: array<string, string>, ssl: array<string, string>} $pd_policy */
$pd_policy = require $pd_root . '/references/cloudflare-status-policy.php';

$pd_expected = array(
	'hostname_status' => 16,
	'ssl_status'      => 21,
);
$pd_map      = array(
	'hostname' => array(),
	'ssl'      => array(),
);

foreach ( array(
	'hostname_status' => 'hostname',
	'ssl_status'      => 'ssl',
) as $pd_axis => $pd_key ) {
	$pd_values = $pd_schema[ $pd_axis ] ?? null;

	if ( ! is_array( $pd_values ) ) {
		fwrite( STDERR, "schema axis {$pd_axis} is missing or malformed\n" );
		exit( 1 );
	}

	if ( count( $pd_values ) !== count( array_unique( $pd_values ) ) ) {
		fwrite( STDERR, "schema axis {$pd_axis} contains duplicates\n" );
		exit( 1 );
	}

	if ( ! isset( $pd_args['schema'] ) && count( $pd_values ) !== $pd_expected[ $pd_axis ] ) {
		fwrite(
			STDERR,
			"schema axis {$pd_axis} has " . count( $pd_values )
			. " values, expected {$pd_expected[ $pd_axis ]}; update the expectation deliberately\n"
		);
		exit( 1 );
	}

	foreach ( $pd_values as $pd_value ) {
		if ( ! isset( $pd_policy[ $pd_key ][ $pd_value ] ) ) {
			fwrite( STDERR, "unclassified {$pd_axis} value: {$pd_value}\n" );
			exit( 1 );
		}

		$pd_map[ $pd_key ][ $pd_value ] = $pd_policy[ $pd_key ][ $pd_value ];
	}
}

$pd_output = "<?php\n"
	. "/**\n * GENERATED from the pinned schema snapshot and the classification policy.\n"
	. " * Do not edit: run `composer generate:status-map`.\n *\n * @package PostDomain\n */\n\n"
	. "declare( strict_types = 1 );\n\n"
	. 'return ' . var_export( $pd_map, true ) . ";\n";

if ( isset( $pd_args['stdout'] ) ) {
	echo $pd_output; // phpcs:ignore WordPress.Security.EscapeOutput

	exit( 0 );
}

file_put_contents( $pd_root . '/references/cloudflare-status-map.php', $pd_output );
