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

		foreach ( $this->collection_registry->get_available_collection_slugs() as $collection_slug ) {
			$manifest = $this->collection_registry->get_manifest( $collection_slug );

			if ( ! $this->register_collection( $collection_slug, $manifest ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				$this->register_icon( $collection_slug, $icon );
			}
		}
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
						static function ( $icon ) use ( $disabled ) {
							return ! is_array( $icon ) || empty( $icon['collection'] ) || ! in_array( $icon['collection'], $disabled, true );
						}
					)
				);
			}
		}

		$response->set_data( $data );

		return $response;
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
	 */
	private function register_icon( $collection_slug, $icon ) {
		if ( ! is_array( $icon ) || empty( $icon['coreIconName'] ) || empty( $icon['label'] ) || empty( $icon['path'] ) ) {
			return;
		}

		$core_icon_name = sanitize_text_field( $icon['coreIconName'] );
		$file_path      = $this->collection_registry->get_svg_path( $collection_slug, $icon['path'] );

		if ( 0 !== strpos( $core_icon_name, $collection_slug . '/' ) || null === $file_path ) {
			return;
		}

		wp_register_icon(
			$core_icon_name,
			array(
				'label'     => sanitize_text_field( $icon['label'] ),
				'file_path' => $file_path,
			)
		);
	}
}
