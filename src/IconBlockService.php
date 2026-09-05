<?php
/**
 * Safe operations for AI and automation clients working with core/icon blocks.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides bounded, permission-aware icon catalog and block operations.
 */
class IconBlockService {
	const MAX_BLOCK_NODES = 1000;
	const MAX_BLOCK_DEPTH = 100;
	const MAX_PATH_LENGTH = 100;
	const MAX_ICON_LENGTH = 200;

	/**
	 * Collection registry.
	 *
	 * @var CollectionRegistry
	 */
	private $collection_registry;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 */
	public function __construct( CollectionRegistry $collection_registry ) {
		$this->collection_registry = $collection_registry;
	}

	/**
	 * Searches enabled icon collections without exposing filesystem paths or SVG markup.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public function search_icons( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$query = $this->collection_registry->query_icons(
			array(
				'collection' => isset( $input['collection'] ) && is_string( $input['collection'] ) ? $input['collection'] : '',
				'variant'    => isset( $input['variant'] ) && is_string( $input['variant'] ) ? $input['variant'] : '',
				'category'   => isset( $input['category'] ) && is_string( $input['category'] ) ? $input['category'] : '',
				'search'     => isset( $input['search'] ) && is_string( $input['search'] ) ? $input['search'] : '',
				'enabled'    => true,
				'page'       => isset( $input['page'] ) ? $input['page'] : 1,
				'per_page'   => isset( $input['per_page'] ) ? $input['per_page'] : 50,
			)
		);

		if ( ! is_array( $query ) ) {
			return $this->error( 'icon_library_ability_catalog_failed', __( 'The icon catalog could not be read.', 'icon-library' ), 500 );
		}

		$page     = isset( $input['page'] ) && is_int( $input['page'] ) ? max( 1, $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) && is_int( $input['per_page'] ) ? min( 100, max( 1, $input['per_page'] ) ) : 50;
		$total    = isset( $query['total'] ) && is_int( $query['total'] ) ? max( 0, $query['total'] ) : count( (array) ( $query['items'] ?? array() ) );
		$items    = array();

		foreach ( (array) ( $query['items'] ?? array() ) as $icon ) {
			if ( is_array( $icon ) ) {
				$prepared = $this->prepare_icon( $icon );
				if ( '' !== $prepared['name'] ) {
					$items[] = $prepared;
				}
			}
		}

		$variant_counts = array();
		foreach ( (array) ( $query['variant_counts'] ?? array() ) as $variant => $count ) {
			if ( is_string( $variant ) && '' !== sanitize_key( $variant ) ) {
				$variant_counts[ sanitize_key( $variant ) ] = max( 0, (int) $count );
			}
		}

		return array(
			'items'          => $items,
			'total'          => $total,
			'page'           => $page,
			'per_page'       => $per_page,
			'has_more'       => ( $page * $per_page ) < $total,
			'variant_counts' => $variant_counts,
		);
	}

	/**
	 * Resolves one enabled icon by its Core registry name.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_icon( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$name  = $this->normalize_icon_name( $input['icon'] ?? '' );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$icon = $this->resolve_available_icon( $name );
		return is_wp_error( $icon ) ? $icon : $this->prepare_icon( $icon );
	}

	/**
	 * Lists core/icon blocks in a post using stable block-tree paths.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public function list_icon_blocks( $input = array() ) {
		$post = $this->load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$blocks = $this->parse_post_blocks( $post );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$items        = $this->collect_icon_blocks( $blocks );
		$modified_gmt = isset( $post->post_modified_gmt ) && is_string( $post->post_modified_gmt ) && 32 >= strlen( $post->post_modified_gmt ) ? $post->post_modified_gmt : '';
		return array(
			'post_id'      => (int) $post->ID,
			'modified_gmt' => $modified_gmt,
			'items'        => $items,
			'count'        => count( $items ),
		);
	}

	/**
	 * Inserts one core/icon block at the requested root or container path.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public function insert_icon_block( $input = array() ) {
		$post = $this->load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$stale = $this->check_expected_modified( $post, $input );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$icon = $this->resolve_available_icon( is_array( $input ) ? ( $input['icon'] ?? '' ) : '' );
		if ( is_wp_error( $icon ) ) {
			return $icon;
		}
		$blocks = $this->parse_post_blocks( $post );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}
		if ( $this->count_block_nodes( $blocks ) >= self::MAX_BLOCK_NODES ) {
			return $this->error( 'icon_library_ability_block_limit', __( 'The post contains too many blocks for an automated edit.', 'icon-library' ), 400 );
		}

		$parent_path = $this->normalize_path( is_array( $input ) && array_key_exists( 'parent_path', $input ) ? $input['parent_path'] : array(), true );
		if ( is_wp_error( $parent_path ) ) {
			return $parent_path;
		}
		if ( count( $parent_path ) >= self::MAX_PATH_LENGTH ) {
			return $this->error( 'icon_library_ability_invalid_path', __( 'The resulting block path would be too deep.', 'icon-library' ), 400 );
		}
		$position = $this->get_insert_position( $blocks, $parent_path, $input );
		if ( is_wp_error( $position ) ) {
			return $position;
		}

		$attributes = $this->normalize_icon_attributes( is_array( $input ) ? ( $input['attributes'] ?? array() ) : array(), $icon['name'] );
		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}
		$new_block = array(
			'blockName'    => 'core/icon',
			'attrs'        => $attributes,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array( '' ),
		);

		if ( ! ( new BlockTreeEditor() )->insert( $blocks, $parent_path, $position, $new_block ) ) {
			return $this->error( 'icon_library_ability_parent_not_allowed', __( 'The requested block container has no safe insertion point.', 'icon-library' ), 400 );
		}

		$path    = array_values( $parent_path );
		$path[]  = $position;
		$updated = $this->save_post_blocks( $post, $blocks );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'post_id' => (int) $post->ID,
			'path'    => $path,
			'icon'    => $icon['name'],
			'changed' => true,
		);
	}

	/**
	 * Replaces the icon and optional supported presentation attributes at a path.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public function replace_icon_block( $input = array() ) {
		$post = $this->load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$stale = $this->check_expected_modified( $post, $input );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		$path = $this->normalize_path( is_array( $input ) ? ( $input['path'] ?? null ) : null, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$icon = $this->resolve_available_icon( is_array( $input ) ? ( $input['icon'] ?? '' ) : '' );
		if ( is_wp_error( $icon ) ) {
			return $icon;
		}
		$blocks = $this->parse_post_blocks( $post );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}
		$block = $this->get_block_at_path( $blocks, $path );
		if ( ! is_array( $block ) ) {
			return $this->error( 'icon_library_ability_block_not_found', __( 'The requested icon block could not be found.', 'icon-library' ), 404 );
		}
		if ( 'core/icon' !== ( $block['blockName'] ?? '' ) ) {
			return $this->error( 'icon_library_ability_not_icon_block', __( 'The requested block is not a core/icon block.', 'icon-library' ), 400 );
		}

		$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$requested  = is_array( $input ) && array_key_exists( 'attributes', $input ) ? $input['attributes'] : array();
		$normalized = $this->normalize_icon_attributes( $requested, $icon['name'], $attributes );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$changed = $normalized !== $attributes;
		if ( ! $changed ) {
			return array(
				'post_id' => (int) $post->ID,
				'path'    => $path,
				'icon'    => $icon['name'],
				'changed' => false,
			);
		}

		$block['attrs'] = $normalized;
		if ( ! $this->replace_at_path( $blocks, $path, $block ) ) {
			return $this->error( 'icon_library_ability_block_not_found', __( 'The requested icon block could not be found.', 'icon-library' ), 404 );
		}
		$updated = $this->save_post_blocks( $post, $blocks );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'post_id' => (int) $post->ID,
			'path'    => $path,
			'icon'    => $icon['name'],
			'changed' => true,
		);
	}

	/**
	 * Removes a core/icon block at a stable block-tree path.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public function remove_icon_block( $input = array() ) {
		$post = $this->load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$stale = $this->check_expected_modified( $post, $input );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		$path = $this->normalize_path( is_array( $input ) ? ( $input['path'] ?? null ) : null, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$blocks = $this->parse_post_blocks( $post );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}
		$block = $this->get_block_at_path( $blocks, $path );
		if ( ! is_array( $block ) ) {
			return $this->error( 'icon_library_ability_block_not_found', __( 'The requested icon block could not be found.', 'icon-library' ), 404 );
		}
		if ( 'core/icon' !== ( $block['blockName'] ?? '' ) ) {
			return $this->error( 'icon_library_ability_not_icon_block', __( 'The requested block is not a core/icon block.', 'icon-library' ), 400 );
		}
		if ( ! ( new BlockTreeEditor() )->remove( $blocks, $path ) ) {
			return $this->error( 'icon_library_ability_block_not_found', __( 'The requested icon block could not be found.', 'icon-library' ), 404 );
		}
		$updated = $this->save_post_blocks( $post, $blocks );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'post_id' => (int) $post->ID,
			'path'    => $path,
			'icon'    => $this->normalize_stored_icon_name( $block['attrs']['icon'] ?? '' ),
			'changed' => true,
		);
	}

	/**
	 * Checks catalog access for read-only abilities.
	 *
	 * @param mixed $input Ability input.
	 * @return true|WP_Error
	 */
	public function can_read_icons( $input = array() ) {
		unset( $input );
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		foreach ( (array) get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if ( isset( $post_type->cap->edit_posts ) && current_user_can( $post_type->cap->edit_posts ) ) {
				return true;
			}
		}
		return $this->error( 'icon_library_ability_cannot_read', __( 'Sorry, you are not allowed to read icon library resources.', 'icon-library' ), 403 );
	}

	/**
	 * Checks post-level edit access for block mutations and private block discovery.
	 *
	 * @param mixed $input Ability input.
	 * @return true|WP_Error
	 */
	public function can_edit_post( $input = array() ) {
		$post_id = $this->normalize_post_id( is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0 );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'icon_library_ability_cannot_edit_post', __( 'Sorry, you are not allowed to edit this post.', 'icon-library' ), 403 );
		}
		return true;
	}

	/**
	 * Loads a post from ability input.
	 *
	 * @param mixed $input Ability input.
	 * @return object|WP_Error
	 */
	private function load_post( $input ) {
		$post_id = $this->normalize_post_id( is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0 );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! function_exists( 'get_post' ) ) {
			return $this->error( 'icon_library_ability_unavailable', __( 'Post editing is unavailable in this WordPress context.', 'icon-library' ), 500 );
		}
		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_content ) || ! is_string( $post->post_content ) ) {
			return $this->error( 'icon_library_ability_post_not_found', __( 'The requested post could not be found.', 'icon-library' ), 404 );
		}
		return $post;
	}

	/**
	 * Parses a post with bounded input checks.
	 *
	 * @param object $post Post object.
	 * @return array|WP_Error
	 */
	private function parse_post_blocks( $post ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return $this->error( 'icon_library_ability_unavailable', __( 'Block parsing is unavailable in this WordPress context.', 'icon-library' ), 500 );
		}
		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) || $this->count_block_nodes( $blocks ) > self::MAX_BLOCK_NODES ) {
			return $this->error( 'icon_library_ability_block_limit', __( 'The post contains too many blocks for an automated edit.', 'icon-library' ), 400 );
		}
		return $blocks;
	}

	/**
	 * Saves only post content after a successful structural edit.
	 *
	 * @param object $post   Post object.
	 * @param array  $blocks Parsed blocks.
	 * @return true|WP_Error
	 */
	private function save_post_blocks( $post, $blocks ) {
		if ( ! function_exists( 'serialize_blocks' ) || ! function_exists( 'wp_update_post' ) ) {
			return $this->error( 'icon_library_ability_unavailable', __( 'Post editing is unavailable in this WordPress context.', 'icon-library' ), 500 );
		}
		$content = serialize_blocks( $blocks );
		if ( ! is_string( $content ) ) {
			return $this->error( 'icon_library_ability_serialize_failed', __( 'The edited blocks could not be serialized.', 'icon-library' ), 500 );
		}
		$updated = wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_content' => function_exists( 'wp_slash' ) ? wp_slash( $content ) : $content,
			),
			true
		);
		if ( is_wp_error( $updated ) || ! $updated ) {
			return is_wp_error( $updated ) ? $updated : $this->error( 'icon_library_ability_update_failed', __( 'The post could not be updated.', 'icon-library' ), 500 );
		}
		return true;
	}

	/**
	 * Resolves only icons that can be selected for new content.
	 *
	 * @param mixed $name Candidate icon name.
	 * @return array|WP_Error
	 */
	private function resolve_available_icon( $name ) {
		$name = $this->normalize_icon_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$icon = $this->collection_registry->get_icon_by_core_name( $name, true );
		if ( is_array( $icon ) ) {
			$icon['name'] = $name;
			return $icon;
		}

		if ( 0 === strpos( $name, 'core/' ) && class_exists( 'WP_Icons_Registry' ) && method_exists( 'WP_Icons_Registry', 'get_instance' ) ) {
			$registry = \WP_Icons_Registry::get_instance();
			if ( method_exists( $registry, 'get_registered_icon' ) && is_array( $registry->get_registered_icon( $name ) ) ) {
				return array(
					'id'         => $name,
					'name'       => $name,
					'label'      => $name,
					'collection' => 'core',
					'variant'    => '',
					'categories' => array(),
					'keywords'   => array(),
				);
			}
		}

		return $this->error( 'icon_library_ability_icon_not_available', __( 'The requested icon is not available in an enabled collection.', 'icon-library' ), 404 );
	}

	/**
	 * Prepares a machine-readable icon without leaking internal source paths.
	 *
	 * @param array $icon Manifest or registry icon.
	 * @return array
	 */
	private function prepare_icon( $icon ) {
		$categories = array();
		$keywords   = array();
		foreach ( (array) ( $icon['categories'] ?? array() ) as $category ) {
			if ( is_string( $category ) && '' !== sanitize_key( $category ) ) {
				$categories[] = sanitize_key( $category );
			}
		}
		foreach ( (array) ( $icon['keywords'] ?? array() ) as $keyword ) {
			if ( is_string( $keyword ) && '' !== trim( $keyword ) ) {
				$keywords[] = sanitize_text_field( $keyword );
			}
		}
		$name = isset( $icon['name'] ) && is_string( $icon['name'] ) ? $icon['name'] : '';
		if ( '' === $name && isset( $icon['coreIconName'] ) && is_string( $icon['coreIconName'] ) ) {
			$name = $icon['coreIconName'];
		}
		$name = $this->normalize_stored_icon_name( $name );
		$id   = isset( $icon['id'] ) && is_string( $icon['id'] ) ? $this->normalize_public_icon_id( $icon['id'] ) : '';
		if ( '' === $id ) {
			$id = $name;
		}
		return array(
			'id'         => $id,
			'name'       => $name,
			'label'      => isset( $icon['label'] ) && is_string( $icon['label'] ) ? sanitize_text_field( $icon['label'] ) : '',
			'collection' => isset( $icon['collection'] ) && is_string( $icon['collection'] ) ? sanitize_key( $icon['collection'] ) : '',
			'variant'    => isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '',
			'categories' => array_values( array_unique( $categories ) ),
			'keywords'   => array_values( array_unique( $keywords ) ),
		);
	}

	/**
	 * Collects icon blocks using an iterative, bounded walk.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array[]
	 */
	private function collect_icon_blocks( $blocks ) {
		$items = array();
		$stack = array();
		foreach ( array_reverse( array_values( $blocks ) ) as $index => $block ) {
			$root_index = count( $blocks ) - 1 - $index;
			$stack[]    = array( $block, array( $root_index ), 0 );
		}
		$visited = 0;
		while ( $stack && $visited < self::MAX_BLOCK_NODES ) {
			$current = array_pop( $stack );
			$block   = $current[0];
			$path    = $current[1];
			$depth   = $current[2];
			++$visited;
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'core/icon' === ( $block['blockName'] ?? '' ) ) {
				$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$icon_name = $this->normalize_stored_icon_name( $attrs['icon'] ?? '' );
				if ( '' !== $icon_name ) {
					$items[] = array(
						'path'       => array_values( $path ),
						'icon'       => $icon_name,
						'attributes' => $this->prepare_block_attributes( $attrs ),
					);
				}
			}
			if ( $depth >= self::MAX_BLOCK_DEPTH ) {
				continue;
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( $block['innerBlocks'] ) : array();
			foreach ( array_reverse( $children ) as $child_index => $child ) {
				$child_path   = $path;
				$child_path[] = count( $children ) - 1 - $child_index;
				$stack[]      = array( $child, $child_path, $depth + 1 );
			}
		}
		return $items;
	}

	/**
	 * Returns one block by path.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int[] $path   Block path.
	 * @return array|null
	 */
	private function get_block_at_path( $blocks, $path ) {
		$current = $blocks;
		$last    = count( $path ) - 1;
		foreach ( $path as $depth => $index ) {
			if ( ! is_array( $current ) || ! isset( $current[ $index ] ) || ! is_array( $current[ $index ] ) ) {
				return null;
			}
			$current = $current[ $index ];
			if ( $depth < $last ) {
				$current = isset( $current['innerBlocks'] ) && is_array( $current['innerBlocks'] ) ? $current['innerBlocks'] : null;
			}
		}
		return is_array( $current ) ? $current : null;
	}

	/**
	 * Replaces a block at a path.
	 *
	 * @param array $blocks Replacement target by reference.
	 * @param int[] $path   Block path.
	 * @param array $block  Replacement block.
	 * @return bool
	 */
	private function replace_at_path( &$blocks, $path, $block ) {
		$index = array_shift( $path );
		if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
			return false;
		}
		if ( empty( $path ) ) {
			$blocks[ $index ] = $block;
			return true;
		}
		if ( ! isset( $blocks[ $index ]['innerBlocks'] ) || ! is_array( $blocks[ $index ]['innerBlocks'] ) ) {
			return false;
		}
		return $this->replace_at_path( $blocks[ $index ]['innerBlocks'], $path, $block );
	}

	/**
	 * Returns the append/index position for an insertion.
	 *
	 * @param array $blocks Blocks.
	 * @param int[] $path   Parent path.
	 * @param mixed $input  Ability input.
	 * @return int|WP_Error
	 */
	private function get_insert_position( $blocks, $path, $input ) {
		$container = empty( $path ) ? array( 'innerBlocks' => $blocks ) : $this->get_block_at_path( $blocks, $path );
		if ( ! is_array( $container ) || ! isset( $container['innerBlocks'] ) || ! is_array( $container['innerBlocks'] ) ) {
			return $this->error( 'icon_library_ability_parent_not_found', __( 'The requested block container could not be found.', 'icon-library' ), 404 );
		}
		if ( ! empty( $path ) && ! $this->can_contain_icon( $container ) ) {
			return $this->error( 'icon_library_ability_parent_not_allowed', __( 'The requested block does not allow an icon child.', 'icon-library' ), 400 );
		}
		$count = count( $container['innerBlocks'] );
		if ( ! is_array( $input ) || ! array_key_exists( 'position', $input ) ) {
			return $count;
		}
		if ( ! is_int( $input['position'] ) || $input['position'] < 0 || $input['position'] > $count ) {
			return $this->error( 'icon_library_ability_invalid_position', __( 'The insertion position is outside the container.', 'icon-library' ), 400 );
		}
		return $input['position'];
	}

	/**
	 * Checks the registered parent constraints before adding a child block.
	 *
	 * @param array $block Parent block.
	 * @return bool
	 */
	private function can_contain_icon( $block ) {
		if ( 'core/icon' === ( $block['blockName'] ?? '' ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_Block_Type_Registry' ) || ! method_exists( 'WP_Block_Type_Registry', 'get_instance' ) ) {
			return true;
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! method_exists( $registry, 'get_registered' ) ) {
			return true;
		}
		$type = $registry->get_registered( $block['blockName'] ?? '' );
		if ( ! is_object( $type ) || ! isset( $type->allowed_blocks ) || ! is_array( $type->allowed_blocks ) ) {
			return true;
		}
		return in_array( 'core/icon', $type->allowed_blocks, true );
	}

	/**
	 * Keeps block attributes deliberately small and presentation-only.
	 *
	 * @param mixed  $attributes Existing or requested attributes.
	 * @param string $icon       Icon name.
	 * @param array  $existing   Existing attributes to preserve when replacing.
	 * @return array|WP_Error
	 */
	private function normalize_icon_attributes( $attributes, $icon, $existing = array() ) {
		if ( ! is_array( $attributes ) ) {
			return $this->error( 'icon_library_ability_invalid_attributes', __( 'Icon attributes must be an object.', 'icon-library' ), 400 );
		}
		$normalized         = is_array( $existing ) ? $existing : array();
		$normalized['icon'] = $icon;
		$allowed            = array( 'ariaLabel', 'rotation', 'flipHorizontal', 'flipVertical' );
		foreach ( $attributes as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return $this->error( 'icon_library_ability_invalid_attributes', __( 'Only accessible label, rotation, and flip attributes may be changed.', 'icon-library' ), 400 );
			}
			if ( 'ariaLabel' === $key ) {
				if ( ! is_string( $value ) || 200 < strlen( $value ) ) {
					return $this->error( 'icon_library_ability_invalid_attributes', __( 'The accessible label must be a short string.', 'icon-library' ), 400 );
				}
				$normalized[ $key ] = sanitize_text_field( $value );
			} elseif ( 'rotation' === $key ) {
				if ( ! is_int( $value ) || -360 > $value || 360 < $value ) {
					return $this->error( 'icon_library_ability_invalid_attributes', __( 'Rotation must be an integer between -360 and 360.', 'icon-library' ), 400 );
				}
				$normalized[ $key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$normalized[ $key ] = $value;
			} else {
				return $this->error( 'icon_library_ability_invalid_attributes', __( 'Flip attributes must be boolean values.', 'icon-library' ), 400 );
			}
		}
		return $normalized;
	}

	/**
	 * Returns safe attributes for the AI-facing block listing.
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	private function prepare_block_attributes( $attributes ) {
		$output = array();
		if ( isset( $attributes['ariaLabel'] ) && is_string( $attributes['ariaLabel'] ) && 200 >= strlen( $attributes['ariaLabel'] ) ) {
			$output['ariaLabel'] = sanitize_text_field( $attributes['ariaLabel'] );
		}
		if ( isset( $attributes['rotation'] ) && is_int( $attributes['rotation'] ) && -360 <= $attributes['rotation'] && 360 >= $attributes['rotation'] ) {
			$output['rotation'] = $attributes['rotation'];
		}
		foreach ( array( 'flipHorizontal', 'flipVertical' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && is_bool( $attributes[ $key ] ) ) {
				$output[ $key ] = $attributes[ $key ];
			}
		}
		return $output;
	}

	/**
	 * Returns a bounded, well-formed icon name from existing post content.
	 *
	 * Existing content may predate this plugin, so invalid names are ignored
	 * during discovery rather than being exposed to an AI client.
	 *
	 * @param mixed $name Stored icon name.
	 * @return string
	 */
	private function normalize_stored_icon_name( $name ) {
		$normalized = $this->normalize_icon_name( $name );
		return is_wp_error( $normalized ) ? '' : $normalized;
	}

	/**
	 * Returns a safe stable manifest ID without allowing filesystem-like values.
	 *
	 * @param mixed $id Manifest ID.
	 * @return string
	 */
	private function normalize_public_icon_id( $id ) {
		if ( ! is_string( $id ) || '' === $id || self::MAX_ICON_LENGTH < strlen( $id ) || 1 !== preg_match( '/^[a-z0-9-]+(?:\/[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?)+$/', $id ) ) {
			return '';
		}
		return $id;
	}

	/**
	 * Validates an icon name without silently changing it.
	 *
	 * @param mixed $name Candidate name.
	 * @return string|WP_Error
	 */
	private function normalize_icon_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || self::MAX_ICON_LENGTH < strlen( $name ) || 1 !== preg_match( '/^[a-z0-9-]+\/[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $name ) ) {
			return $this->error( 'icon_library_ability_invalid_icon', __( 'Icon names must use the collection/icon-name format.', 'icon-library' ), 400 );
		}
		return $name;
	}

	/**
	 * Validates a post ID.
	 *
	 * @param mixed $post_id Candidate ID.
	 * @return int|WP_Error
	 */
	private function normalize_post_id( $post_id ) {
		if ( ! is_int( $post_id ) || 0 >= $post_id ) {
			return $this->error( 'icon_library_ability_invalid_post', __( 'A valid post ID is required.', 'icon-library' ), 400 );
		}
		return $post_id;
	}

	/**
	 * Validates a block path.
	 *
	 * @param mixed $path       Candidate path.
	 * @param bool  $allow_empty Whether root is valid.
	 * @return int[]|WP_Error
	 */
	private function normalize_path( $path, $allow_empty ) {
		if ( ! is_array( $path ) || self::MAX_PATH_LENGTH < count( $path ) || ( ! $allow_empty && empty( $path ) ) ) {
			return $this->error( 'icon_library_ability_invalid_path', __( 'A valid non-empty block path is required.', 'icon-library' ), 400 );
		}
		$normalized = array();
		foreach ( $path as $index ) {
			if ( ! is_int( $index ) || 0 > $index ) {
				return $this->error( 'icon_library_ability_invalid_path', __( 'Block paths must contain non-negative integer indexes.', 'icon-library' ), 400 );
			}
			$normalized[] = $index;
		}
		return $normalized;
	}

	/**
	 * Rejects a stale post edit when an expected version was supplied.
	 *
	 * @param object $post  Post object.
	 * @param mixed  $input Ability input.
	 * @return true|WP_Error
	 */
	private function check_expected_modified( $post, $input ) {
		if ( ! is_array( $input ) || ! array_key_exists( 'expected_modified_gmt', $input ) ) {
			return true;
		}
		$expected = $input['expected_modified_gmt'];
		if ( ! is_string( $expected ) || 32 < strlen( $expected ) || (string) ( $post->post_modified_gmt ?? '' ) !== $expected ) {
			return $this->error( 'icon_library_ability_stale_post', __( 'The post changed after it was read. Refresh the block list and try again.', 'icon-library' ), 409 );
		}
		return true;
	}

	/**
	 * Counts parsed blocks with a bounded iterative walk.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return int
	 */
	private function count_block_nodes( $blocks ) {
		$count = 0;
		$stack = array();
		foreach ( $blocks as $block ) {
			$stack[] = array( $block, 1 );
		}
		while ( $stack && $count <= self::MAX_BLOCK_NODES ) {
			list( $block, $depth ) = array_pop( $stack );
			if ( $depth > self::MAX_PATH_LENGTH ) {
				return self::MAX_BLOCK_NODES + 1;
			}
			++$count;
			if ( is_array( $block ) && isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				foreach ( $block['innerBlocks'] as $child ) {
					$stack[] = array( $child, $depth + 1 );
				}
			}
		}
		return $count;
	}

	/**
	 * Builds a consistent error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
