<?php
/**
 * Validates all bundled collection manifests and their SVG assets.
 *
 * Usage: php scripts/validate-manifests.php
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

$collections_dir = dirname( __DIR__ ) . '/assets/icons';
$manifest_paths  = glob( $collections_dir . '/*/manifest.json' );
$failed          = false;

if ( empty( $manifest_paths ) ) {
	fwrite( STDERR, "No collection manifests found.\n" );
	exit( 1 );
}

sort( $manifest_paths );

foreach ( $manifest_paths as $manifest_path ) {
	$manifest = json_decode( file_get_contents( $manifest_path ), true );
	$label    = basename( dirname( $manifest_path ) );

	if ( ! is_array( $manifest ) ) {
		fwrite( STDERR, sprintf( '%s: invalid JSON.', $label ) . "\n" );
		$failed = true;
		continue;
	}

	$errors = CollectionBuild::validate_manifest( $manifest, dirname( $manifest_path ) );

	if ( ! empty( $errors ) ) {
		$failed = true;
		foreach ( $errors as $error ) {
			fwrite( STDERR, sprintf( '%1$s: %2$s', $label, $error ) . "\n" );
		}
		continue;
	}

	printf( '%1$s: valid (%2$d icons).' . "\n", $label, count( $manifest['icons'] ) );
}

exit( $failed ? 1 : 0 );
