<?php
/**
 * Imports a supported path-based SVG library.
 *
 * Usage: php scripts/import-path-library.php bootstrap-icons /path/to/checkout
 *        php scripts/import-path-library.php font-awesome /path/to/checkout
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

$library    = isset( $argv[1] ) ? $argv[1] : '';
$source_dir = isset( $argv[2] ) ? rtrim( $argv[2], DIRECTORY_SEPARATOR ) : '';
$plugin_dir = dirname( __DIR__ );
$configs    = array(
	'bootstrap-icons' => array(
		'name'         => 'Bootstrap Icons',
		'description'  => 'Official open source SVG icons for Bootstrap.',
		'variants'     => array(
			'default' => array(
				'label'  => 'Default',
				'source' => 'icons',
				'suffix' => '',
			),
			'filled'  => array(
				'label'  => 'Filled',
				'source' => 'icons',
				'suffix' => '-fill',
			),
		),
		'license'      => 'MIT',
		'license_url'  => 'https://github.com/twbs/icons/blob/main/LICENSE',
		'license_file' => 'LICENSE',
		'source_name'  => 'twbs/icons',
		'source_url'   => 'https://github.com/twbs/icons',
	),
	'font-awesome'    => array(
		'name'         => 'Font Awesome Free',
		'description'  => 'The free Classic Solid and Regular styles plus the Brands style from Font Awesome.',
		'variants'     => array(
			'solid'   => array(
				'label'  => 'Solid',
				'source' => 'svgs/solid',
			),
			'regular' => array(
				'label'  => 'Regular',
				'source' => 'svgs/regular',
			),
			'brands'  => array(
				'label'  => 'Brands',
				'source' => 'svgs/brands',
			),
		),
		'license'      => 'CC BY 4.0',
		'license_url'  => 'https://fontawesome.com/license/free',
		'license_file' => 'LICENSE.txt',
		'source_name'  => 'FortAwesome/Font-Awesome',
		'source_url'   => 'https://github.com/FortAwesome/Font-Awesome',
	),
);

if ( ! isset( $configs[ $library ] ) || ! is_dir( $source_dir ) ) {
	fwrite( STDERR, "Provide a supported library slug and source checkout.\n" );
	exit( 1 );
}

$config     = $configs[ $library ];
$target_dir = $plugin_dir . '/assets/icons/' . $library;

/**
 * Reads the official Font Awesome metadata used to build the Free collection.
 *
 * The upstream repository keeps the icon definitions in JSON and the category
 * taxonomy in a deliberately small YAML file. Keeping this parser here avoids
 * adding a runtime YAML dependency to the plugin or to collection authors.
 *
 * @param string $directory Font Awesome source directory.
 * @return array{icons:array,categories:array}
 */
$read_font_awesome_metadata = static function ( $directory ) {
	$find_file = static function ( $relative_path ) use ( $directory ) {
		$paths = array(
			$directory . '/' . $relative_path,
			$directory . '/js-packages/@fortawesome/fontawesome-free/' . $relative_path,
		);

		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	};

	$icons_path = $find_file( 'metadata/icons.json' );
	$icons      = $icons_path ? json_decode( file_get_contents( $icons_path ), true ) : null;
	if ( ! is_array( $icons ) ) {
		throw new RuntimeException( 'Font Awesome metadata/icons.json is missing or invalid.' );
	}

	$categories_path = $find_file( 'metadata/categories.yml' );
	$lines           = $categories_path ? file( $categories_path, FILE_IGNORE_NEW_LINES ) : false;
	if ( ! is_array( $lines ) ) {
		throw new RuntimeException( 'Font Awesome metadata/categories.yml is missing or unreadable.' );
	}

	$parse_scalar = static function ( $value ) {
		$value = trim( (string) $value );
		if ( strlen( $value ) >= 2 ) {
			$first = $value[0];
			$last  = $value[ strlen( $value ) - 1 ];
			if ( ( "'" === $first && "'" === $last ) || ( '"' === $first && '"' === $last ) ) {
				$value = substr( $value, 1, -1 );
			}
		}

		return trim( $value );
	};

	$categories    = array();
	$current_slug  = '';
	$reading_icons = false;

	foreach ( $lines as $line ) {
		$line = rtrim( (string) $line );

		if ( 1 === preg_match( '/^([a-z0-9-]+):\s*$/', $line, $matches ) ) {
			$current_slug                = $matches[1];
			$categories[ $current_slug ] = array(
				'slug'  => $current_slug,
				'label' => ucwords( str_replace( '-', ' ', $current_slug ) ),
				'icons' => array(),
			);
			$reading_icons               = false;
			continue;
		}

		if ( '' === $current_slug ) {
			continue;
		}

		if ( 1 === preg_match( '/^  icons:\s*$/', $line ) ) {
			$reading_icons = true;
			continue;
		}

		if ( $reading_icons && 1 === preg_match( '/^    -\s*(.+?)\s*$/', $line, $matches ) ) {
			$categories[ $current_slug ]['icons'][] = $parse_scalar( $matches[1] );
			continue;
		}

		if ( 1 === preg_match( '/^  label:\s*(.+?)\s*$/', $line, $matches ) ) {
			$categories[ $current_slug ]['label'] = $parse_scalar( $matches[1] );
			$reading_icons                        = false;
		}
	}

	return array(
		'icons'      => $icons,
		'categories' => array_values( $categories ),
	);
};

$font_awesome_metadata = array(
	'icons'      => array(),
	'categories' => array(),
);
if ( 'font-awesome' === $library ) {
	try {
		$font_awesome_metadata = $read_font_awesome_metadata( $source_dir );
	} catch ( RuntimeException $exception ) {
		fwrite( STDERR, $exception->getMessage() . "\n" );
		exit( 1 );
	}
}

$font_awesome_canonical = array();
$font_awesome_aliases   = array();
if ( 'font-awesome' === $library ) {
	foreach ( $font_awesome_metadata['icons'] as $canonical_slug => $metadata ) {
		if ( ! is_array( $metadata ) ) {
			continue;
		}

		$canonical_slug                            = (string) $canonical_slug;
		$font_awesome_canonical[ $canonical_slug ] = $canonical_slug;
		foreach ( (array) ( $metadata['aliases']['names'] ?? array() ) as $alias ) {
			$font_awesome_aliases[ (string) $alias ] = $canonical_slug;
		}
	}
}

$font_awesome_categories_by_icon = array();
if ( 'font-awesome' === $library ) {
	foreach ( $font_awesome_metadata['categories'] as $category ) {
		$category_slug = isset( $category['slug'] ) ? (string) $category['slug'] : '';
		if ( '' === $category_slug ) {
			continue;
		}

		foreach ( (array) ( $category['icons'] ?? array() ) as $icon_slug ) {
			$icon_slug                                       = (string) $icon_slug;
			$canonical                                       = $font_awesome_aliases[ $icon_slug ] ?? $font_awesome_canonical[ $icon_slug ] ?? $icon_slug;
			$font_awesome_categories_by_icon[ $canonical ][] = $category_slug;
		}
	}
}

$package  = json_decode( (string) @file_get_contents( $source_dir . '/package.json' ), true );
$version  = is_array( $package ) && isset( $package['version'] ) ? $package['version'] : ltrim( basename( trim( shell_exec( 'git -C ' . escapeshellarg( $source_dir ) . ' describe --tags --exact-match 2>/dev/null' ) ) ), 'v' );
$revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source_dir ) . ' rev-parse HEAD 2>/dev/null' ) );

if ( '' === $version || 1 !== preg_match( '/^[a-f0-9]{40}$/', $revision ) ) {
	fwrite( STDERR, "Source checkout is missing icons, version, or Git revision.\n" );
	exit( 1 );
}

if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0755, true ) ) {
	fwrite( STDERR, "Could not create collection directory.\n" );
	exit( 1 );
}

copy( $source_dir . '/' . $config['license_file'], $target_dir . '/LICENSE' );
$icons             = array();
$skipped           = array();
$manifest_variants = array();
$category_members  = array();

foreach ( $config['variants'] as $variant_slug => $variant ) {
	$source_icons = $source_dir . '/' . $variant['source'];
	$target_icons = $target_dir . '/' . $variant_slug;
	if ( ! is_dir( $source_icons ) || ( ! is_dir( $target_icons ) && ! mkdir( $target_icons, 0755, true ) ) ) {
		fwrite( STDERR, "Could not read or create variant directory.\n" );
		exit( 1 );
	}

	$manifest_variants[] = array(
		'slug'      => $variant_slug,
		'label'     => $variant['label'],
		'iconCount' => 0,
	);
	$variant_index       = count( $manifest_variants ) - 1;
	$files               = glob( $source_icons . '/*.svg' );
	sort( $files );

	foreach ( $files as $file ) {
		$slug      = basename( $file, '.svg' );
		$is_filled = '-fill' === substr( $slug, -5 );
		if ( 'bootstrap-icons' === $library && ( ( 'filled' === $variant_slug ) !== $is_filled ) ) {
			continue;
		}

		$canonical_slug = $slug;
		$metadata       = array();
		if ( 'font-awesome' === $library ) {
			$canonical_slug = $font_awesome_aliases[ $slug ] ?? $font_awesome_canonical[ $slug ] ?? '';
			$metadata       = '' !== $canonical_slug && isset( $font_awesome_metadata['icons'][ $canonical_slug ] ) && is_array( $font_awesome_metadata['icons'][ $canonical_slug ] ) ? $font_awesome_metadata['icons'][ $canonical_slug ] : array();
			$free_styles    = array_map( 'strval', (array) ( $metadata['free'] ?? array() ) );
			if ( '' === $canonical_slug || ! in_array( $variant_slug, $free_styles, true ) ) {
				$skipped[] = $variant_slug . '/' . $slug . ': not part of the Font Awesome Free metadata';
				continue;
			}
		}

		try {
			$svg = CollectionBuild::normalize_svg( file_get_contents( $file ) );
		} catch ( RuntimeException $exception ) {
			$skipped[] = $variant_slug . '/' . $slug . ': ' . $exception->getMessage();
			continue;
		}

		$relative = $variant_slug . '/' . $slug . '.svg';
		$target   = $target_dir . '/' . $relative;
		file_put_contents( $target, $svg . "\n" );

		$categories = 'font-awesome' === $library ? array_values( array_unique( $font_awesome_categories_by_icon[ $canonical_slug ] ?? array() ) ) : array();
		if ( 'font-awesome' === $library && 'brands' === $variant_slug ) {
			$categories[] = 'brands';
		}
		if ( empty( $categories ) ) {
			$categories = array( 'general' );
		}
		$categories = array_values( array_unique( array_filter( array_map( 'strval', $categories ) ) ) );

		if ( 'font-awesome' === $library ) {
			foreach ( $categories as $category_slug ) {
				$category_members[ $category_slug ][ $canonical_slug ] = true;
			}
		}

		$label = ucwords( str_replace( '-', ' ', $slug ) );
		if ( 'font-awesome' === $library && $slug === $canonical_slug && ! empty( $metadata['label'] ) ) {
			$label = (string) $metadata['label'];
		}
		$keywords = array_merge(
			explode( '-', $slug ),
			$canonical_slug !== $slug ? explode( '-', $canonical_slug ) : array(),
			'font-awesome' === $library ? (array) ( $metadata['search']['terms'] ?? array() ) : array()
		);
		$keywords = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $keyword ) {
							return strtolower( trim( (string) $keyword ) );
						},
						$keywords
					)
				)
			)
		);

		++$manifest_variants[ $variant_index ]['iconCount'];
		$icons[] = array(
			'id'           => $library . '/' . $variant_slug . '/' . $slug,
			'coreIconName' => $library . '/' . $slug . '-' . $variant_slug,
			'label'        => $label,
			'variant'      => $variant_slug,
			'categories'   => $categories,
			'keywords'     => $keywords,
			'path'         => $relative,
			'sha256'       => hash_file( 'sha256', $target ),
		);
	}
}

$manifest = array(
	'schemaVersion' => CollectionBuild::SCHEMA_VERSION,
	'slug'          => $library,
	'name'          => $config['name'],
	'description'   => $config['description'],
	'version'       => $version,
	'license'       => array(
		'name' => $config['license'],
		'url'  => $config['license_url'],
	),
	'source'        => array(
		'name'     => $config['source_name'],
		'url'      => $config['source_url'],
		'revision' => $revision,
	),
	'variants'      => $manifest_variants,
	'icons'         => $icons,
);

if ( 'font-awesome' === $library ) {
	$manifest['categories'] = array();
	foreach ( $font_awesome_metadata['categories'] as $category ) {
		$category_slug = (string) ( $category['slug'] ?? '' );
		if ( '' === $category_slug || empty( $category_members[ $category_slug ] ) ) {
			continue;
		}
		$manifest['categories'][] = array(
			'slug'      => $category_slug,
			'label'     => (string) ( $category['label'] ?? ucwords( str_replace( '-', ' ', $category_slug ) ) ),
			'iconCount' => count( $category_members[ $category_slug ] ),
		);
	}

	foreach ( array(
		'brands'  => 'Brands',
		'general' => 'General',
	) as $category_slug => $label ) {
		if ( empty( $category_members[ $category_slug ] ) ) {
			continue;
		}
		$manifest['categories'][] = array(
			'slug'      => $category_slug,
			'label'     => $label,
			'iconCount' => count( $category_members[ $category_slug ] ),
		);
	}
}

$errors = CollectionBuild::validate_manifest( $manifest, $target_dir );
if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

file_put_contents( $target_dir . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
printf( "Imported %d icons into %s.\n", count( $icons ), $target_dir );
if ( $skipped ) {
	fwrite( STDERR, sprintf( "Skipped %d Core-incompatible icons. First examples: %s\n", count( $skipped ), implode( ', ', array_slice( $skipped, 0, 5 ) ) ) );
}
