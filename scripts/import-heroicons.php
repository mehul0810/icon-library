<?php
/**
 * Imports Heroicons SVG files into the bundled collection manifest.
 *
 * Usage: php scripts/import-heroicons.php /path/to/heroicons
 *
 * @package IconLibrary
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}

$source_dir = isset( $argv[1] ) ? rtrim( $argv[1], DIRECTORY_SEPARATOR ) : '';
$plugin_dir = dirname( __DIR__ );
$target_dir = $plugin_dir . '/assets/icons/heroicons';

if ( '' === $source_dir || ! is_dir( $source_dir . '/optimized' ) ) {
	fwrite( STDERR, "Provide a Heroicons checkout containing the optimized directory.\n" );
	exit( 1 );
}

$package_path = $source_dir . '/package.json';
$package      = is_readable( $package_path ) ? json_decode( file_get_contents( $package_path ), true ) : array();
$version      = is_array( $package ) && isset( $package['version'] ) ? $package['version'] : 'unknown';

$variants = array(
	'24-outline' => array(
		'label'  => '24px Outline',
		'source' => 'optimized/24/outline',
	),
	'24-solid'   => array(
		'label'  => '24px Solid',
		'source' => 'optimized/24/solid',
	),
	'20-solid'   => array(
		'label'  => '20px Solid',
		'source' => 'optimized/20/solid',
	),
	'16-solid'   => array(
		'label'  => '16px Solid',
		'source' => 'optimized/16/solid',
	),
);

if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0755, true ) ) {
	fwrite( STDERR, "Could not create target directory.\n" );
	exit( 1 );
}

if ( is_readable( $source_dir . '/LICENSE' ) ) {
	copy( $source_dir . '/LICENSE', $target_dir . '/LICENSE' );
}

$icons = array();

foreach ( $variants as $variant_slug => $variant ) {
	$source_variant_dir = $source_dir . '/' . $variant['source'];
	$target_variant_dir = $target_dir . '/' . $variant_slug;

	if ( ! is_dir( $source_variant_dir ) ) {
		fwrite( STDERR, "Missing Heroicons variant: {$variant['source']}\n" );
		exit( 1 );
	}

	if ( ! is_dir( $target_variant_dir ) && ! mkdir( $target_variant_dir, 0755, true ) ) {
		fwrite( STDERR, "Could not create variant directory: {$variant_slug}\n" );
		exit( 1 );
	}

	$files = glob( $source_variant_dir . '/*.svg' );
	sort( $files );

	foreach ( $files as $file ) {
		$base_name = basename( $file, '.svg' );
		$svg       = file_get_contents( $file );
		$svg       = sanitize_svg_for_bundle( $svg );

		if ( '' === $svg ) {
			fwrite( STDERR, "Skipping invalid SVG: {$file}\n" );
			continue;
		}

		$target_relative = $variant_slug . '/' . $base_name . '.svg';
		$target_file     = $target_dir . '/' . $target_relative;

		file_put_contents( $target_file, $svg . "\n" );

		$icons[] = array(
			'id'           => 'heroicons/' . $variant_slug . '/' . $base_name,
			'coreIconName' => 'heroicons/' . $base_name . '-' . $variant_slug,
			'label'        => title_case_slug( $base_name ),
			'variant'      => $variant_slug,
			'categories'   => array( 'general' ),
			'keywords'     => keywords_from_slug( $base_name ),
			'path'         => $target_relative,
		);
	}
}

$manifest = array(
	'schemaVersion' => 1,
	'slug'          => 'heroicons',
	'name'          => 'Heroicons',
	'description'   => 'A set of hand-crafted SVG icons from Tailwind Labs.',
	'version'       => $version,
	'license'       => array(
		'name' => 'MIT',
		'url'  => 'https://github.com/tailwindlabs/heroicons/blob/master/LICENSE',
	),
	'source'        => array(
		'name' => 'tailwindlabs/heroicons',
		'url'  => 'https://github.com/tailwindlabs/heroicons',
	),
	'variants'      => array_map(
		static function ( $slug, $variant ) {
			return array(
				'slug'  => $slug,
				'label' => $variant['label'],
			);
		},
		array_keys( $variants ),
		$variants
	),
	'icons'         => $icons,
);

file_put_contents(
	$target_dir . '/manifest.json',
	wp_json_encode_local( $manifest )
);

printf( "Imported %d Heroicons into %s\n", count( $icons ), $target_dir );

/**
 * Sanitizes a trusted upstream SVG before bundling.
 *
 * @param string $svg SVG markup.
 * @return string
 */
function sanitize_svg_for_bundle( $svg ) {
	if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
		return '';
	}

	$svg = preg_replace( '/<\?xml.*?\?>/is', '', $svg );
	$svg = preg_replace( '/<!doctype.*?>/is', '', $svg );
	$svg = preg_replace( '/<!--.*?-->/s', '', $svg );
	$svg = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $svg );
	$svg = preg_replace( '/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svg );
	$svg = preg_replace( '/\s(?:on[a-z]+|style|href|xlink:href)\s*=\s*(["\']).*?\1/is', '', $svg );
	$svg = preg_replace( '/\sdata-[a-z0-9_-]+\s*=\s*(["\']).*?\1/is', '', $svg );
	$svg = trim( $svg );

	if ( 0 !== stripos( $svg, '<svg' ) ) {
		return '';
	}

	return $svg;
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
 * JSON encodes with stable formatting without requiring WordPress bootstrap.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function wp_json_encode_local( $data ) {
	return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
