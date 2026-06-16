<?php
/**
 * WordPress core Icon block integration.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use ReflectionException;
use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps direct calls into the unreleased WordPress icon registry in one place.
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
	private $manifest_loader;

	/**
	 * SVG sanitizer.
	 *
	 * @var SvgSanitizer
	 */
	private $svg_sanitizer;

	/**
	 * Registered icon names.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 * @param ManifestLoader     $manifest_loader     Manifest loader.
	 * @param SvgSanitizer       $svg_sanitizer       SVG sanitizer.
	 */
	public function __construct( CollectionRegistry $collection_registry, ManifestLoader $manifest_loader, SvgSanitizer $svg_sanitizer ) {
		$this->collection_registry = $collection_registry;
		$this->manifest_loader     = $manifest_loader;
		$this->svg_sanitizer       = $svg_sanitizer;
	}

	/**
	 * Registers bundled icons with the WordPress core icon registry.
	 */
	public function register_icons() {
		if ( ! class_exists( 'WP_Icons_Registry' ) ) {
			return;
		}

		$registry = \WP_Icons_Registry::get_instance();

		try {
			$register_method = new ReflectionMethod( $registry, 'register' );
		} catch ( ReflectionException $exception ) {
			return;
		}

		/*
		 * WordPress trunk currently keeps icon registration protected. Keep this
		 * compatibility bridge isolated until a public third-party API lands.
		 */
		if ( PHP_VERSION_ID < 80100 ) {
			$register_method->setAccessible( true );
		}

		foreach ( $this->collection_registry->get_collections() as $collection_slug => $collection ) {
			$manifest = $this->manifest_loader->get_manifest( $collection_slug );

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				$this->register_icon( $registry, $register_method, $collection_slug, $icon );
			}
		}
	}

	/**
	 * Filters the core icon collection endpoint so disabled plugin collections
	 * are hidden from new selections while existing saved blocks can still render.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function filter_core_icons_response( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( '/wp/v2/icons' !== $request->get_route() || 'GET' !== $request->get_method() ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$enabled = $this->collection_registry->get_enabled_collection_slugs();
		$data    = array_values(
			array_filter(
				$data,
				function ( $icon ) use ( $enabled ) {
					if ( ! is_array( $icon ) || empty( $icon['name'] ) ) {
						return true;
					}

					$collection_slug = $this->collection_registry->get_collection_slug_for_core_icon_name( $icon['name'] );

					if ( null === $collection_slug ) {
						return true;
					}

					return in_array( $collection_slug, $enabled, true );
				}
			)
		);

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Registers one icon row.
	 *
	 * @param object           $registry        Core icon registry instance.
	 * @param ReflectionMethod $register_method Protected register method.
	 * @param string           $collection_slug Collection slug.
	 * @param array            $icon            Manifest icon row.
	 */
	private function register_icon( $registry, ReflectionMethod $register_method, $collection_slug, $icon ) {
		if ( ! is_array( $icon ) || empty( $icon['coreIconName'] ) || empty( $icon['label'] ) || empty( $icon['path'] ) ) {
			return;
		}

		$core_icon_name = sanitize_text_field( $icon['coreIconName'] );

		if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( $core_icon_name ) ) {
			return;
		}

		$svg = $this->manifest_loader->get_svg_content( $collection_slug, $icon['path'] );
		$svg = $this->svg_sanitizer->sanitize( $svg );

		if ( '' === $svg ) {
			return;
		}

		$registered = $register_method->invoke(
			$registry,
			$core_icon_name,
			array(
				'label'   => sanitize_text_field( $icon['label'] ),
				'content' => $svg,
			)
		);

		if ( $registered ) {
			$this->registered[] = $core_icon_name;
		}
	}
}
