<?php
/**
 * Icon collection registry.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns collection metadata, activation state, and icon discovery.
 */
class CollectionRegistry {
	/**
	 * Manifest loader.
	 *
	 * @var ManifestLoader
	 */
	private $manifest_loader;

	/**
	 * Custom icon repository.
	 *
	 * @var CustomIconRepository|null
	 */
	private $custom_icons;

	/**
	 * Constructor.
	 *
	 * @param ManifestLoader            $manifest_loader Manifest loader.
	 * @param CustomIconRepository|null $custom_icons    Custom icon repository.
	 */
	public function __construct( ManifestLoader $manifest_loader, ?CustomIconRepository $custom_icons = null ) {
		$this->manifest_loader = $manifest_loader;
		$this->custom_icons    = $custom_icons;
	}

	/**
	 * Returns all discovered collections.
	 *
	 * @return array[]
	 */
	public function get_collections() {
		$collections = array();
		$enabled     = $this->get_enabled_collection_slugs();

		foreach ( $this->get_available_collection_slugs() as $slug ) {
			$manifest = $this->get_manifest( $slug );

			if ( ! is_array( $manifest ) ) {
				continue;
			}

			$collections[ $slug ] = $this->prepare_collection_summary( $manifest, in_array( $slug, $enabled, true ) );
		}

		/**
		 * Filters available icon collections.
		 *
		 * @param array[] $collections Collection summaries keyed by slug.
		 */
		return apply_filters( 'icon_library_collections', $collections );
	}

	/**
	 * Returns one collection summary.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_collection( $slug ) {
		$collections = $this->get_collections();
		$slug        = sanitize_key( $slug );

		return isset( $collections[ $slug ] ) ? $collections[ $slug ] : null;
	}

	/**
	 * Returns collection slugs owned by this plugin.
	 *
	 * @return string[]
	 */
	public function get_available_collection_slugs() {
		$slugs = $this->manifest_loader->get_collection_slugs();
		if ( $this->custom_icons && $this->custom_icons->get_manifest() ) {
			$slugs[] = CustomIconRepository::COLLECTION_SLUG;
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Returns one collection manifest.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_manifest( $slug ) {
		if ( CustomIconRepository::COLLECTION_SLUG === $slug && $this->custom_icons ) {
			return $this->custom_icons->get_manifest();
		}
		return $this->manifest_loader->get_manifest( $slug );
	}

	/**
	 * Resolves one collection SVG path.
	 *
	 * @param string $slug          Collection slug.
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_svg_path( $slug, $relative_path ) {
		if ( CustomIconRepository::COLLECTION_SLUG === $slug && $this->custom_icons ) {
			return $this->custom_icons->get_file_path( $relative_path );
		}
		return $this->manifest_loader->get_svg_path( $slug, $relative_path );
	}

	/**
	 * Reads one collection SVG.
	 *
	 * @param string $slug          Collection slug.
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_svg_content( $slug, $relative_path ) {
		if ( CustomIconRepository::COLLECTION_SLUG === $slug && $this->custom_icons ) {
			return $this->custom_icons->get_svg_content( $relative_path );
		}
		return $this->manifest_loader->get_svg_content( $slug, $relative_path );
	}

	/**
	 * Returns enabled collection slugs.
	 *
	 * @return string[]
	 */
	public function get_enabled_collection_slugs() {
		$available = $this->get_available_collection_slugs();
		$enabled   = get_option( Plugin::OPTION_ENABLED_COLLECTIONS, array( 'heroicons' ) );

		if ( ! is_array( $enabled ) ) {
			$enabled = array();
		}

		$enabled = array_values(
			array_intersect(
				array_map( 'sanitize_key', $enabled ),
				$available
			)
		);

		if ( in_array( CustomIconRepository::COLLECTION_SLUG, $available, true ) ) {
			$enabled[] = CustomIconRepository::COLLECTION_SLUG;
			$enabled   = array_values( array_unique( $enabled ) );
		}

		/**
		 * Filters enabled icon collections.
		 *
		 * @param string[] $enabled Enabled collection slugs.
		 */
		return apply_filters( 'icon_library_enabled_collections', $enabled );
	}

	/**
	 * Updates collection activation state.
	 *
	 * @param string $slug    Collection slug.
	 * @param bool   $enabled Whether the collection should be enabled.
	 * @return bool
	 */
	public function set_collection_enabled( $slug, $enabled ) {
		$slug = sanitize_key( $slug );
		if ( CustomIconRepository::COLLECTION_SLUG === $slug ) {
			return false;
		}

		if ( null === $this->get_collection( $slug ) ) {
			return false;
		}

		$enabled_slugs = $this->get_enabled_collection_slugs();

		if ( $enabled ) {
			$enabled_slugs[] = $slug;
		} else {
			$enabled_slugs = array_diff( $enabled_slugs, array( $slug ) );
		}

		$enabled_slugs = array_values( array_unique( array_map( 'sanitize_key', $enabled_slugs ) ) );

		return update_option( Plugin::OPTION_ENABLED_COLLECTIONS, $enabled_slugs, false );
	}

	/**
	 * Returns icons matching query args.
	 *
	 * @param array $args Query args.
	 * @return array[]
	 */
	public function get_icons( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'collection' => '',
				'variant'    => '',
				'category'   => '',
				'search'     => '',
				'enabled'    => null,
				'page'       => 1,
				'per_page'   => 100,
			)
		);

		$collection_filter = sanitize_key( $args['collection'] );
		$variant_filter    = sanitize_key( $args['variant'] );
		$category_filter   = sanitize_key( $args['category'] );
		$search            = strtolower( sanitize_text_field( $args['search'] ) );
		$page              = max( 1, absint( $args['page'] ) );
		$per_page          = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$enabled_slugs     = $this->get_enabled_collection_slugs();
		$icons             = array();

		foreach ( $this->get_collections() as $collection_slug => $collection ) {
			if ( $collection_filter && $collection_filter !== $collection_slug ) {
				continue;
			}

			if ( true === $args['enabled'] && ! in_array( $collection_slug, $enabled_slugs, true ) ) {
				continue;
			}

			if ( false === $args['enabled'] && in_array( $collection_slug, $enabled_slugs, true ) ) {
				continue;
			}

			$manifest = $this->get_manifest( $collection_slug );

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				if ( ! is_array( $icon ) || empty( $icon['id'] ) || empty( $icon['coreIconName'] ) ) {
					continue;
				}

				if ( $variant_filter && sanitize_key( $icon['variant'] ?? '' ) !== $variant_filter ) {
					continue;
				}

				$categories = isset( $icon['categories'] ) && is_array( $icon['categories'] ) ? $icon['categories'] : array();
				if ( $category_filter && ! in_array( $category_filter, array_map( 'sanitize_key', $categories ), true ) ) {
					continue;
				}

				if ( $search && ! $this->icon_matches_search( $icon, $search ) ) {
					continue;
				}

				$icons[] = array_merge(
					$icon,
					array(
						'collection'        => $collection_slug,
						'collectionLabel'   => $collection['name'],
						'collectionEnabled' => $collection['enabled'],
					)
				);
			}
		}

		return array_slice( $icons, ( $page - 1 ) * $per_page, $per_page );
	}

	/**
	 * Finds a collection slug for a core icon name.
	 *
	 * @param string $core_icon_name Core icon registry name.
	 * @return string|null
	 */
	public function get_collection_slug_for_core_icon_name( $core_icon_name ) {
		foreach ( $this->get_available_collection_slugs() as $collection_slug ) {
			$manifest = $this->get_manifest( $collection_slug );

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				if ( isset( $icon['coreIconName'] ) && $core_icon_name === $icon['coreIconName'] ) {
					return $collection_slug;
				}
			}
		}

		return null;
	}

	/**
	 * Checks if an icon matches a search term.
	 *
	 * @param array  $icon   Icon manifest row.
	 * @param string $search Lowercase search term.
	 * @return bool
	 */
	private function icon_matches_search( $icon, $search ) {
		$haystack = array(
			$icon['id'] ?? '',
			$icon['coreIconName'] ?? '',
			$icon['label'] ?? '',
			$icon['variant'] ?? '',
		);

		foreach ( array( 'keywords', 'categories' ) as $key ) {
			if ( isset( $icon[ $key ] ) && is_array( $icon[ $key ] ) ) {
				$haystack = array_merge( $haystack, $icon[ $key ] );
			}
		}

		return false !== strpos( strtolower( implode( ' ', array_map( 'strval', $haystack ) ) ), $search );
	}

	/**
	 * Prepares collection summary data.
	 *
	 * @param array $manifest Manifest data.
	 * @param bool  $enabled  Whether the collection is enabled.
	 * @return array
	 */
	private function prepare_collection_summary( $manifest, $enabled ) {
		$icons    = isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ? $manifest['icons'] : array();
		$variants = isset( $manifest['variants'] ) && is_array( $manifest['variants'] ) ? $manifest['variants'] : array();

		return array(
			'slug'        => sanitize_key( $manifest['slug'] ?? '' ),
			'name'        => sanitize_text_field( $manifest['name'] ?? '' ),
			'description' => sanitize_text_field( $manifest['description'] ?? '' ),
			'version'     => sanitize_text_field( $manifest['version'] ?? '' ),
			'license'     => $manifest['license'] ?? array(),
			'source'      => $manifest['source'] ?? array(),
			'variants'    => $variants,
			'iconCount'   => count( $icons ),
			'enabled'     => (bool) $enabled,
		);
	}
}
