<?php
/**
 * Plugin REST API.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers icon-library/v1 routes.
 */
class RestController {
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
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/collections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_collections' ),
					'permission_callback' => array( $this, 'can_read_icons' ),
				),
				'schema' => array( $this, 'get_collection_schema' ),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/collections/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate_collection' ),
				'permission_callback' => array( $this, 'can_manage_collections' ),
				'args'                => $this->get_collection_mutation_args(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/collections/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate_collection' ),
				'permission_callback' => array( $this, 'can_manage_collections' ),
				'args'                => $this->get_collection_mutation_args(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/icons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_icons' ),
				'permission_callback' => array( $this, 'can_read_icons' ),
				'args'                => $this->get_icon_collection_args(),
			)
		);
	}

	/**
	 * Checks read access.
	 *
	 * @return true|WP_Error
	 */
	public function can_read_icons() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if ( current_user_can( $post_type->cap->edit_posts ) ) {
				return true;
			}
		}

		return new WP_Error(
			'icon_library_rest_cannot_view',
			__( 'Sorry, you are not allowed to view icon library resources.', 'icon-library' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Checks mutation access.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage_collections() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'icon_library_rest_cannot_manage',
			__( 'Sorry, you are not allowed to manage icon collections.', 'icon-library' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns collection summaries.
	 *
	 * @return WP_REST_Response
	 */
	public function get_collections() {
		return rest_ensure_response( array_values( $this->collection_registry->get_collections() ) );
	}

	/**
	 * Activates a collection.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_collection( WP_REST_Request $request ) {
		return $this->set_collection_state( $request, true );
	}

	/**
	 * Deactivates a collection.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate_collection( WP_REST_Request $request ) {
		return $this->set_collection_state( $request, false );
	}

	/**
	 * Returns icon metadata.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_icons( WP_REST_Request $request ) {
		$include_svg = (bool) $request->get_param( 'include_svg' );
		$icons       = $this->collection_registry->get_icons(
			array(
				'collection' => $request->get_param( 'collection' ),
				'variant'    => $request->get_param( 'variant' ),
				'category'   => $request->get_param( 'category' ),
				'search'     => $request->get_param( 'search' ),
				'enabled'    => true,
				'page'       => $request->get_param( 'page' ),
				'per_page'   => $request->get_param( 'per_page' ),
			)
		);

		if ( $include_svg ) {
			foreach ( $icons as $index => $icon ) {
				$svg = $this->manifest_loader->get_svg_content( $icon['collection'], $icon['path'] ?? '' );
				$svg = $this->svg_sanitizer->sanitize( $svg );

				$icons[ $index ]['content'] = $svg;
			}
		}

		return rest_ensure_response( $icons );
	}

	/**
	 * Updates collection state.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param bool            $enabled Desired enabled state.
	 * @return WP_REST_Response|WP_Error
	 */
	private function set_collection_state( WP_REST_Request $request, $enabled ) {
		$slug       = sanitize_key( $request['slug'] );
		$collection = $this->collection_registry->get_collection( $slug );

		if ( null === $collection ) {
			return new WP_Error(
				'icon_library_collection_not_found',
				__( 'Icon collection not found.', 'icon-library' ),
				array( 'status' => 404 )
			);
		}

		$this->collection_registry->set_collection_enabled( $slug, $enabled );

		return rest_ensure_response( $this->collection_registry->get_collection( $slug ) );
	}

	/**
	 * Returns collection mutation args.
	 *
	 * @return array
	 */
	private function get_collection_mutation_args() {
		return array(
			'slug' => array(
				'description'       => __( 'Collection slug.', 'icon-library' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => static function ( $value ) {
					return is_string( $value ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value );
				},
			),
		);
	}

	/**
	 * Returns icon collection args.
	 *
	 * @return array
	 */
	private function get_icon_collection_args() {
		return array(
			'collection'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'variant'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'category'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'search'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'include_svg' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'page'        => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'    => array(
				'type'              => 'integer',
				'default'           => 100,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Returns collection item schema.
	 *
	 * @return array
	 */
	public function get_collection_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'icon-library-collection',
			'type'       => 'object',
			'properties' => array(
				'slug'        => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'name'        => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'description' => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'version'     => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'iconCount'   => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'enabled'     => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
			),
		);
	}
}
