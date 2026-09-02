<?php
/**
 * Collection manifest loading.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads bundled icon collection manifests and SVG files.
 */
class ManifestLoader {
	/**
	 * Base directory for bundled icon collections.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * In-request manifest cache.
	 *
	 * @var array
	 */
	private $manifests = array();

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Base icon asset directory.
	 */
	public function __construct( $base_dir ) {
		$this->base_dir = trailingslashit( $base_dir );
	}

	/**
	 * Returns available collection slugs.
	 *
	 * @return string[]
	 */
	public function get_collection_slugs() {
		if ( ! is_dir( $this->base_dir ) ) {
			return array();
		}

		$slugs = array();
		$dirs  = glob( $this->base_dir . '*', GLOB_ONLYDIR );

		if ( ! is_array( $dirs ) ) {
			return array();
		}

		foreach ( $dirs as $dir ) {
			$slug = basename( $dir );

			if ( $this->is_valid_slug( $slug ) && is_readable( $dir . '/manifest.json' ) ) {
				$slugs[] = $slug;
			}
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * Loads one collection manifest.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_manifest( $slug ) {
		$slug = sanitize_key( $slug );

		if ( ! $this->is_valid_slug( $slug ) ) {
			return null;
		}

		if ( isset( $this->manifests[ $slug ] ) ) {
			return $this->manifests[ $slug ];
		}

		$path = $this->get_manifest_path( $slug );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$cache_key = 'manifest_' . $slug;
		$modified  = filemtime( $path );
		$cached    = wp_cache_get( $cache_key, 'icon_library' );
		$manifest  = is_array( $cached ) && isset( $cached['modified'], $cached['manifest'] ) && $modified === $cached['modified'] ? $cached['manifest'] : false;

		if ( false === $manifest ) {
			if ( function_exists( 'wp_json_file_decode' ) ) {
				$manifest = wp_json_file_decode( $path, array( 'associative' => true ) );
			} else {
				$raw      = file_get_contents( $path );
				$manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
			}

			if ( ! is_array( $manifest ) || empty( $manifest['slug'] ) || $slug !== $manifest['slug'] ) {
				return null;
			}

			wp_cache_set(
				$cache_key,
				array(
					'modified' => $modified,
					'manifest' => $manifest,
				),
				'icon_library'
			);
		}

		/**
		 * Filters a loaded icon collection manifest for the current request.
		 *
		 * @param array  $manifest Manifest data.
		 * @param string $slug     Collection slug.
		 * @param string $path     Manifest file path.
		 */
		$manifest = apply_filters( 'icon_library_icon_manifest', $manifest, $slug, $path );

		if ( ! is_array( $manifest ) || empty( $manifest['slug'] ) || $slug !== $manifest['slug'] ) {
			return null;
		}

		$this->manifests[ $slug ] = $manifest;

		return $manifest;
	}

	/**
	 * Returns sanitized SVG file contents from a manifest path.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param string $relative_path   Manifest-relative SVG path.
	 * @return string|null
	 */
	public function get_svg_content( $collection_slug, $relative_path ) {
		$file = $this->get_svg_path( $collection_slug, $relative_path );

		if ( null === $file ) {
			return null;
		}

		$content = file_get_contents( $file );

		return is_string( $content ) ? $content : null;
	}

	/**
	 * Returns an absolute validated SVG path for lazy Core registration.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param string $relative_path   Manifest-relative SVG path.
	 * @return string|null
	 */
	public function get_svg_path( $collection_slug, $relative_path ) {
		$collection_slug = sanitize_key( $collection_slug );
		$relative_path   = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );

		if ( ! $this->is_valid_slug( $collection_slug ) || false !== strpos( $relative_path, '..' ) ) {
			return null;
		}

		$base = realpath( $this->base_dir . $collection_slug );
		$file = realpath( $this->base_dir . $collection_slug . '/' . $relative_path );

		if ( false === $base || false === $file || 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		if ( ! is_readable( $file ) || 'svg' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		return $file;
	}

	/**
	 * Returns the manifest path for a collection.
	 *
	 * @param string $slug Collection slug.
	 * @return string
	 */
	private function get_manifest_path( $slug ) {
		return $this->base_dir . $slug . '/manifest.json';
	}

	/**
	 * Checks collection slug shape.
	 *
	 * @param string $slug Collection slug.
	 * @return bool
	 */
	private function is_valid_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
	}
}
