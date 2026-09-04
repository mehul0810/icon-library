<?php
/**
 * WordPress core Icon block integration.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers plugin collections through the public WordPress Icon API.
 */
class CoreIconRegistrar {
	const MAX_PREWALK_NODES    = 1000;
	const MAX_PREWALK_DEPTH    = 100;
	const MAX_ICON_NAME_LENGTH = 200;

	/**
	 * Collection registry.
	 *
	 * @var CollectionRegistry
	 */
	private $collection_registry;

	/**
	 * Core style collection slugs mapped to their installable library.
	 *
	 * @var string[]
	 */
	private $style_collections = array();

	/**
	 * Legacy namespaces retained for saved blocks but hidden from discovery.
	 *
	 * @var string[]
	 */
	private $legacy_collections = array();

	/**
	 * Variants whose presentation depends on attributes stripped by Core.
	 *
	 * @var array<string,string[]>
	 */
	private $core_incompatible_variants = array();

	/**
	 * Marked stroked SVG content cached for this request.
	 *
	 * @var array<string,string|null>
	 */
	private $stroked_content_cache = array();

	/**
	 * Request-local resolved-name cache, including misses.
	 *
	 * @var array<string,bool>
	 */
	private $resolved_icon_names = array();

	/**
	 * Request-local name indexes keyed by collection slug.
	 *
	 * @var array<string,array<string,array>>
	 */
	private $collection_indexes = array();

	/**
	 * Collections registered during this request.
	 *
	 * @var array<string,bool>
	 */
	private $registered_collections = array();

	/**
	 * Icons registered during this request.
	 *
	 * @var array<string,bool>
	 */
	private $registered_icons = array();

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 */
	public function __construct( CollectionRegistry $collection_registry ) {
		$this->collection_registry = $collection_registry;
	}

	/**
	 * Registers bundled icons with the WordPress core icon registry.
	 */
	public function register_icons() {
		if ( ! function_exists( 'wp_register_icon_collection' ) || ! function_exists( 'wp_register_icon' ) ) {
			return;
		}

		foreach ( $this->collection_registry->get_enabled_collection_slugs() as $collection_slug ) {
			if ( ! is_string( $collection_slug ) || '' === $collection_slug ) {
				continue;
			}
			$manifest         = $this->collection_registry->get_manifest( $collection_slug );
			$enabled_variants = $this->collection_registry->get_enabled_variants( $collection_slug );
			if ( ! is_array( $enabled_variants ) ) {
				$enabled_variants = array();
			}

			if ( ! $this->manifest_has_enabled_icons( $manifest, $enabled_variants ) ) {
				continue;
			}

			if ( ! $this->register_collection( $collection_slug, $manifest ) ) {
				continue;
			}

			$this->set_core_incompatible_variants( $collection_slug, $manifest );

			$styles = $this->register_style_collections( $collection_slug, $manifest, $enabled_variants );

			foreach ( $manifest['icons'] as $icon ) {
				if ( ! is_array( $icon ) ) {
					continue;
				}
				if ( ! empty( $icon['archived'] ) ) {
					continue;
				}
				$variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
				if ( $variant && ! in_array( $variant, $enabled_variants, true ) ) {
					continue;
				}
				if ( $variant && isset( $styles[ $variant ] ) ) {
					$this->register_icon( $collection_slug, $icon, $styles[ $variant ] );
				} else {
					$this->register_icon( $collection_slug, $icon );
				}
			}
		}
	}

	/**
	 * Enqueues the presentation rules required by Core-sanitized stroked icons.
	 */
	public function enqueue_styles() {
		wp_enqueue_style(
			'icon-library-icons',
			ICON_LIBRARY_URL . 'assets/icons.css',
			array(),
			ICON_LIBRARY_VERSION
		);
	}

	/**
	 * Registers icons referenced by the queried post before frontend styles print.
	 */
	public function register_queried_post_icons() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! is_string( $post->post_content ) || '' === $post->post_content ) {
			return;
		}

		$this->walk_blocks( parse_blocks( $post->post_content ) );
	}

	/**
	 * Hydrates a single Core icon before its REST callback runs.
	 *
	 * This keeps disabled and legacy saved names available to the editor while
	 * leaving collection discovery filtered to enabled choices.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function prepare_core_icon_request( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() || ! $this->can_read_core_icons() ) {
			return $result;
		}

		$route = $request->get_route();
		if ( '/wp/v2/icons' === $route || '/wp/v2/icon-collections' === $route || 1 === preg_match( '#^/wp/v2/icons/[^/]+$#', $route ) ) {
			$this->register_icons();
		} elseif ( 1 === preg_match( '#^/wp/v2/icons/([^/]+/[^/]+)$#', $route, $matches ) ) {
			$this->register_icon_by_name( rawurldecode( $matches[1] ) );
		}

		return $result;
	}

	/**
	 * Mirrors Core's editor-facing Icon REST permission condition.
	 *
	 * @return bool
	 */
	private function can_read_core_icons() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		foreach ( (array) get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if ( isset( $post_type->cap->edit_posts ) && current_user_can( $post_type->cap->edit_posts ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lazily registers a saved Icon block without exposing it in discovery.
	 *
	 * @param array $parsed_block Parsed block data.
	 * @return array
	 */
	public function register_icon_block( $parsed_block ) {
		if ( is_array( $parsed_block ) && 'core/icon' === ( $parsed_block['blockName'] ?? '' ) ) {
			$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
			$name  = isset( $attrs['icon'] ) && is_string( $attrs['icon'] ) ? sanitize_text_field( $attrs['icon'] ) : '';
			if ( $name ) {
				$this->register_icon_by_name( $name );
			}
		}

		return $parsed_block;
	}

	/**
	 * Processes parsed blocks with defensive depth and count limits.
	 *
	 * @param array[] $blocks Parsed blocks.
	 */
	private function walk_blocks( $blocks ) {
		$stack = array();
		foreach ( array_reverse( (array) $blocks ) as $block ) {
			$stack[] = array( $block, 0 );
		}
		$visited = 0;
		$max     = self::MAX_PREWALK_NODES;
		while ( $stack && $visited < $max ) {
			$current = array_pop( $stack );
			$block   = $current[0];
			$depth   = $current[1];
			++$visited;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$this->register_icon_block( $block );
			if ( $depth >= self::MAX_PREWALK_DEPTH ) {
				continue;
			}
			foreach ( array_reverse( (array) ( $block['innerBlocks'] ?? array() ) ) as $inner_block ) {
				$stack[] = array( $inner_block, $depth + 1 );
			}
		}
	}

	/**
	 * Registers one current, styled, or legacy icon name on demand.
	 *
	 * @param string $requested_name Core icon name.
	 */
	private function register_icon_by_name( $requested_name ) {
		if ( ! is_string( $requested_name ) ) {
			return;
		}
		$requested_name = sanitize_text_field( $requested_name );
		if ( '' === $requested_name || self::MAX_ICON_NAME_LENGTH < strlen( $requested_name ) || 1 !== preg_match( '/^[a-z0-9-]+\/[a-z0-9][a-z0-9_-]*$/', $requested_name ) || array_key_exists( $requested_name, $this->resolved_icon_names ) ) {
			return;
		}
		$this->resolved_icon_names[ $requested_name ] = false;
		$entry                                        = $this->find_icon_entry( $requested_name );
		if ( ! is_array( $entry ) || ! isset( $entry['library'], $entry['manifest'], $entry['icon'] ) || ! is_string( $entry['library'] ) || ! is_array( $entry['manifest'] ) || ! is_array( $entry['icon'] ) ) {
			return;
		}
		if ( ! empty( $entry['legacy'] ) ) {
			$this->register_collection( $entry['library'], $entry['manifest'] );
			$this->register_heroicons_legacy_name( $entry['library'], $entry['icon'], $requested_name );
		} elseif ( isset( $entry['style'], $entry['variant'] ) && is_string( $entry['style'] ) && is_string( $entry['variant'] ) && '' !== $entry['style'] && '' !== $entry['variant'] ) {
			$this->register_style_collections( $entry['library'], $entry['manifest'], array( $entry['variant'] ) );
			$this->register_icon( $entry['library'], $entry['icon'], $entry['style'] );
		} else {
			$this->register_collection( $entry['library'], $entry['manifest'] );
			$this->register_icon( $entry['library'], $entry['icon'] );
		}
		$this->resolved_icon_names[ $requested_name ] = true;
	}

	/**
	 * Resolves one icon name without scanning unrelated collections.
	 *
	 * @param string $requested_name Core icon name.
	 * @return array|null
	 */
	private function find_icon_entry( $requested_name ) {
		$separator = strpos( $requested_name, '/' );
		if ( false === $separator ) {
			return null;
		}

		$namespace = substr( $requested_name, 0, $separator );
		foreach ( $this->collection_registry->get_available_collection_slugs() as $library ) {
			if ( ! is_string( $library ) || '' === $library ) {
				continue;
			}
			if ( $namespace !== $library && 0 !== strpos( $namespace, $library . '-' ) ) {
				continue;
			}
			$index = $this->get_collection_index( $library );
			if ( isset( $index[ $requested_name ] ) && is_array( $index[ $requested_name ] ) ) {
				return $index[ $requested_name ];
			}
		}
		return null;
	}

	/**
	 * Builds one collection's name index on first use.
	 *
	 * @param string $library Collection slug.
	 * @return array<string,array>
	 */
	private function get_collection_index( $library ) {
		if ( ! is_string( $library ) || '' === $library ) {
			return array();
		}
		if ( isset( $this->collection_indexes[ $library ] ) ) {
			return $this->collection_indexes[ $library ];
		}

		$manifest = $this->collection_registry->get_manifest( $library );
		$index    = array();
		if ( ! is_array( $manifest ) || empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
			$this->collection_indexes[ $library ] = $index;
			return $index;
		}

		$this->set_core_incompatible_variants( $library, $manifest );
		foreach ( $manifest['icons'] as $icon ) {
			if ( ! is_array( $icon ) || ! isset( $icon['coreIconName'] ) || ! is_string( $icon['coreIconName'] ) ) {
				continue;
			}
			$base_name    = $icon['coreIconName'];
			$icon_variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
			if ( '' === $base_name ) {
				continue;
			}
			$index[ $base_name ] = array(
				'library'  => $library,
				'manifest' => $manifest,
				'icon'     => $icon,
			);
			if ( $icon_variant ) {
				$style = $library . '-' . $icon_variant;
				$index[ $style . substr( $base_name, strlen( $library ) ) ] = array(
					'library'  => $library,
					'manifest' => $manifest,
					'icon'     => $icon,
					'style'    => $style,
					'variant'  => $icon_variant,
				);
			}
			if ( 'heroicons' === $library && 'solid' === $icon_variant ) {
				if ( ! isset( $icon['path'] ) || ! is_string( $icon['path'] ) ) {
					continue;
				}
				$base = basename( $icon['path'], '.svg' );
				if ( '' === $base || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base ) ) {
					continue;
				}
				foreach ( array( '24-solid', '20-solid', '16-solid' ) as $legacy_variant ) {
					$index[ $library . '/' . $base . '-' . $legacy_variant ] = array(
						'library'  => $library,
						'manifest' => $manifest,
						'icon'     => $icon,
						'legacy'   => true,
					);
				}
			}
		}

		$this->collection_indexes[ $library ] = $index;
		return $index;
	}

	/**
	 * Checks whether a manifest has any enabled icon rows.
	 *
	 * @param array|null $manifest         Collection manifest.
	 * @param string[]   $enabled_variants Enabled variants.
	 * @return bool
	 */
	private function manifest_has_enabled_icons( $manifest, $enabled_variants ) {
		if ( ! is_array( $manifest ) || ! is_array( $enabled_variants ) || empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
			return false;
		}
		foreach ( $manifest['icons'] as $icon ) {
			if ( ! is_array( $icon ) ) {
				continue;
			}
			if ( ! empty( $icon['archived'] ) ) {
				continue;
			}
			$variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
			if ( '' === $variant || in_array( $variant, $enabled_variants, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Caches incompatible variants from one manifest.
	 *
	 * @param string $library  Library slug.
	 * @param array  $manifest Manifest data.
	 */
	private function set_core_incompatible_variants( $library, $manifest ) {
		if ( ! is_string( $library ) || '' === $library || ! is_array( $manifest ) ) {
			return;
		}
		$this->core_incompatible_variants[ $library ] = array_values(
			array_filter(
				array_map(
					static function ( $variant ) {
						return is_array( $variant ) && isset( $variant['slug'] ) && is_string( $variant['slug'] ) && '' !== $variant['slug'] && false === ( $variant['coreCompatible'] ?? true ) ? sanitize_key( $variant['slug'] ) : null;
					},
					(array) ( $manifest['variants'] ?? array() )
				)
			)
		);
	}

	/**
	 * Hides disabled plugin collections from new selections while preserving
	 * individual icon retrieval and server rendering for saved content.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function filter_core_discovery_response( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $response;
		}

		$route = $request->get_route();
		if ( '/wp/v2/icon-collections' !== $route && 1 !== preg_match( '#^/wp/v2/icons(?:/[^/]+)?$#', $route ) ) {
			return $response;
		}
		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$available = $this->collection_registry->get_available_collection_slugs();
		$enabled   = $this->collection_registry->get_enabled_collection_slugs();
		$available = is_array( $available ) ? array_values( array_filter( $available, 'is_string' ) ) : array();
		$enabled   = is_array( $enabled ) ? array_values( array_filter( $enabled, 'is_string' ) ) : array();
		$disabled  = array_values( array_diff( $available, $enabled ) );
		$archived  = array();
		$custom    = $this->collection_registry->get_manifest( CustomIconRepository::COLLECTION_SLUG );
		foreach ( (array) ( $custom['icons'] ?? array() ) as $icon ) {
			if ( is_array( $icon ) && ! empty( $icon['archived'] ) && isset( $icon['coreIconName'] ) && is_string( $icon['coreIconName'] ) && '' !== $icon['coreIconName'] ) {
				$archived[] = $icon['coreIconName'];
			}
		}
		foreach ( $this->style_collections as $style_slug => $library_slug ) {
			$variant          = substr( $style_slug, strlen( $library_slug ) + 1 );
			$enabled_variants = $this->collection_registry->get_enabled_variants( $library_slug );
			$enabled_variants = is_array( $enabled_variants ) ? $enabled_variants : array();
			if ( ! in_array( $library_slug, $enabled, true ) || ! in_array( $variant, $enabled_variants, true ) ) {
				$disabled[] = $style_slug;
			}
		}
		$disabled = array_merge( $disabled, $this->legacy_collections );

		if ( empty( $disabled ) ) {
			return $response;
		}

		if ( '/wp/v2/icon-collections' === $route ) {
			$data = array_values(
				array_filter(
					$data,
					static function ( $collection ) use ( $disabled ) {
						return ! is_array( $collection ) || empty( $collection['slug'] ) || ! in_array( $collection['slug'], $disabled, true );
					}
				)
			);
		} elseif ( 1 === preg_match( '#^/wp/v2/icons(?:/([^/]+))?$#', $route, $matches ) ) {
			if ( ! empty( $matches[1] ) && in_array( $matches[1], $disabled, true ) ) {
				$data = array();
			} else {
				$data = array_values(
					array_filter(
						$data,
						static function ( $icon ) use ( $disabled, $archived ) {
							return ! is_array( $icon ) || ( ( empty( $icon['collection'] ) || ! in_array( $icon['collection'], $disabled, true ) ) && ( empty( $icon['name'] ) || ! in_array( $icon['name'], $archived, true ) ) );
						}
					)
				);
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Uses Core's collection filter for style selection without replacing its UI.
	 * Keeps the original namespace registered so existing block names still work.
	 *
	 * @param string $library  Installable library slug.
	 * @param array  $manifest Collection manifest.
	 * @param array  $enabled_variants Variants to expose.
	 * @return string[] Core collection slugs keyed by variant.
	 */
	private function register_style_collections( $library, $manifest, $enabled_variants = array() ) {
		$styles = array();
		if ( ! is_string( $library ) || '' === $library || ! is_array( $manifest ) || ! isset( $manifest['name'] ) || ! is_string( $manifest['name'] ) || '' === trim( $manifest['name'] ) || ! is_array( $enabled_variants ) || empty( $manifest['variants'] ) || ! is_array( $manifest['variants'] ) || empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
			return $styles;
		}

		$used = array();
		foreach ( $manifest['icons'] as $icon ) {
			if ( ! is_array( $icon ) ) {
				continue;
			}
			if ( ! empty( $icon['archived'] ) ) {
				continue;
			}
			$variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
			if ( $variant ) {
				$used[] = $variant;
			}
		}
		$used = array_values( array_unique( $used ) );
		foreach ( $manifest['variants'] as $variant ) {
			if ( ! is_array( $variant ) || ! isset( $variant['slug'], $variant['label'] ) || ! is_string( $variant['slug'] ) || ! is_string( $variant['label'] ) || '' === $variant['slug'] || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $variant['slug'] ) || '' === $variant['label'] || ! in_array( $variant['slug'], $used, true ) ) {
				continue;
			}
			if ( $enabled_variants && ! in_array( sanitize_key( $variant['slug'] ), $enabled_variants, true ) ) {
				continue;
			}
			$slug = $library . '-' . $variant['slug'];
			if ( $this->register_collection_slug( $slug, array( 'label' => sanitize_text_field( $manifest['name'] . ' - ' . $variant['label'] ) ) ) ) {
				$styles[ $variant['slug'] ]       = $slug;
				$this->style_collections[ $slug ] = $library;
			}
		}

		// Only hide the original group when every enabled variant has a style group.
		$discoverable_variants = $enabled_variants ? array_values( array_intersect( $used, $enabled_variants ) ) : $used;
		if ( $styles && ! array_diff( $discoverable_variants, array_keys( $styles ) ) ) {
			$this->legacy_collections[] = $library;
		}

		return $styles;
	}

	/**
	 * Preserves names saved before Heroicons sizes were replaced by style labels.
	 *
	 * The current manifest exposes only style variants. The old size-based files
	 * remain bundled as compatibility sources, but their namespaces are never
	 * added to Core's discovery responses.
	 *
	 * @param string $library Library slug.
	 * @param array  $icon    Manifest icon row.
	 * @param string $requested_name Optional single legacy name.
	 */
	private function register_heroicons_legacy_name( $library, $icon, $requested_name = '' ) {
		if ( 'heroicons' !== $library || ! is_array( $icon ) || ! isset( $icon['variant'], $icon['path'], $icon['coreIconName'] ) || ! is_string( $icon['variant'] ) || ! is_string( $icon['path'] ) || ! is_string( $icon['coreIconName'] ) || 'solid' !== sanitize_key( $icon['variant'] ) ) {
			return;
		}

		$base_name = basename( $icon['path'], '.svg' );
		if ( '' === $base_name || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base_name ) || false === strpos( $icon['coreIconName'], $library . '/' ) ) {
			return;
		}

		foreach ( array( '24-solid', '20-solid', '16-solid' ) as $legacy_variant ) {
			$legacy_icon                 = $icon;
			$legacy_icon['coreIconName'] = $library . '/' . $base_name . '-' . $legacy_variant;
			if ( $requested_name && $requested_name !== $legacy_icon['coreIconName'] ) {
				continue;
			}
			$legacy_icon['path'] = $legacy_variant . '/' . $base_name . '.svg';
			$this->register_icon( $library, $legacy_icon );
		}
	}

	/**
	 * Registers one collection.
	 *
	 * @param string     $collection_slug Collection slug.
	 * @param array|null $manifest        Collection manifest.
	 * @return bool
	 */
	private function register_collection( $collection_slug, $manifest ) {
		if ( ! is_string( $collection_slug ) || '' === $collection_slug || ! is_array( $manifest ) || ! isset( $manifest['name'] ) || ! is_string( $manifest['name'] ) || '' === trim( $manifest['name'] ) || empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
			return false;
		}

		$args = array(
			'label' => sanitize_text_field( $manifest['name'] ),
		);

		if ( isset( $manifest['description'] ) && is_string( $manifest['description'] ) && '' !== trim( $manifest['description'] ) ) {
			$args['description'] = sanitize_text_field( $manifest['description'] );
		}

		return $this->register_collection_slug( $collection_slug, $args );
	}

	/**
	 * Registers a Core collection once, tolerating repeated hook invocations.
	 *
	 * @param string $slug Collection slug.
	 * @param array  $args Collection arguments.
	 * @return bool
	 */
	private function register_collection_slug( $slug, $args ) {
		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) || ! is_array( $args ) || ! isset( $args['label'] ) || ! is_string( $args['label'] ) || '' === trim( $args['label'] ) ) {
			return false;
		}
		if ( isset( $this->registered_collections[ $slug ] ) ) {
			return $this->registered_collections[ $slug ];
		}

		if ( class_exists( 'WP_Icon_Collections_Registry' ) && method_exists( 'WP_Icon_Collections_Registry', 'get_instance' ) ) {
			$registry = \WP_Icon_Collections_Registry::get_instance();
			if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( $slug ) ) {
				$this->registered_collections[ $slug ] = true;
				return true;
			}
		}

		$this->registered_collections[ $slug ] = (bool) wp_register_icon_collection( $slug, $args );
		return $this->registered_collections[ $slug ];
	}

	/**
	 * Registers one icon row.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param array  $icon            Manifest icon row.
	 * @param string $style_slug      Optional Core style collection namespace.
	 */
	private function register_icon( $collection_slug, $icon, $style_slug = '' ) {
		if ( ! is_string( $collection_slug ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $collection_slug ) || ! is_array( $icon ) || ! isset( $icon['coreIconName'], $icon['label'], $icon['path'] ) || ! is_string( $icon['coreIconName'] ) || ! is_string( $icon['label'] ) || ! is_string( $icon['path'] ) || '' === trim( $icon['coreIconName'] ) || '' === trim( $icon['label'] ) || '' === trim( $icon['path'] ) || ! is_string( $style_slug ) ) {
			return;
		}
		if ( 1 !== preg_match( '/^' . preg_quote( $collection_slug, '/' ) . '\/[a-z0-9][a-z0-9_-]*$/', $icon['coreIconName'] ) || ( $style_slug && 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $style_slug ) ) ) {
			return;
		}

		$core_icon_name = sanitize_text_field( $icon['coreIconName'] );
		$file_path      = $this->collection_registry->get_svg_path( $collection_slug, $icon['path'] );
		$svg_content    = null === $file_path ? $this->collection_registry->get_svg_content( $collection_slug, $icon['path'] ) : null;

		if ( 0 !== strpos( $core_icon_name, $collection_slug . '/' ) || ( null === $file_path && ( ! is_string( $svg_content ) || '' === trim( $svg_content ) ) ) ) {
			return;
		}

		$icon_args = array(
			'label' => sanitize_text_field( $icon['label'] ),
		);

		if ( $this->is_core_incompatible_icon( $collection_slug, $icon ) ) {
			$content = $this->get_marked_stroked_content( $collection_slug, $icon['path'] );
			if ( null === $content ) {
				return;
			}
			$icon_args['content'] = $content;
		} elseif ( null !== $file_path ) {
			$icon_args['file_path'] = $file_path;
		} else {
			$icon_args['content'] = $svg_content;
		}

		if ( $style_slug ) {
			$core_icon_name = $style_slug . substr( $core_icon_name, strlen( $collection_slug ) );
		}
		if ( isset( $this->registered_icons[ $core_icon_name ] ) ) {
			return;
		}

		if ( class_exists( 'WP_Icons_Registry' ) && method_exists( 'WP_Icons_Registry', 'get_instance' ) ) {
			$registry = \WP_Icons_Registry::get_instance();
			if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( $core_icon_name ) ) {
				$this->registered_icons[ $core_icon_name ] = true;
				return;
			}
		}

		$this->registered_icons[ $core_icon_name ] = (bool) wp_register_icon( $core_icon_name, $icon_args );
	}

	/**
	 * Determines whether an icon needs a CSS presentation marker.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param array  $icon            Manifest icon row.
	 * @return bool
	 */
	private function is_core_incompatible_icon( $collection_slug, $icon ) {
		$variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';

		return '' !== $variant && in_array( $variant, $this->core_incompatible_variants[ $collection_slug ] ?? array(), true );
	}

	/**
	 * Adds a safe marker class before Core sanitizes stroked SVG attributes.
	 *
	 * Core preserves the root class while removing stroke presentation
	 * attributes. The stylesheet loaded for the editor and frontend restores
	 * those presentation rules without bypassing Core's element allowlist.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param string $relative_path   Manifest-relative SVG path.
	 * @return string|null Marked SVG content, or null when it cannot be parsed.
	 */
	private function get_marked_stroked_content( $collection_slug, $relative_path ) {
		$cache_key = sanitize_key( $collection_slug ) . '|' . ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
		if ( array_key_exists( $cache_key, $this->stroked_content_cache ) ) {
			return $this->stroked_content_cache[ $cache_key ];
		}

		$svg = $this->collection_registry->get_svg_content( $collection_slug, $relative_path );
		if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
			$this->stroked_content_cache[ $cache_key ] = null;
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument exposes this standard property name.
		$root = $document->documentElement;
		if ( ! $loaded || ! $root instanceof \DOMElement ) {
			$this->stroked_content_cache[ $cache_key ] = null;
			return null;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement exposes this standard property name.
		$tag_name = $root->tagName;
		if ( 'svg' !== strtolower( $tag_name ) ) {
			$this->stroked_content_cache[ $cache_key ] = null;
			return null;
		}

		$classes = preg_split( '/\s+/', trim( $root->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! in_array( 'icon-library-stroked', $classes, true ) ) {
			$classes[] = 'icon-library-stroked';
		}
		$root->setAttribute( 'class', implode( ' ', $classes ) );

		$marked = $document->saveXML( $root );
		if ( ! is_string( $marked ) || '' === trim( $marked ) ) {
			$this->stroked_content_cache[ $cache_key ] = null;
			return null;
		}

		$this->stroked_content_cache[ $cache_key ] = trim( $marked );
		return $this->stroked_content_cache[ $cache_key ];
	}
}
