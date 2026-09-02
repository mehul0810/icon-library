<?php
/**
 * Imports Heroicons SVG files into the bundled collection manifest.
 *
 * Outline is retained as an opt-in experimental variant because WordPress 7.1
 * strips the stroke attributes required to render it.
 *
 * Usage: php scripts/import-heroicons.php /path/to/heroicons
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}

$source_dir = isset( $argv[1] ) ? rtrim( $argv[1], DIRECTORY_SEPARATOR ) : '';
$plugin_dir = dirname( __DIR__ );
$target_dir = $plugin_dir . '/assets/icons/heroicons';

require_once __DIR__ . '/lib/CollectionBuild.php';

if ( '' === $source_dir || ! is_dir( $source_dir . '/optimized' ) ) {
	fwrite( STDERR, "Provide a Heroicons checkout containing the optimized directory.\n" );
	exit( 1 );
}

$package_path = $source_dir . '/package.json';
$package      = is_readable( $package_path ) ? json_decode( file_get_contents( $package_path ), true ) : array();
$version      = is_array( $package ) && isset( $package['version'] ) ? $package['version'] : 'unknown';
$revision     = get_git_revision( $source_dir );

if ( 'unknown' === $version || null === $revision ) {
	fwrite( STDERR, "Heroicons source must provide a package version and Git revision.\n" );
	exit( 1 );
}

$variants = array(
	'outline' => array(
		'label'          => 'Outline',
		'source'         => 'optimized/24/outline',
		'coreCompatible' => false,
		'defaultEnabled' => false,
	),
	'solid'   => array(
		'label'          => 'Solid',
		'source'         => 'optimized/24/solid',
		'coreCompatible' => true,
		'defaultEnabled' => true,
	),
);

if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0755, true ) ) {
	fwrite( STDERR, "Could not create target directory.\n" );
	exit( 1 );
}

if ( is_readable( $source_dir . '/LICENSE' ) ) {
	copy( $source_dir . '/LICENSE', $target_dir . '/LICENSE' );
}

$icons             = array();
$skipped           = array();
$manifest_variants = array();

foreach ( $variants as $variant_slug => $variant ) {
	$variant_start_count = count( $icons );
	$source_variant_dir  = $source_dir . '/' . $variant['source'];
	$target_variant_dir  = $target_dir . '/' . $variant_slug;

	if ( ! is_dir( $source_variant_dir ) ) {
		fwrite( STDERR, "Missing Heroicons variant: {$variant['source']}\n" );
		exit( 1 );
	}

	$files = glob( $source_variant_dir . '/*.svg' );
	sort( $files );

	foreach ( $files as $file ) {
		$base_name = basename( $file, '.svg' );
		$svg       = file_get_contents( $file );

		try {
			CollectionBuild::normalize_svg( $svg, ! $variant['coreCompatible'] );
		} catch ( RuntimeException $exception ) {
			$skipped[] = $variant_slug . '/' . $base_name . ': ' . $exception->getMessage();
			continue;
		}

		$svg = preg_replace( '/\sdata-[a-z0-9_-]+\s*=\s*(["\']).*?\1/is', '', $svg );

		$target_relative = $variant_slug . '/' . $base_name . '.svg';
		$target_file     = $target_dir . '/' . $target_relative;

		if ( ! is_dir( $target_variant_dir ) && ! mkdir( $target_variant_dir, 0755, true ) ) {
			fwrite( STDERR, "Could not create variant directory: {$variant_slug}\n" );
			exit( 1 );
		}

		if ( false === file_put_contents( $target_file, trim( $svg ) . "\n" ) ) {
			fwrite( STDERR, "Could not write SVG: {$target_file}\n" );
			exit( 1 );
		}

		$icons[] = array(
			'id'           => 'heroicons/' . $variant_slug . '/' . $base_name,
			'coreIconName' => 'heroicons/' . $base_name . '-' . $variant_slug,
			'label'        => title_case_slug( $base_name ),
			'variant'      => $variant_slug,
			'categories'   => array( 'general' ),
			'keywords'     => keywords_from_slug( $base_name ),
			'path'         => $target_relative,
			'sha256'       => hash_file( 'sha256', $target_file ),
		);
	}

	if ( count( $icons ) > $variant_start_count ) {
		$manifest_variants[] = array(
			'slug'           => $variant_slug,
			'label'          => $variant['label'],
			'coreCompatible' => (bool) $variant['coreCompatible'],
			'defaultEnabled' => (bool) $variant['defaultEnabled'],
		);
	}
}

$manifest = array(
	'schemaVersion' => CollectionBuild::SCHEMA_VERSION,
	'slug'          => 'heroicons',
	'name'          => 'Heroicons',
	'description'   => 'A set of hand-crafted SVG icons from Tailwind Labs.',
	'version'       => $version,
	'license'       => array(
		'name' => 'MIT',
		'url'  => 'https://github.com/tailwindlabs/heroicons/blob/master/LICENSE',
	),
	'source'        => array(
		'name'     => 'tailwindlabs/heroicons',
		'url'      => 'https://github.com/tailwindlabs/heroicons',
		'revision' => $revision,
	),
	'variants'      => $manifest_variants,
	'icons'         => $icons,
);

$manifest_errors = CollectionBuild::validate_manifest( $manifest, $target_dir );

if ( ! empty( $manifest_errors ) ) {
	foreach ( $manifest_errors as $error ) {
		fwrite( STDERR, $error . "\n" );
	}
	exit( 1 );
}

$manifest_json = wp_json_encode_local( $manifest );
$manifest_path = $target_dir . '/manifest.json';
$temporary     = $manifest_path . '.tmp';

if ( false === file_put_contents( $temporary, $manifest_json ) || ! rename( $temporary, $manifest_path ) ) {
	fwrite( STDERR, "Could not write collection manifest.\n" );
	exit( 1 );
}

printf( "Imported %d Heroicons into %s\n", count( $icons ), $target_dir );
if ( $skipped ) {
	fwrite( STDERR, sprintf( "Skipped %d Core-incompatible icons. First examples: %s\n", count( $skipped ), implode( ', ', array_slice( $skipped, 0, 5 ) ) ) );
}

/**
 * Converts an icon slug to a label.
 *
 * @param string $slug Icon slug.
 * @return string
 */
function title_case_slug( $slug ) {
	return ucwords( str_replace( '-', ' ', $slug ) );
}

/**
 * Returns search keywords from an icon slug.
 *
 * @param string $slug Icon slug.
 * @return string[]
 */
function keywords_from_slug( $slug ) {
	return array_values( array_filter( explode( '-', $slug ) ) );
}

/**
 * Returns the full revision for a Git checkout.
 *
 * @param string $source_dir Git checkout.
 * @return string|null
 */
function get_git_revision( $source_dir ) {
	$command = sprintf( 'git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg( $source_dir ) );
	$output  = array();
	$status  = 1;
	exec( $command, $output, $status );
	$hash = isset( $output[0] ) ? trim( $output[0] ) : '';

	return 0 === $status && 1 === preg_match( '/^[a-f0-9]{40}$/', $hash ) ? $hash : null;
}

/**
 * JSON encodes with stable formatting without requiring WordPress bootstrap.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function wp_json_encode_local( $data ) {
	return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
