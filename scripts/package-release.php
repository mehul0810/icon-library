<?php
/**
 * Builds a deterministic production ZIP.
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

$root = dirname( __DIR__ );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The Zip extension is required.\n" );
	exit( 1 );
}

$plugin_source = file_get_contents( $root . '/icon-library.php' );
if ( ! preg_match( '/^[ \t*#@]*Version:\s*(\d+\.\d+\.\d+)/mi', $plugin_source, $version_match ) ) {
	fwrite( STDERR, "Plugin version could not be read.\n" );
	exit( 1 );
}
$version = $version_match[1];

$readme = file_get_contents( $root . '/readme.txt' );
if ( ! preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $stable_match ) || $version !== $stable_match[1] ) {
	fwrite( STDERR, "Plugin version and readme stable tag do not match.\n" );
	exit( 1 );
}

$files            = array(
	'.' => array(
		'icon-library.php',
		'uninstall.php',
		'readme.txt',
		'LICENSE.md',
		'assets/admin.css',
		'assets/admin.js',
		'assets/icons.css',
	),
);
$legacy_svg_paths = array();

foreach ( glob( $root . '/src/*.php' ) as $source_file ) {
	$files['.'][] = substr( $source_file, strlen( $root ) + 1 );
}
foreach ( glob( $root . '/assets/icons/*/manifest.json' ) as $manifest_path ) {
	$collection      = basename( dirname( $manifest_path ) );
	$manifest        = json_decode( file_get_contents( $manifest_path ), true );
	$manifest_errors = is_array( $manifest ) ? CollectionBuild::validate_manifest( $manifest, dirname( $manifest_path ) ) : array( 'Manifest must decode to an object.' );
	if ( ! is_array( $manifest ) || ! empty( $manifest_errors ) || 0 !== strcmp( $collection, $manifest['slug'] ?? '' ) ) {
		fwrite( STDERR, sprintf( "Collection manifest is invalid: %s\n", $collection ) );
		foreach ( $manifest_errors as $manifest_error ) {
			fwrite( STDERR, sprintf( "- %s\n", $manifest_error ) );
		}
		exit( 1 );
	}
	$files['.'][] = 'assets/icons/' . $collection . '/manifest.json';
	$files['.'][] = 'assets/icons/' . $collection . '/LICENSE';
	foreach ( $manifest['icons'] as $icon ) {
		if ( empty( $icon['path'] ) || false !== strpos( $icon['path'], '..' ) ) {
			fwrite( STDERR, "Manifest contains an unsafe icon path.\n" );
			exit( 1 );
		}
		$files['.'][] = 'assets/icons/' . $collection . '/' . $icon['path'];
	}

	// Keep the old Heroicons size paths available for blocks saved before the
	// collection moved to style-based variants.
	if ( 'heroicons' === $collection ) {
		foreach ( array( '16-solid', '20-solid', '24-solid' ) as $legacy_variant ) {
			foreach ( glob( $root . '/assets/icons/heroicons/' . $legacy_variant . '/*.svg' ) as $legacy_file ) {
				$legacy_path                      = 'assets/icons/heroicons/' . $legacy_variant . '/' . basename( $legacy_file );
				$files['.'][]                     = $legacy_path;
				$legacy_svg_paths[ $legacy_path ] = true;
			}
		}
	}
}

$files = array_values( array_unique( $files['.'] ) );
sort( $files );

foreach ( $files as $relative_path ) {
	$source_path = $root . '/' . $relative_path;
	$resolved    = realpath( $source_path );
	if ( false === $resolved || is_link( $source_path ) || 0 !== strpos( $resolved, $root . DIRECTORY_SEPARATOR ) || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
		fwrite( STDERR, sprintf( "Package file is unreadable: %s\n", $relative_path ) );
		exit( 1 );
	}
	if ( 'svg' === strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ) && isset( $legacy_svg_paths[ $relative_path ] ) ) {
		try {
			CollectionBuild::normalize_svg( file_get_contents( $root . '/' . $relative_path ), true );
		} catch ( RuntimeException $exception ) {
			fwrite( STDERR, sprintf( "Legacy SVG is invalid: %s (%s)\n", $relative_path, $exception->getMessage() ) );
			exit( 1 );
		}
	}
}

$build_dir = $root . '/build';
if ( ! is_dir( $build_dir ) && ! mkdir( $build_dir, 0775, true ) ) {
	fwrite( STDERR, "Build directory could not be created.\n" );
	exit( 1 );
}

$destination = $build_dir . '/icon-library.' . $version . '.zip';
$temporary   = $destination . '.tmp';
if ( file_exists( $temporary ) ) {
	unlink( $temporary );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Release ZIP could not be created.\n" );
	exit( 1 );
}

$timestamp = 946684800;
foreach ( $files as $relative_path ) {
	$archive_path = 'icon-library/' . $relative_path;
	if ( isset( $legacy_svg_paths[ $relative_path ] ) ) {
		try {
			$content = CollectionBuild::normalize_svg( file_get_contents( $root . '/' . $relative_path ), true );
		} catch ( RuntimeException $exception ) {
			$zip->close();
			fwrite( STDERR, sprintf( "Legacy SVG is invalid: %s (%s)\n", $relative_path, $exception->getMessage() ) );
			exit( 1 );
		}
		$added = $zip->addFromString( $archive_path, $content . "\n" );
	} else {
		$added = $zip->addFile( $root . '/' . $relative_path, $archive_path );
	}
	if ( ! $added || ! $zip->setMtimeName( $archive_path, $timestamp ) || ! $zip->setCompressionName( $archive_path, ZipArchive::CM_DEFLATE, 9 ) ) {
		$zip->close();
		fwrite( STDERR, sprintf( "Could not add package file: %s\n", $relative_path ) );
		exit( 1 );
	}
}
if ( ! $zip->close() ) {
	fwrite( STDERR, "Release ZIP could not be closed.\n" );
	exit( 1 );
}

if ( ! rename( $temporary, $destination ) ) {
	fwrite( STDERR, "Release ZIP could not be finalized.\n" );
	exit( 1 );
}

$verification = new ZipArchive();
$opened       = $verification->open( $destination );
// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this standard property name.
$entry_count = true === $opened ? $verification->numFiles : 0;
if ( true !== $opened || count( $files ) !== $entry_count ) {
	if ( true === $opened ) {
		$verification->close();
	}
	fwrite( STDERR, "Release ZIP verification failed.\n" );
	exit( 1 );
}
$verification->close();

printf(
	"Built %s (%d files, sha256 %s).\n",
	$destination,
	count( $files ),
	hash_file( 'sha256', $destination )
);
