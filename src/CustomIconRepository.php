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
	const MAX_ICONS       = 500;
	const MAX_BYTES       = 33554432;
	const LOCK_TTL        = 60;
	const OPTION_LOCK     = 'icon_library_custom_icons_lock';
	const COLLECTION_SLUG = 'custom-icons';
	const OPTION_ICONS    = 'icon_library_custom_icons';

	/**
	 * SVG sanitizer.
	 *
	 * @var SvgSanitizer
	 */
	private $sanitizer;

	/**
	 * Token for the current request's metadata lock.
	 *
	 * @var string
	 */
	private $lock_token = '';

	/**
	 * Metadata used to build the cached manifest.
	 *
	 * @var array|null
	 */
	private $manifest_icons;

	/**
	 * Request-local manifest, refreshed when stored metadata changes.
	 *
	 * @var array|null
	 */
	private $manifest;

	/**
	 * Constructor.
	 *
	 * @param SvgSanitizer $sanitizer SVG sanitizer.
	 */
	public function __construct( SvgSanitizer $sanitizer ) {
		$this->sanitizer = $sanitizer;
	}

	/**
	 * Returns stored icon metadata.
	 *
	 * @return array<string,array>
	 */
	public function get_icons() {
		$icons = get_option( self::OPTION_ICONS, array() );
		return is_array( $icons ) ? $icons : array();
	}

	/**
	 * Returns the dynamic custom collection manifest.
	 *
	 * @return array|null
	 */
	public function get_manifest() {
		$icons = $this->get_icons();
		if ( $icons === $this->manifest_icons ) {
			return $this->manifest;
		}
		$this->manifest_icons = $icons;
		if ( empty( $icons ) ) {
			$this->manifest = null;
			return null;
		}

		$this->manifest = array(
			'schemaVersion' => 2,
			'slug'          => self::COLLECTION_SLUG,
			'name'          => __( 'Custom Icons', 'icon-library' ),
			'description'   => __( 'Icons uploaded locally by site administrators.', 'icon-library' ),
			'version'       => 'site',
			'license'       => array(
				'name' => __( 'Site provided', 'icon-library' ),
				'url'  => '',
			),
			'source'        => array(
				'name' => __( 'This site', 'icon-library' ),
				'url'  => '',
			),
			'variants'      => array(
				array(
					'slug'  => 'custom',
					'label' => __( 'Custom', 'icon-library' ),
				),
			),
			'icons'         => array_values( $icons ),
		);
		return $this->manifest;
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
		$name     = $this->normalize_name( $raw_name );
		$label    = is_string( $label ) ? sanitize_text_field( $label ) : '';

		if ( $raw_name !== $name || ! $name || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) {
			return new WP_Error( 'icon_library_custom_name_invalid', __( 'Use a lowercase icon name containing letters, numbers, and hyphens.', 'icon-library' ), array( 'status' => 400 ) );
		}
		if ( '' === $label ) {
			return new WP_Error( 'icon_library_custom_label_invalid', __( 'An icon label is required.', 'icon-library' ), array( 'status' => 400 ) );
		}

		$icons = $this->get_icons();
		if ( self::MAX_ICONS <= count( $icons ) ) {
			return new WP_Error( 'icon_library_custom_limit', __( 'The custom icon limit has been reached.', 'icon-library' ), array( 'status' => 409 ) );
		}
		if ( isset( $icons[ $name ] ) ) {
			return new WP_Error( 'icon_library_custom_duplicate', __( 'An icon with that name already exists.', 'icon-library' ), array( 'status' => 409 ) );
		}

		$sanitized = $this->sanitizer->sanitize_custom( $svg );
		if ( is_wp_error( $sanitized ) ) {
			if ( ! is_array( $sanitized->get_error_data() ) || empty( $sanitized->get_error_data()['status'] ) ) {
				$sanitized->add_data( array( 'status' => 400 ) );
			}
			return $sanitized;
		}

		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'icon_library_custom_busy', __( 'Another custom icon change is in progress. Try again.', 'icon-library' ), array( 'status' => 409 ) );
		}

		try {
			$icons = $this->get_icons();
			if ( self::MAX_ICONS <= count( $icons ) || self::MAX_BYTES < $this->get_retained_bytes( $icons ) + strlen( $sanitized ) ) {
				return new WP_Error( 'icon_library_custom_limit', __( 'The custom icon limit has been reached.', 'icon-library' ), array( 'status' => 409 ) );
			}
			if ( isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_duplicate', __( 'An icon with that name already exists.', 'icon-library' ), array( 'status' => 409 ) );
			}

			$directory = $this->get_directory( true );
			if ( is_wp_error( $directory ) ) {
				return $directory;
			}

			$path = $directory . '/' . $name . '.svg';
			$temp = tempnam( $directory, '.icon-library-' );
			// Atomic same-directory replacement in the plugin-owned uploads directory.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! $temp || false === file_put_contents( $temp, $sanitized, LOCK_EX ) || ! rename( $temp, $path ) ) {
				if ( $temp && file_exists( $temp ) ) {
					wp_delete_file( $temp );
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
				'bytes'        => strlen( $sanitized ),
			);

			if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
				wp_delete_file( $path );
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be stored.', 'icon-library' ) );
			}

			return $icons[ $name ];
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Resolves one stored icon file.
	 *
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_file_path( $relative_path ) {
		if ( ! is_string( $relative_path ) ) {
			return null;
		}
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

	/**
	 * Reads one stored icon.
	 *
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_svg_content( $relative_path ) {
		$path    = $this->get_file_path( $relative_path );
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
		$name  = $this->normalize_name( $name );
		$label = is_string( $label ) ? sanitize_text_field( $label ) : '';
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'icon_library_custom_busy', __( 'Another custom icon change is in progress. Try again.', 'icon-library' ), array( 'status' => 409 ) );
		}
		try {
			$icons = $this->get_icons();
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'icon-library' ), array( 'status' => 404 ) );
			}
			if ( '' === $label ) {
				return new WP_Error( 'icon_library_custom_label_invalid', __( 'An icon label is required.', 'icon-library' ), array( 'status' => 400 ) );
			}
			$icons[ $name ]['label'] = $label;
			if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
				$current = $this->get_icons();
				if ( ! isset( $current[ $name ] ) || $label !== $current[ $name ]['label'] ) {
					return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'icon-library' ), array( 'status' => 500 ) );
				}
			}
			return $icons[ $name ];
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Archives an icon so existing blocks continue to render.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function delete( $name ) {
		$name = $this->normalize_name( $name );
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'icon_library_custom_busy', __( 'Another custom icon change is in progress. Try again.', 'icon-library' ), array( 'status' => 409 ) );
		}
		try {
			$icons = $this->get_icons();
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'icon-library' ), array( 'status' => 404 ) );
			}
			if ( ! empty( $icons[ $name ]['archived'] ) ) {
				return true;
			}
			$icons[ $name ]['archived'] = true;
			if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
				$current = $this->get_icons();
				if ( empty( $current[ $name ]['archived'] ) ) {
					return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'icon-library' ), array( 'status' => 500 ) );
				}
			}
			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Restores an archived icon for new selections.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function restore( $name ) {
		$name = $this->normalize_name( $name );
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'icon_library_custom_busy', __( 'Another custom icon change is in progress. Try again.', 'icon-library' ), array( 'status' => 409 ) );
		}
		try {
			$icons = $this->get_icons();
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'icon-library' ), array( 'status' => 404 ) );
			}
			if ( empty( $icons[ $name ]['archived'] ) ) {
				return true;
			}
			unset( $icons[ $name ]['archived'] );
			if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'icon-library' ), array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Permanently removes an archived icon and its stored SVG.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function purge( $name ) {
		$name = $this->normalize_name( $name );
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'icon_library_custom_busy', __( 'Another custom icon change is in progress. Try again.', 'icon-library' ), array( 'status' => 409 ) );
		}
		try {
			$icons    = $this->get_icons();
			$original = $icons;
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'icon-library' ), array( 'status' => 404 ) );
			}
			if ( empty( $icons[ $name ]['archived'] ) ) {
				return new WP_Error( 'icon_library_custom_not_archived', __( 'Archive the icon before permanently deleting it.', 'icon-library' ), array( 'status' => 409 ) );
			}
			$path = $this->get_file_path( $name . '.svg' );
			unset( $icons[ $name ] );
			if ( ! update_option( self::OPTION_ICONS, $icons, false ) ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'icon-library' ), array( 'status' => 500 ) );
			}
			if ( $path ) {
				wp_delete_file( $path );
				if ( file_exists( $path ) ) {
					update_option( self::OPTION_ICONS, $original, false );
					return new WP_Error( 'icon_library_custom_delete_failed', __( 'The stored icon could not be removed.', 'icon-library' ), array( 'status' => 500 ) );
				}
			}
			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquires the atomic option lock used for metadata mutations.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$existing = get_option( self::OPTION_LOCK, false );
		$started  = is_array( $existing ) && is_scalar( $existing['started'] ?? null ) ? absint( $existing['started'] ) : ( is_scalar( $existing ) ? absint( $existing ) : 0 );
		if ( $started && time() - $started > self::LOCK_TTL ) {
			delete_option( self::OPTION_LOCK );
		}

		$this->lock_token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'icon-library-', true );
		if ( ! add_option(
			self::OPTION_LOCK,
			array(
				'token'   => $this->lock_token,
				'started' => time(),
			),
			'',
			false
		) ) {
			$this->lock_token = '';
			return false;
		}
		return true;
	}

	/** Releases the custom icon metadata lock. */
	private function release_lock() {
		$lock = get_option( self::OPTION_LOCK, false );
		if ( is_array( $lock ) && isset( $lock['token'] ) && is_string( $lock['token'] ) && '' !== $lock['token'] && '' !== $this->lock_token && hash_equals( $lock['token'], $this->lock_token ) ) {
			delete_option( self::OPTION_LOCK );
		}
		$this->lock_token = '';
	}

	/**
	 * Counts bytes retained by active and archived icon files.
	 *
	 * @param array $icons Stored icon metadata.
	 * @return int
	 */
	private function get_retained_bytes( $icons ) {
		$total     = 0;
		$directory = $this->get_directory( false );
		$directory = is_wp_error( $directory ) ? null : $directory;
		foreach ( (array) $icons as $icon ) {
			if ( ! is_array( $icon ) ) {
				continue;
			}
			if ( isset( $icon['bytes'] ) && is_numeric( $icon['bytes'] ) ) {
				$total += max( 0, absint( $icon['bytes'] ) );
				continue;
			}
			$relative_path = isset( $icon['path'] ) && is_string( $icon['path'] ) ? $icon['path'] : '';
			if ( $directory && '' !== $relative_path && basename( $relative_path ) === $relative_path && '.svg' === substr( $relative_path, -4 ) ) {
				$path  = $directory . '/' . $relative_path;
				$bytes = is_file( $path ) ? filesize( $path ) : false;
				if ( false !== $bytes ) {
					$total += $bytes;
				}
			}
		}
		return $total;
	}

	/**
	 * Normalizes a custom icon name without accepting array/object input.
	 *
	 * @param mixed $name Candidate name.
	 * @return string
	 */
	private function normalize_name( $name ) {
		return is_string( $name ) ? sanitize_key( $name ) : '';
	}

	/**
	 * Resolves the plugin-owned upload directory.
	 *
	 * @param bool $create Whether to create the directory.
	 * @return string|WP_Error
	 */
	private function get_directory( $create ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'icon_library_upload_directory', $uploads['error'] );
		}
		$base_path = is_string( $uploads['basedir'] ?? null ) ? $uploads['basedir'] : '';
		if ( $create && $base_path && ! is_dir( $base_path ) && ! wp_mkdir_p( $base_path ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The uploads directory could not be created.', 'icon-library' ) );
		}
		$base = realpath( $base_path );
		if ( false === $base || ! is_dir( $base ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The uploads directory is not available.', 'icon-library' ) );
		}
		$directory = $base . '/icon-library/custom-icons';
		if ( $create && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The custom icon directory could not be created.', 'icon-library' ) );
		}
		$resolved = realpath( $directory );
		if ( false === $resolved || is_link( $directory ) || 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR ) || ! is_dir( $resolved ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The custom icon directory does not exist.', 'icon-library' ) );
		}
		return $directory;
	}
}
