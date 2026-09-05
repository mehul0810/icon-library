<?php
/**
 * Shared current, variant and legacy icon name resolution.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Produces a name index without doing any registration or file I/O. */
class IconNameIndex {
	/**
	 * Indexes one manifest for both rendering and catalog lookup.
	 *
	 * @param string $library Library slug.
	 * @param array  $manifest Collection manifest.
	 * @return array
	 */
	public static function build( $library, $manifest ) {
		$index = array();
		foreach ( (array) ( $manifest['icons'] ?? array() ) as $icon ) {
			if ( ! is_array( $icon ) || ! is_string( $icon['coreIconName'] ?? null ) || 1 !== preg_match( '/^' . preg_quote( $library, '/' ) . '\/[a-z0-9][a-z0-9_-]*$/', $icon['coreIconName'] ) ) {
				continue;
			}
			$name           = $icon['coreIconName'];
			$variant        = is_string( $icon['variant'] ?? null ) ? sanitize_key( $icon['variant'] ) : '';
			$entry          = array(
				'library'  => $library,
				'manifest' => $manifest,
				'icon'     => $icon,
			);
			$index[ $name ] = $entry;
			if ( $variant ) {
				$style = $library . '-' . $variant;
				$index[ $style . substr( $name, strlen( $library ) ) ] = array_merge(
					$entry,
					array(
						'style'   => $style,
						'variant' => $variant,
					)
				);
			}
			if ( 'heroicons' === $library && 'solid' === $variant && is_string( $icon['path'] ?? null ) ) {
				$base = basename( $icon['path'], '.svg' );
				if ( 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base ) ) {
					foreach ( array( '24-solid', '20-solid', '16-solid' ) as $legacy ) {
						$index[ $library . '/' . $base . '-' . $legacy ] = array_merge( $entry, array( 'legacy' => true ) );
					}
				}
			}
		}
		return $index;
	}
}
