<?php
/**
 * WordPress core Icon block integration.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

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
	private $manifest_loader;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 * @param ManifestLoader     $manifest_loader     Manifest loader.
	 */
	public function __construct( CollectionRegistry $collection_registry, ManifestLoader $manifest_loader ) {
		$this->collection_registry = $collection_registry;
		$this->manifest_loader     = $manifest_loader;
	}

	/**
	 * Registers bundled icons with the WordPress core icon registry.
	 */
	public function register_icons() {
		if ( ! function_exists( 'wp_register_icon_collection' ) || ! function_exists( 'wp_register_icon' ) ) {
			return;
		}

		foreach ( $this->collection_registry->get_enabled_collection_slugs() as $collection_slug ) {
			$manifest = $this->manifest_loader->get_manifest( $collection_slug );

			if ( ! $this->register_collection( $collection_slug, $manifest ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				$this->register_icon( $collection_slug, $icon );
			}
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
	 * @param string           $collection_slug Collection slug.
	 * @param array            $icon            Manifest icon row.
	 */
	private function register_icon( $collection_slug, $icon ) {
		if ( ! is_array( $icon ) || empty( $icon['coreIconName'] ) || empty( $icon['label'] ) || empty( $icon['path'] ) ) {
			return;
		}

		$core_icon_name = sanitize_text_field( $icon['coreIconName'] );
		$file_path      = $this->manifest_loader->get_svg_path( $collection_slug, $icon['path'] );

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
