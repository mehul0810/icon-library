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
			'/icons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_icons' ),
				'permission_callback' => array( $this, 'can_read_icons' ),
				'args'                => $this->get_icon_query_args(),
				'schema'              => array( $this, 'get_icon_catalog_schema' ),
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

		foreach ( array( 'restore', 'purge' ) as $operation ) {
			register_rest_route(
				Plugin::REST_NAMESPACE,
				'/custom-icons/(?P<name>[a-z0-9]+(?:-[a-z0-9]+)*)/' . $operation,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore' === $operation ? 'restore_custom_icon' : 'purge_custom_icon' ),
					'permission_callback' => array( $this, 'can_manage_collections' ),
				)
			);
		}
	}

	/**
	 * Creates a custom icon.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->create( $request->get_param( 'name' ), $request->get_param( 'label' ), $request->get_param( 'svg' ) );
		if ( is_wp_error( $result ) ) {
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
		$result = $this->custom_icons->update_label( $request->get_param( 'name' ), $request->get_param( 'label' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Deletes a custom icon.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->delete( $request->get_param( 'name' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Restores an archived custom icon.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->restore( $request->get_param( 'name' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'restored' => true ) );
	}

	/**
	 * Permanently removes an archived custom icon.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge_custom_icon( WP_REST_Request $request ) {
		$result = $this->custom_icons->purge( $request->get_param( 'name' ) );
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

		foreach ( (array) get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if ( isset( $post_type->cap->edit_posts ) && current_user_can( $post_type->cap->edit_posts ) ) {
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
	 * Returns a paginated icon catalog for integrations that cannot use Core's
	 * native discovery routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_icons( WP_REST_Request $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$page     = null === $page ? 1 : $page;
		$per_page = null === $per_page ? 100 : $per_page;
		$query    = $this->collection_registry->query_icons(
			array(
				'collection' => $request->get_param( 'collection' ),
				'variant'    => $request->get_param( 'variant' ),
				'category'   => $request->get_param( 'category' ),
				'search'     => $request->get_param( 'search' ),
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);
		$page     = max( 1, absint( $page ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );

		return rest_ensure_response(
			array(
				'items'          => $query['items'],
				'total'          => $query['total'],
				'page'           => $page,
				'per_page'       => $per_page,
				'total_pages'    => (int) ceil( $query['total'] / $per_page ),
				'variant_counts' => $query['variant_counts'],
			)
		);
	}

	/**
	 * Returns the icon catalog response schema.
	 *
	 * @return array
	 */
	public function get_icon_catalog_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'icon-library-icons',
			'type'       => 'object',
			'properties' => array(
				'items'          => array(
					'type'     => 'array',
					'readonly' => true,
				),
				'total'          => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'page'           => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'per_page'       => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'total_pages'    => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'variant_counts' => array(
					'type'     => 'object',
					'readonly' => true,
				),
			),
		);
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
		$slug         = is_string( $request->get_param( 'slug' ) ) ? sanitize_key( $request->get_param( 'slug' ) ) : '';
		$variant      = is_string( $request->get_param( 'variant' ) ) ? sanitize_key( $request->get_param( 'variant' ) ) : '';
		$collection   = $this->collection_registry->get_collection( $slug );
		$variant_rows = $collection && isset( $collection['variants'] ) && is_array( $collection['variants'] ) ? $collection['variants'] : array();
		$variants     = null === $collection ? array() : array_values(
			array_filter(
				array_map(
					static function ( $variant_slug ) {
						return is_string( $variant_slug ) ? sanitize_key( $variant_slug ) : '';
					},
					wp_list_pluck( $variant_rows, 'slug' )
				)
			)
		);
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
		$slug       = is_string( $request->get_param( 'slug' ) ) ? sanitize_key( $request->get_param( 'slug' ) ) : '';
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
	 * Returns icon catalog query arguments.
	 *
	 * @return array
	 */
	private function get_icon_query_args() {
		return array(
			'collection' => array( 'type' => 'string' ),
			'variant'    => array( 'type' => 'string' ),
			'category'   => array( 'type' => 'string' ),
			'search'     => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'page'       => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'maximum'           => CollectionRegistry::MAX_PAGE,
				'validate_callback' => static function ( $value ) {
					return is_numeric( $value ) && 1 <= (int) $value;
				},
			),
			'per_page'   => array(
				'type'              => 'integer',
				'default'           => 100,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => static function ( $value ) {
					return is_numeric( $value ) && 1 <= (int) $value && 100 >= (int) $value;
				},
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
