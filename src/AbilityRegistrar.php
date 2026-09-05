<?php
/**
 * WordPress Abilities API integration.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers machine-readable catalog and core/icon block abilities.
 */
class AbilityRegistrar {
	const CATEGORY = 'icon-library';

	/**
	 * Icon block service.
	 *
	 * @var IconBlockService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 */
	public function __construct( CollectionRegistry $collection_registry ) {
		$this->service = new IconBlockService( $collection_registry );
	}

	/** Registers the category and ability hooks. */
	public function register() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/** Registers the category required by every plugin ability. */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Icon Library', 'icon-library' ),
				'description' => __( 'Discover and safely manage icons in WordPress content.', 'icon-library' ),
			)
		);
	}

	/** Registers public abilities during the required Abilities API action. */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$abilities = apply_filters( 'icon_library_abilities', $this->get_ability_definitions() );
		if ( ! is_array( $abilities ) ) {
			return;
		}
		foreach ( $abilities as $name => $args ) {
			if ( ! is_string( $name ) || ! is_array( $args ) || 1 !== preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name ) ) {
				continue;
			}
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Returns the default ability definitions.
	 *
	 * @return array<string,array>
	 */
	private function get_ability_definitions() {
		$read_meta = array(
			'public'       => true,
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		);
		return array(
			'icon-library/search-icons'       => array(
				'label'               => __( 'Search Icons', 'icon-library' ),
				'description'         => __( 'Search enabled icon libraries and return safe icon names, labels, variants, categories, and keywords. Use the returned name when assigning an icon to a core/icon block.', 'icon-library' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_search_input_schema(),
				'output_schema'       => $this->get_search_output_schema(),
				'execute_callback'    => array( $this->service, 'search_icons' ),
				'permission_callback' => array( $this->service, 'can_read_icons' ),
				'meta'                => $read_meta,
			),
			'icon-library/get-icon'           => array(
				'label'               => __( 'Get Icon', 'icon-library' ),
				'description'         => __( 'Validate and retrieve metadata for one enabled icon by its collection/icon-name registry name. SVG markup and filesystem paths are never returned.', 'icon-library' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'icon' => $this->get_icon_name_schema(),
					),
					'required'             => array( 'icon' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->get_icon_output_schema(),
				'execute_callback'    => array( $this->service, 'get_icon' ),
				'permission_callback' => array( $this->service, 'can_read_icons' ),
				'meta'                => $read_meta,
			),
			'icon-library/list-icon-blocks'   => array(
				'label'               => __( 'List Icon Blocks', 'icon-library' ),
				'description'         => __( 'List core/icon blocks in an editable post. Each result includes a stable block-tree path, and the response includes a modified_gmt token for stale-write protection before a replace or remove operation.', 'icon-library' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_post_input_schema(),
				'output_schema'       => $this->get_block_list_output_schema(),
				'execute_callback'    => array( $this->service, 'list_icon_blocks' ),
				'permission_callback' => array( $this->service, 'can_edit_post' ),
				'meta'                => $read_meta,
			),
			'icon-library/insert-icon-block'  => array(
				'label'               => __( 'Insert Icon Block', 'icon-library' ),
				'description'         => __( 'Insert a core/icon block into an editable post at the root or inside a container block. Only enabled, registered icons and a small safe presentation attribute allowlist are accepted.', 'icon-library' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_insert_input_schema(),
				'output_schema'       => $this->get_mutation_output_schema(),
				'execute_callback'    => array( $this->service, 'insert_icon_block' ),
				'permission_callback' => array( $this->service, 'can_edit_post' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			),
			'icon-library/replace-icon-block' => array(
				'label'               => __( 'Replace Icon Block', 'icon-library' ),
				'description'         => __( 'Assign a different icon to the selected core/icon block and optionally update its accessible label, rotation, or flip presentation attributes without accepting arbitrary block markup.', 'icon-library' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_replace_input_schema(),
				'output_schema'       => $this->get_mutation_output_schema(),
				'execute_callback'    => array( $this->service, 'replace_icon_block' ),
				'permission_callback' => array( $this->service, 'can_edit_post' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
			'icon-library/remove-icon-block'  => array(
				'label'               => __( 'Remove Icon Block', 'icon-library' ),
				'description'         => __( 'Remove one core/icon block from an editable post by the stable path returned by list-icon-blocks. This is a destructive operation and never accepts raw content.', 'icon-library' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_path_input_schema(),
				'output_schema'       => $this->get_mutation_output_schema(),
				'execute_callback'    => array( $this->service, 'remove_icon_block' ),
				'permission_callback' => array( $this->service, 'can_edit_post' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			),
		);
	}

	/** Returns the icon name schema. @return array */
	private function get_icon_name_schema() {
		return array(
			'type'      => 'string',
			'maxLength' => IconBlockService::MAX_ICON_LENGTH,
			'pattern'   => '^[a-z0-9-]+/[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$',
		);
	}

	/** Returns the block path schema. @return array */
	private function get_path_schema() {
		return array(
			'type'     => 'array',
			'maxItems' => IconBlockService::MAX_PATH_LENGTH,
			'items'    => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => IconBlockService::MAX_BLOCK_NODES,
			),
		);
	}

	/** Returns shared post targeting properties. @return array */
	private function get_post_properties() {
		return array(
			'post_id'               => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'expected_modified_gmt' => array(
				'type'      => 'string',
				'maxLength' => 32,
			),
		);
	}

	/** Returns the list-blocks input schema. @return array */
	private function get_post_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => $this->get_post_properties(),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/** Returns the path-targeted input schema. @return array */
	private function get_path_input_schema() {
		$properties         = $this->get_post_properties();
		$properties['path'] = $this->get_path_schema();
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id', 'path' ),
			'additionalProperties' => false,
		);
	}

	/** Returns the insert-block input schema. @return array */
	private function get_insert_input_schema() {
		$properties                = $this->get_post_properties();
		$properties['icon']        = $this->get_icon_name_schema();
		$properties['parent_path'] = $this->get_path_schema();
		$properties['position']    = array(
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => IconBlockService::MAX_BLOCK_NODES,
		);
		$properties['attributes']  = $this->get_attributes_schema();
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id', 'icon' ),
			'additionalProperties' => false,
		);
	}

	/** Returns the replace-block input schema. @return array */
	private function get_replace_input_schema() {
		$properties               = $this->get_post_properties();
		$properties['path']       = $this->get_path_schema();
		$properties['icon']       = $this->get_icon_name_schema();
		$properties['attributes'] = $this->get_attributes_schema();
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id', 'path', 'icon' ),
			'additionalProperties' => false,
		);
	}

	/** Returns supported icon block attributes. @return array */
	private function get_attributes_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'ariaLabel'      => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'rotation'       => array(
					'type'    => 'integer',
					'minimum' => -360,
					'maximum' => 360,
				),
				'flipHorizontal' => array( 'type' => 'boolean' ),
				'flipVertical'   => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/** Returns the search input schema. @return array */
	private function get_search_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'collection' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'variant'    => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'category'   => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'search'     => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10000,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** Returns one-icon output schema. @return array */
	private function get_icon_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'string' ),
				'name'       => array( 'type' => 'string' ),
				'label'      => array( 'type' => 'string' ),
				'collection' => array( 'type' => 'string' ),
				'variant'    => array( 'type' => 'string' ),
				'categories' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'keywords'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'id', 'name', 'label', 'collection', 'variant', 'categories', 'keywords' ),
		);
	}

	/** Returns the search output schema. @return array */
	private function get_search_output_schema() {
		$schema = $this->get_icon_output_schema();
		return array(
			'type'       => 'object',
			'properties' => array(
				'items'          => array(
					'type'  => 'array',
					'items' => $schema,
				),
				'total'          => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'has_more'       => array( 'type' => 'boolean' ),
				'variant_counts' => array( 'type' => 'object' ),
			),
			'required'   => array( 'items', 'total', 'page', 'per_page', 'has_more', 'variant_counts' ),
		);
	}

	/** Returns the icon block list output schema. @return array */
	private function get_block_list_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'modified_gmt' => array(
					'type'      => 'string',
					'maxLength' => 32,
				),
				'items'        => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'path'       => $this->get_path_schema(),
							'icon'       => array( 'type' => 'string' ),
							'attributes' => array( 'type' => 'object' ),
						),
						'required'   => array( 'path', 'icon', 'attributes' ),
					),
				),
				'count'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'   => array( 'post_id', 'modified_gmt', 'items', 'count' ),
		);
	}

	/** Returns the mutation output schema. @return array */
	private function get_mutation_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'path'    => $this->get_path_schema(),
				'icon'    => array( 'type' => 'string' ),
				'changed' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'post_id', 'path', 'icon', 'changed' ),
		);
	}
}
