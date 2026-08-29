<?php
/**
 * Custom icon persistence.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores sanitized custom icons outside the Media Library.
 */
class CustomIconRepository {
	const COLLECTION_SLUG = 'custom-icons';
	const OPTION_ICONS     = 'icon_library_custom_icons';

	/** @var SvgSanitizer */
	private $sanitizer;

	/**
	 * @param SvgSanitizer $sanitizer SVG sanitizer.
	 */
	public function __construct( SvgSanitizer $sanitizer ) {
		$this->sanitizer = $sanitizer;
	}

	/** @return array<string,array> */
	public function get_icons() {
		$icons = get_option( self::OPTION_ICONS, array() );
		return is_array( $icons ) ? $icons : array();
	}

	/** @return array|null */
	public function get_manifest() {
		$icons = $this->get_icons();
		if ( empty( $icons ) ) {
			return null;
		}

		return array(
			'schemaVersion' => 2,
			'slug'          => self::COLLECTION_SLUG,
			'name'          => __( 'Custom Icons', 'icon-library' ),
			'description'   => __( 'Icons uploaded locally by site administrators.', 'icon-library' ),
			'version'       => 'site',
			'license'       => array( 'name' => __( 'Site provided', 'icon-library' ), 'url' => '' ),
			'source'        => array( 'name' => __( 'This site', 'icon-library' ), 'url' => '' ),
			'variants'      => array( array( 'slug' => 'custom', 'label' => __( 'Custom', 'icon-library' ) ) ),
			'icons'         => array_values( $icons ),
		);
	}

	/**
	 * Creates an icon after strict validation.
	 *
	 * @param string $name  Stable icon name.
	 * @param string $label Display label.
	 * @param string $svg   SVG markup.
	 * @return array|WP_Error
	 */
	public function create( $name, $label, $svg ) {
		$raw_name = is_string( $name ) ? trim( $name ) : '';
		$name     = sanitize_key( $raw_name );
		$label    = sanitize_text_field( $label );

		if ( $raw_name !== $name || ! $name || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) {
			return new WP_Error( 'icon_library_custom_name_invalid', __( 'Use a lowercase icon name containing letters, numbers, and hyphens.', 'icon-library' ) );
		}
		if ( '' === $label ) {
			return new WP_Error( 'icon_library_custom_label_invalid', __( 'An icon label is required.', 'icon-library' ) );
		}

		$icons = $this->get_icons();
		if ( isset( $icons[ $name ] ) ) {
			return new WP_Error( 'icon_library_custom_duplicate', __( 'An icon with that name already exists.', 'icon-library' ) );
		}

		$sanitized = $this->sanitizer->sanitize_custom( $svg );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		$directory = $this->get_directory( true );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$path = $directory . '/' . $name . '.svg';
		$temp = tempnam( $directory, '.icon-library-' );
		if ( ! $temp || false === file_put_contents( $temp, $sanitized, LOCK_EX ) || ! rename( $temp, $path ) ) {
			if ( $temp && file_exists( $temp ) ) {
				unlink( $temp );
			}
			return new WP_Error( 'icon_library_custom_write_failed', __( 'The sanitized icon could not be stored.', 'icon-library' ) );
		}

		$icons[ $name ] = array(
			'id'           => self::COLLECTION_SLUG . '/custom/' . $name,
			'coreIconName' => self::COLLECTION_SLUG . '/' . $name,
			'label'        => $label,
			'variant'      => 'custom',
			'categories'   => array( 'custom' ),
			'keywords'     => preg_split( '/[-\s]+/', $name ),
			'path'         => $name . '.svg',
			'sha256'       => hash( 'sha256', $sanitized ),
		);

		if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
			unlink( $path );
			return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be stored.', 'icon-library' ) );
		}

		return $icons[ $name ];
	}

	/** @return string|null */
	public function get_file_path( $relative_path ) {
		$name = basename( (string) $relative_path, '.svg' );
		if ( $name . '.svg' !== $relative_path || ! isset( $this->get_icons()[ $name ] ) ) {
			return null;
		}
		$directory = $this->get_directory( false );
		if ( is_wp_error( $directory ) ) {
			return null;
		}
		$path = $directory . '/' . $name . '.svg';
		return is_readable( $path ) ? $path : null;
	}

	/** @return string|null */
	public function get_svg_content( $relative_path ) {
		$path = $this->get_file_path( $relative_path );
		$content = $path ? file_get_contents( $path ) : false;
		return is_string( $content ) ? $content : null;
	}

	/**
	 * Updates display metadata without changing the stable registered name.
	 *
	 * @param string $name  Stable name.
	 * @param string $label New label.
	 * @return array|WP_Error
	 */
	public function update_label( $name, $label ) {
		$name  = sanitize_key( $name );
		$label = sanitize_text_field( $label );
		$icons = $this->get_icons();
		if ( ! isset( $icons[ $name ] ) ) {
			return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'icon-library' ), array( 'status' => 404 ) );
		}
		if ( '' === $label ) {
			return new WP_Error( 'icon_library_custom_label_invalid', __( 'An icon label is required.', 'icon-library' ) );
		}
		$icons[ $name ]['label'] = $label;
		if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
			$current = $this->get_icons();
			if ( ! isset( $current[ $name ] ) || $label !== $current[ $name ]['label'] ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'icon-library' ) );
			}
		}
		return $icons[ $name ];
	}

	/**
	 * Deletes an icon. Existing blocks referencing it will no longer render.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function delete( $name ) {
		$name  = sanitize_key( $name );
		$icons = $this->get_icons();
		if ( ! isset( $icons[ $name ] ) ) {
			return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'icon-library' ), array( 'status' => 404 ) );
		}
		unset( $icons[ $name ] );
		if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
			return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be deleted.', 'icon-library' ) );
		}
		$directory = $this->get_directory( false );
		$path      = is_wp_error( $directory ) ? '' : $directory . '/' . $name . '.svg';
		if ( $path && file_exists( $path ) ) {
			unlink( $path );
		}
		return true;
	}

	/** @return string|WP_Error */
	private function get_directory( $create ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'icon_library_upload_directory', $uploads['error'] );
		}
		$directory = trailingslashit( $uploads['basedir'] ) . 'icon-library/custom-icons';
		if ( $create && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The custom icon directory could not be created.', 'icon-library' ) );
		}
		if ( ! is_dir( $directory ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The custom icon directory does not exist.', 'icon-library' ) );
		}
		return $directory;
	}
}
