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
	 * Whether the current frontend request renders a stroked icon.
	 *
	 * @var bool
	 */
	private $needs_compatibility_styles = false;

	/**
	 * Manifest loader.
	 *
	 * @var ManifestLoader
	 */
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
			$manifest = $this->collection_registry->get_manifest( $collection_slug );

			if ( ! $this->register_collection( $collection_slug, $manifest ) ) {
				continue;
			}

			$this->set_core_incompatible_variants( $collection_slug, $manifest );

			$enabled_variants = $this->collection_registry->get_enabled_variants( $collection_slug );
			$styles           = $this->register_style_collections( $collection_slug, $manifest, $enabled_variants );

			foreach ( $manifest['icons'] as $icon ) {
				$variant = sanitize_key( $icon['variant'] ?? '' );
				if ( $variant && ! in_array( $variant, $enabled_variants, true ) ) {
					continue;
				}
				if ( isset( $icon['variant'], $styles[ $icon['variant'] ] ) ) {
					$this->register_icon( $collection_slug, $icon, $styles[ $icon['variant'] ] );
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
		if ( ! is_admin() && ! $this->needs_compatibility_styles ) {
			return;
		}

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

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			$this->walk_block( $block );
		}
	}

	/**
	 * Lazily registers a saved Icon block without exposing it in discovery.
	 *
	 * @param array $parsed_block Parsed block data.
	 * @return array
	 */
	public function register_icon_block( $parsed_block ) {
		if ( is_array( $parsed_block ) && 'core/icon' === ( $parsed_block['blockName'] ?? '' ) ) {
			$name = sanitize_text_field( $parsed_block['attrs']['icon'] ?? '' );
			if ( $name ) {
				$this->register_icon_by_name( $name );
			}
		}

		return $parsed_block;
	}

	/**
	 * Recursively processes parsed blocks.
	 *
	 * @param array $block Parsed block.
	 */
	private function walk_block( $block ) {
		$this->register_icon_block( $block );
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner_block ) {
			$this->walk_block( $inner_block );
		}
	}

	/**
	 * Registers one current, styled, or legacy icon name on demand.
	 *
	 * @param string $requested_name Core icon name.
	 */
	private function register_icon_by_name( $requested_name ) {
		foreach ( $this->collection_registry->get_available_collection_slugs() as $library ) {
			$manifest = $this->collection_registry->get_manifest( $library );
			if ( ! is_array( $manifest ) || empty( $manifest['icons'] ) ) {
				continue;
			}

			$this->set_core_incompatible_variants( $library, $manifest );
			foreach ( $manifest['icons'] as $icon ) {
				$base_name = (string) ( $icon['coreIconName'] ?? '' );
				$variant   = sanitize_key( $icon['variant'] ?? '' );
				$style     = $variant ? $library . '-' . $variant : '';

				if ( $requested_name === $base_name ) {
					$this->register_collection( $library, $manifest );
					$this->register_icon( $library, $icon );
					$this->mark_compatibility_styles( $library, $icon );
					return;
				}

				if ( $style && $requested_name === $style . substr( $base_name, strlen( $library ) ) ) {
					$this->register_style_collections( $library, $manifest, array( $variant ) );
					$this->register_icon( $library, $icon, $style );
					$this->mark_compatibility_styles( $library, $icon );
					return;
				}

				if ( 'heroicons' === $library && 'solid' === $variant && 1 === preg_match( '#^heroicons/(.+)-(24|20|16)-solid$#', $requested_name, $matches ) && basename( (string) ( $icon['path'] ?? '' ), '.svg' ) === $matches[1] ) {
					$this->register_collection( $library, $manifest );
					$this->register_heroicons_legacy_name( $library, $icon, $requested_name );
					return;
				}
			}
		}
	}

	/**
	 * Records whether an icon requires the compatibility stylesheet.
	 *
	 * @param string $library Library slug.
	 * @param array  $icon    Manifest icon row.
	 */
	private function mark_compatibility_styles( $library, $icon ) {
		if ( $this->is_core_incompatible_icon( $library, $icon ) ) {
			$this->needs_compatibility_styles = true;
		}
	}

	/**
	 * Caches incompatible variants from one manifest.
	 *
	 * @param string $library  Library slug.
	 * @param array  $manifest Manifest data.
	 */
	private function set_core_incompatible_variants( $library, $manifest ) {
		$this->core_incompatible_variants[ $library ] = array_values(
			array_filter(
				array_map(
					static function ( $variant ) {
						return is_array( $variant ) && ! empty( $variant['slug'] ) && false === ( $variant['coreCompatible'] ?? true ) ? sanitize_key( $variant['slug'] ) : null;
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
		$data  = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$available = $this->collection_registry->get_available_collection_slugs();
		$enabled   = $this->collection_registry->get_enabled_collection_slugs();
		$disabled  = array_values( array_diff( $available, $enabled ) );
		$archived  = array();
		$custom    = $this->collection_registry->get_manifest( CustomIconRepository::COLLECTION_SLUG );
		foreach ( (array) ( $custom['icons'] ?? array() ) as $icon ) {
			if ( ! empty( $icon['archived'] ) && ! empty( $icon['coreIconName'] ) ) {
				$archived[] = $icon['coreIconName'];
			}
		}
		foreach ( $this->style_collections as $style_slug => $library_slug ) {
			$variant = substr( $style_slug, strlen( $library_slug ) + 1 );
			if ( ! in_array( $library_slug, $enabled, true ) || ! in_array( $variant, $this->collection_registry->get_enabled_variants( $library_slug ), true ) ) {
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
		if ( CustomIconRepository::COLLECTION_SLUG === $library || empty( $manifest['variants'] ) ) {
			return $styles;
		}

		$used = array_values( array_unique( array_map( 'sanitize_key', array_column( $manifest['icons'], 'variant' ) ) ) );
		foreach ( $manifest['variants'] as $variant ) {
			if ( empty( $variant['slug'] ) || empty( $variant['label'] ) || ! in_array( $variant['slug'], $used, true ) ) {
				continue;
			}
			if ( $enabled_variants && ! in_array( sanitize_key( $variant['slug'] ), $enabled_variants, true ) ) {
				continue;
			}
			$slug = $library . '-' . $variant['slug'];
			if ( wp_register_icon_collection( $slug, array( 'label' => sanitize_text_field( $manifest['name'] . ' - ' . $variant['label'] ) ) ) ) {
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
		if ( 'heroicons' !== $library || ! isset( $icon['variant'], $icon['path'], $icon['coreIconName'] ) || 'solid' !== sanitize_key( $icon['variant'] ) ) {
			return;
		}

		$base_name = basename( $icon['path'], '.svg' );
		if ( '' === $base_name || false === strpos( $icon['coreIconName'], $library . '/' ) ) {
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
		if ( ! is_array( $manifest ) || empty( $manifest['name'] ) || empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
			return false;
		}

		$args = array(
			'label' => sanitize_text_field( $manifest['name'] ),
		);

		if ( ! empty( $manifest['description'] ) ) {
			$args['description'] = sanitize_text_field( $manifest['description'] );
		}

		return wp_register_icon_collection( $collection_slug, $args );
	}

	/**
	 * Registers one icon row.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param array  $icon            Manifest icon row.
	 * @param string $style_slug      Optional Core style collection namespace.
	 */
	private function register_icon( $collection_slug, $icon, $style_slug = '' ) {
		if ( ! is_array( $icon ) || empty( $icon['coreIconName'] ) || ! isset( $icon['label'] ) || '' === trim( (string) $icon['label'] ) || empty( $icon['path'] ) ) {
			return;
		}

		$core_icon_name = sanitize_text_field( $icon['coreIconName'] );
		$file_path      = $this->collection_registry->get_svg_path( $collection_slug, $icon['path'] );

		if ( 0 !== strpos( $core_icon_name, $collection_slug . '/' ) || null === $file_path ) {
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
		} else {
			$icon_args['file_path'] = $file_path;
		}

		if ( $style_slug ) {
			$core_icon_name = $style_slug . substr( $core_icon_name, strlen( $collection_slug ) );
		}

		wp_register_icon( $core_icon_name, $icon_args );
	}

	/**
	 * Determines whether an icon needs a CSS presentation marker.
	 *
	 * @param string $collection_slug Collection slug.
	 * @param array  $icon            Manifest icon row.
	 * @return bool
	 */
	private function is_core_incompatible_icon( $collection_slug, $icon ) {
		$variant = isset( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';

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
