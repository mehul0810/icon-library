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
		$slug     = sanitize_key( $slug );
		$manifest = $this->get_manifest( $slug );
		if ( ! is_array( $manifest ) ) {
			return null;
		}

		$collections = apply_filters(
			'icon_library_collections',
			array(
				$slug => $this->prepare_collection_summary( $manifest, in_array( $slug, $this->get_enabled_collection_slugs(), true ) ),
			)
		);

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
		$enabled   = get_option( Plugin::OPTION_ENABLED_COLLECTIONS, array() );

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
		$is_enabled    = in_array( $slug, $enabled_slugs, true );
		if ( (bool) $enabled === $is_enabled ) {
			return true;
		}

		if ( $enabled ) {
			$enabled_slugs[] = $slug;
		} else {
			$enabled_slugs = array_diff( $enabled_slugs, array( $slug ) );
		}

		$enabled_slugs = array_values( array_unique( array_map( 'sanitize_key', $enabled_slugs ) ) );

		if ( update_option( Plugin::OPTION_ENABLED_COLLECTIONS, $enabled_slugs, false ) ) {
			return true;
		}

		$stored = get_option( Plugin::OPTION_ENABLED_COLLECTIONS, array() );
		return is_array( $stored ) && ( in_array( $slug, array_map( 'sanitize_key', $stored ), true ) === (bool) $enabled );
	}

	/**
	 * Returns enabled variants for a collection. Missing state enables the
	 * manifest variants marked as defaults for backwards compatibility.
	 *
	 * @param string $slug Collection slug.
	 * @return string[]
	 */
	public function get_enabled_variants( $slug ) {
		$manifest = $this->get_manifest( sanitize_key( $slug ) );
		if ( ! is_array( $manifest ) || empty( $manifest['variants'] ) ) {
			return array();
		}
		$available = array_values( array_filter( array_map( 'sanitize_key', wp_list_pluck( $manifest['variants'], 'slug' ) ) ) );
		$defaults  = array_values(
			array_filter(
				array_map(
					static function ( $variant ) {
						if ( ! is_array( $variant ) || empty( $variant['slug'] ) || false === ( $variant['defaultEnabled'] ?? true ) ) {
							return null;
						}
						return sanitize_key( $variant['slug'] );
					},
					$manifest['variants']
				)
			)
		);
		$state     = get_option( Plugin::OPTION_ENABLED_VARIANTS, array() );
		if ( ! is_array( $state ) || ! array_key_exists( sanitize_key( $slug ), $state ) ) {
			$enabled = $defaults;
		} else {
			$enabled = is_array( $state[ sanitize_key( $slug ) ] ) ? $state[ sanitize_key( $slug ) ] : array();
			$enabled = array_map( 'sanitize_key', $enabled );
			// The initial Heroicons importer called the 24px Solid style
			// `24-solid`. Keep that saved preference meaningful after the style
			// taxonomy moves to `solid`.
			if ( 'heroicons' === sanitize_key( $slug ) ) {
				$enabled = array_map(
					static function ( $variant ) {
						return '24-solid' === $variant ? 'solid' : $variant;
					},
					$enabled
				);
			}
			$enabled = array_values( array_intersect( $available, $enabled ) );
		}

		/**
		 * Filters enabled variants for one collection.
		 *
		 * @param string[] $enabled Enabled variant slugs.
		 * @param string   $slug    Collection slug.
		 */
		return apply_filters( 'icon_library_enabled_variants', $enabled, sanitize_key( $slug ) );
	}

	/**
	 * Updates one variant activation state.
	 *
	 * @param string $slug    Collection slug.
	 * @param string $variant Variant slug.
	 * @param bool   $enabled Desired state.
	 * @return bool
	 */
	public function set_variant_enabled( $slug, $variant, $enabled ) {
		$slug       = sanitize_key( $slug );
		$variant    = sanitize_key( $variant );
		$collection = $this->get_collection( $slug );
		if ( ! $collection || ! in_array( $slug, $this->get_enabled_collection_slugs(), true ) ) {
			return false;
		}
		$available_variants = array_map( 'sanitize_key', wp_list_pluck( $collection['variants'], 'slug' ) );
		if ( ! in_array( $variant, $available_variants, true ) ) {
			return false;
		}
		$state = get_option( Plugin::OPTION_ENABLED_VARIANTS, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state[ $slug ] = $this->get_enabled_variants( $slug );
		$is_enabled     = in_array( $variant, $state[ $slug ], true );
		if ( (bool) $enabled === $is_enabled ) {
			return true;
		}
		if ( $enabled ) {
			$state[ $slug ][] = $variant;
		} else {
			$state[ $slug ] = array_diff( $state[ $slug ], array( $variant ) );
		}
		$state[ $slug ] = array_values( array_unique( $state[ $slug ] ) );
		if ( update_option( Plugin::OPTION_ENABLED_VARIANTS, $state, false ) ) {
			return true;
		}

		$stored = get_option( Plugin::OPTION_ENABLED_VARIANTS, array() );
		return isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) && ( in_array( $variant, array_map( 'sanitize_key', $stored[ $slug ] ), true ) === (bool) $enabled );
	}

	/**
	 * Returns one paginated icon query with totals and variant facets.
	 *
	 * @param array $args Query args.
	 * @return array{items:array,total:int,variant_counts:array}
	 */
	public function query_icons( $args = array() ) {
		$args                  = $this->prepare_icon_query_args( $args );
		$variant               = $args['variant'];
		$facet_args            = $args;
		$facet_args['variant'] = '';
		$matching              = $this->get_filtered_icons( $facet_args );
		$counts                = array();

		foreach ( $matching as $icon ) {
			$icon_variant = sanitize_key( $icon['variant'] ?? '' );
			if ( $icon_variant ) {
				$counts[ $icon_variant ] = ( $counts[ $icon_variant ] ?? 0 ) + 1;
			}
		}

		if ( $variant ) {
			$matching = array_values(
				array_filter(
					$matching,
					static function ( $icon ) use ( $variant ) {
						return sanitize_key( $icon['variant'] ?? '' ) === $variant;
					}
				)
			);
		}

		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		return array(
			'items'          => array_slice( $matching, $offset, $args['per_page'] ),
			'total'          => count( $matching ),
			'variant_counts' => $counts,
		);
	}

	/**
	 * Returns icons matching query args.
	 *
	 * @param array $args Query args.
	 * @return array[]
	 */
	public function get_icons( $args = array() ) {
		$query = $this->query_icons( $args );
		return $query['items'];
	}

	/**
	 * Counts icons matching query args before pagination.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count_icons( $args = array() ) {
		$query = $this->query_icons( $args );
		return $query['total'];
	}

	/**
	 * Counts matching icons grouped by variant.
	 *
	 * The variant filter is intentionally ignored so callers can render a
	 * variant selector whose counts reflect the other active filters.
	 *
	 * @param array $args Query args.
	 * @return int[] Counts keyed by variant slug.
	 */
	public function count_icons_by_variant( $args = array() ) {
		$query = $this->query_icons( $args );
		return $query['variant_counts'];
	}

	/**
	 * Normalizes icon query arguments.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	private function prepare_icon_query_args( $args ) {
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

		$args['collection'] = sanitize_key( $args['collection'] );
		$args['variant']    = sanitize_key( $args['variant'] );
		$args['category']   = sanitize_key( $args['category'] );
		$args['search']     = $this->lowercase( sanitize_text_field( $args['search'] ) );
		$args['page']       = max( 1, absint( $args['page'] ) );
		$args['per_page']   = min( 100, max( 1, absint( $args['per_page'] ) ) );

		return $args;
	}

	/**
	 * Returns all icons matching normalized query arguments.
	 *
	 * @param array $args Normalized query args.
	 * @return array[]
	 */
	private function get_filtered_icons( $args ) {
		$collection_filter = $args['collection'];
		$variant_filter    = $args['variant'];
		$category_filter   = $args['category'];
		$search            = $args['search'];
		$enabled_slugs     = $this->get_enabled_collection_slugs();
		$icons             = array();

		$collections = $collection_filter ? array_filter( array( $collection_filter => $this->get_collection( $collection_filter ) ) ) : $this->get_collections();
		foreach ( $collections as $collection_slug => $collection ) {
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
				if ( ! empty( $icon['archived'] ) ) {
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

		return $icons;
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

		return false !== strpos( $this->lowercase( implode( ' ', array_map( 'strval', $haystack ) ) ), $search );
	}

	/**
	 * Lowercases searchable text with Unicode support when available.
	 *
	 * @param string $value Text value.
	 * @return string
	 */
	private function lowercase( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	/**
	 * Prepares collection summary data.
	 *
	 * @param array $manifest Manifest data.
	 * @param bool  $enabled  Whether the collection is enabled.
	 * @return array
	 */
	private function prepare_collection_summary( $manifest, $enabled ) {
		$icons            = isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ? array_values(
			array_filter(
				$manifest['icons'],
				static function ( $icon ) {
					return empty( $icon['archived'] );
				}
			)
		) : array();
		$variants         = isset( $manifest['variants'] ) && is_array( $manifest['variants'] ) ? $manifest['variants'] : array();
		$enabled_variants = $this->get_enabled_variants( $manifest['slug'] ?? '' );
		$variants         = array_map(
			static function ( $variant ) use ( $enabled_variants ) {
				$variant['enabled'] = in_array( sanitize_key( $variant['slug'] ?? '' ), $enabled_variants, true );
				return $variant;
			},
			$variants
		);

		return array(
			'slug'        => sanitize_key( $manifest['slug'] ?? '' ),
			'name'        => sanitize_text_field( $manifest['name'] ?? '' ),
			'description' => sanitize_text_field( $manifest['description'] ?? '' ),
			'version'     => sanitize_text_field( $manifest['version'] ?? '' ),
			'license'     => $manifest['license'] ?? array(),
			'source'      => $manifest['source'] ?? array(),
			'variants'    => $variants,
			'categories'  => isset( $manifest['categories'] ) && is_array( $manifest['categories'] ) ? $manifest['categories'] : array(),
			'iconCount'   => count( $icons ),
			'enabled'     => (bool) $enabled,
		);
	}
}
