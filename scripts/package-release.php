<?php
/**
 * Builds a deterministic production ZIP.
 *
 * @package IconLibrary
 */

$root = dirname( __DIR__ );

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

$files = array(
	'.' => array(
		'icon-library.php',
		'uninstall.php',
		'readme.txt',
		'README.md',
		'LICENSE.md',
		'composer.json',
		'assets/admin.css',
		'assets/admin.js',
		'assets/icons.css',
	),
);

foreach ( glob( $root . '/src/*.php' ) as $source_file ) {
	$files['.'][] = substr( $source_file, strlen( $root ) + 1 );
}
foreach ( glob( $root . '/assets/icons/*/manifest.json' ) as $manifest_path ) {
	$collection = basename( dirname( $manifest_path ) );
	$manifest   = json_decode( file_get_contents( $manifest_path ), true );
	if ( ! is_array( $manifest ) || empty( $manifest['icons'] ) || $collection !== $manifest['slug'] ) {
		fwrite( STDERR, sprintf( "Collection manifest is invalid: %s\n", $collection ) );
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
				$files['.'][] = 'assets/icons/heroicons/' . $legacy_variant . '/' . basename( $legacy_file );
			}
		}
	}
}

$files = array_values( array_unique( $files['.'] ) );
sort( $files );

foreach ( $files as $relative_path ) {
	if ( ! is_readable( $root . '/' . $relative_path ) ) {
		fwrite( STDERR, sprintf( "Package file is unreadable: %s\n", $relative_path ) );
		exit( 1 );
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
	$zip->addFile( $root . '/' . $relative_path, $archive_path );
	$zip->setMtimeName( $archive_path, $timestamp );
	$zip->setCompressionName( $archive_path, ZipArchive::CM_DEFLATE, 9 );
}
$zip->close();

if ( ! rename( $temporary, $destination ) ) {
	fwrite( STDERR, "Release ZIP could not be finalized.\n" );
	exit( 1 );
}

printf(
	"Built %s (%d files, sha256 %s).\n",
	$destination,
	count( $files ),
	hash_file( 'sha256', $destination )
);
