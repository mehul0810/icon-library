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
	 * Request-local lightweight metadata.
	 *
	 * @var array
	 */
	private $metadata = array();

	/**
	 * Reads generated metadata unless runtime filters require the full manifest.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_metadata( $slug ) {
		if ( ! is_string( $slug ) || ! $this->is_valid_slug( $slug ) ) {
			return null;
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'icon_library_icon_manifest' ) ) {
			return $this->get_manifest( $slug );
		}
		if ( isset( $this->metadata[ $slug ] ) ) {
			return $this->metadata[ $slug ];
		}
		$path = $this->base_dir . $slug . '/metadata.json';
		if ( is_readable( $path ) ) {
			$data = json_decode( file_get_contents( $path ), true );
			if ( is_array( $data ) && ( $data['slug'] ?? null ) === $slug && isset( $data['iconCount'], $data['variants'] ) ) {
				$this->metadata[ $slug ] = $data;
				return $data;
			}
		}
		return $this->get_manifest( $slug );
	}

	/**
	 * In-request resolved collection directories.
	 *
	 * @var array<string,string|null>
	 */
	private $collection_bases = array();

	/**
	 * Discovered collection slugs for this request.
	 *
	 * @var string[]|null
	 */
	private $collection_slugs;

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
		if ( null !== $this->collection_slugs ) {
			return $this->collection_slugs;
		}
		if ( ! is_dir( $this->base_dir ) ) {
			$this->collection_slugs = array();
			return $this->collection_slugs;
		}

		$slugs = array();
		$dirs  = glob( $this->base_dir . '*', GLOB_ONLYDIR );

		if ( ! is_array( $dirs ) ) {
			$this->collection_slugs = array();
			return $this->collection_slugs;
		}

		foreach ( $dirs as $dir ) {
			$slug = basename( $dir );

			if ( $this->is_valid_slug( $slug ) && is_readable( $dir . '/manifest.json' ) ) {
				$slugs[] = $slug;
			}
		}

		sort( $slugs );

		$this->collection_slugs = $slugs;
		return $this->collection_slugs;
	}

	/**
	 * Loads one collection manifest.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_manifest( $slug ) {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';

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

		// Include the package version so deterministic build timestamps cannot
		// preserve an older manifest in a persistent object cache after upgrade.
		$cache_key = 'manifest_' . md5( $this->base_dir ) . '_' . $slug . '_' . ( defined( 'ICON_LIBRARY_VERSION' ) ? ICON_LIBRARY_VERSION : 'dev' );
		$modified  = (int) filemtime( $path );
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
		if ( ! is_string( $collection_slug ) || ! is_string( $relative_path ) ) {
			return null;
		}
		$collection_slug = sanitize_key( $collection_slug );
		$relative_path   = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );

		if ( ! $this->is_valid_slug( $collection_slug ) || false !== strpos( $relative_path, '..' ) ) {
			return null;
		}

		if ( ! array_key_exists( $collection_slug, $this->collection_bases ) ) {
			$this->collection_bases[ $collection_slug ] = realpath( $this->base_dir . $collection_slug );
		}
		$base   = $this->collection_bases[ $collection_slug ];
		$source = false !== $base ? $base . '/' . $relative_path : '';
		$file   = false !== $base ? realpath( $source ) : false;

		if ( false === $base || false === $file || is_link( $source ) || 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) ) {
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
