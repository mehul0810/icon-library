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
	 * Custom icon repository.
	 *
	 * @var CustomIconRepository
	 */
	private $custom_icons;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry   $collection_registry Collection registry.
	 * @param CustomIconRepository $custom_icons        Custom icon repository.
	 */
	public function __construct( CollectionRegistry $collection_registry, CustomIconRepository $custom_icons ) {
		$this->collection_registry = $collection_registry;
		$this->custom_icons        = $custom_icons;
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

		foreach ( array( 'activate', 'deactivate' ) as $state ) {
			register_rest_route(
				Plugin::REST_NAMESPACE,
				'/collections/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)/variants/(?P<variant>[a-z0-9]+(?:-[a-z0-9]+)*)/' . $state,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate' === $state ? 'activate_variant' : 'deactivate_variant' ),
					'permission_callback' => array( $this, 'can_manage_collections' ),
					'args'                => $this->get_variant_mutation_args(),
				)
			);
		}

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/custom-icons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_custom_icon' ),
				'permission_callback' => array( $this, 'can_manage_collections' ),
				'args'                => array(
					'name'  => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 100,
					),
					'label' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 200,
					),
					'svg'   => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => SvgSanitizer::MAX_FILE_SIZE,
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/custom-icons/(?P<name>[a-z0-9]+(?:-[a-z0-9]+)*)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_custom_icon' ),
					'permission_callback' => array( $this, 'can_manage_collections' ),
					'args'                => array(
						'label' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 200,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_custom_icon' ),
					'permission_callback' => array( $this, 'can_manage_collections' ),
				),
			)
		);
	}

	/**
	 * Creates a custom icon.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->create( $request['name'], $request['label'], $request['svg'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Updates a custom icon label.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->update_label( $request['name'], $request['label'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Deletes a custom icon.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->delete( $request['name'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
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
			__( 'Sorry, you are not allowed to manage icon libraries.', 'icon-library' ),
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
	 * Activates one collection variant.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_variant( WP_REST_Request $request ) {
		return $this->set_variant_state( $request, true );
	}

	/**
	 * Deactivates one collection variant.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate_variant( WP_REST_Request $request ) {
		return $this->set_variant_state( $request, false );
	}

	/**
	 * Updates one variant state.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param bool            $enabled Desired enabled state.
	 * @return WP_REST_Response|WP_Error
	 */
	private function set_variant_state( WP_REST_Request $request, $enabled ) {
		$slug       = sanitize_key( $request['slug'] );
		$variant    = sanitize_key( $request['variant'] );
		$collection = $this->collection_registry->get_collection( $slug );
		$variants   = null === $collection ? array() : array_map( 'sanitize_key', wp_list_pluck( $collection['variants'] ?? array(), 'slug' ) );
		if ( null === $collection || ! in_array( $variant, $variants, true ) ) {
			return new WP_Error( 'icon_library_variant_not_found', __( 'Icon library variant not found.', 'icon-library' ), array( 'status' => 404 ) );
		}
		if ( empty( $collection['enabled'] ) ) {
			return new WP_Error( 'icon_library_collection_not_installed', __( 'Install the icon library before changing its variants.', 'icon-library' ), array( 'status' => 409 ) );
		}
		if ( ! $this->collection_registry->set_variant_enabled( $slug, $variant, $enabled ) ) {
			return new WP_Error( 'icon_library_variant_update_failed', __( 'The icon library variant could not be updated.', 'icon-library' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( $this->collection_registry->get_collection( $slug ) );
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
				__( 'Icon library not found.', 'icon-library' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->collection_registry->set_collection_enabled( $slug, $enabled ) ) {
			return new WP_Error( 'icon_library_collection_update_failed', __( 'The icon library could not be updated.', 'icon-library' ), array( 'status' => 500 ) );
		}

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
				'description'       => __( 'Library slug.', 'icon-library' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => static function ( $value ) {
					return is_string( $value ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value );
				},
			),
		);
	}

	/**
	 * Returns variant mutation args.
	 *
	 * @return array
	 */
	private function get_variant_mutation_args() {
		return array(
			'slug'    => array(
				'type'     => 'string',
				'required' => true,
			),
			'variant' => array(
				'type'     => 'string',
				'required' => true,
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
				'variants'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'           => array(
								'type'     => 'string',
								'readonly' => true,
							),
							'label'          => array(
								'type'     => 'string',
								'readonly' => true,
							),
							'iconCount'      => array(
								'type'     => 'integer',
								'readonly' => true,
							),
							'coreCompatible' => array(
								'type'     => 'boolean',
								'readonly' => true,
							),
							'defaultEnabled' => array(
								'type'     => 'boolean',
								'readonly' => true,
							),
							'enabled'        => array(
								'type'     => 'boolean',
								'readonly' => true,
							),
						),
					),
				),
				'categories'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'      => array(
								'type'     => 'string',
								'readonly' => true,
							),
							'label'     => array(
								'type'     => 'string',
								'readonly' => true,
							),
							'iconCount' => array(
								'type'     => 'integer',
								'readonly' => true,
							),
						),
					),
				),
			),
		);
	}
}
