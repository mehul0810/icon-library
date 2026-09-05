<?php
/**
 * Structural edits that preserve WordPress serialization placeholders.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps child arrays and their HTML insertion points in sync. */
class BlockTreeEditor {
	/**
	 * Inserts into a root list or a nested container.
	 *
	 * @param array $blocks Blocks by reference.
	 * @param int[] $path Parent path.
	 * @param int   $position Child position.
	 * @param array $block New block.
	 * @return bool
	 */
	public function insert( &$blocks, $path, $position, $block ) {
		if ( empty( $path ) ) {
			array_splice( $blocks, $position, 0, array( $block ) );
			return true;
		}
		$index = array_shift( $path );
		if ( ! isset( $blocks[ $index ]['innerBlocks'] ) ) {
			return false;
		}
		if ( $path ) {
			return $this->insert( $blocks[ $index ]['innerBlocks'], $path, $position, $block );
		}
		$parent = &$blocks[ $index ];
		$slots  = $this->get_slots( $parent );
		if ( null === $slots ) {
			return false;
		}
		if ( empty( $slots ) ) {
			// Empty HTML has no insertion point. Only split known single-wrapper containers.
			$html = implode( '', $parent['innerContent'] );
			if ( ! in_array( $parent['blockName'], array( 'core/group', 'core/column' ), true ) || 1 !== preg_match( '/\A(\s*<div\b[^>]*>\s*)(<\/div>\s*)\z/s', $html, $matches ) ) {
				return false;
			}
			$parent['innerContent'] = array( $matches[1], null, $matches[2] );
		} else {
			$offset = isset( $slots[ $position ] ) ? $slots[ $position ] : end( $slots ) + 1;
			array_splice( $parent['innerContent'], $offset, 0, array( null ) );
		}
		array_splice( $parent['innerBlocks'], $position, 0, array( $block ) );
		return true;
	}

	/**
	 * Removes a block and its corresponding parent placeholder.
	 *
	 * @param array $blocks Blocks by reference.
	 * @param int[] $path Block path.
	 * @return bool
	 */
	public function remove( &$blocks, $path ) {
		$index = array_shift( $path );
		if ( ! isset( $blocks[ $index ] ) ) {
			return false;
		}
		if ( empty( $path ) ) {
			array_splice( $blocks, $index, 1 );
			return true;
		}
		$parent = &$blocks[ $index ];
		if ( 1 === count( $path ) ) {
			$slots = $this->get_slots( $parent );
			$child = $path[0];
			if ( null === $slots || ! isset( $slots[ $child ], $parent['innerBlocks'][ $child ] ) ) {
				return false;
			}
			array_splice( $parent['innerContent'], $slots[ $child ], 1 );
			array_splice( $parent['innerBlocks'], $child, 1 );
			return true;
		}
		return isset( $parent['innerBlocks'] ) && $this->remove( $parent['innerBlocks'], $path );
	}

	/**
	 * Validates the one-to-one correspondence of children and placeholders.
	 *
	 * @param array $container Parent block.
	 * @return int[]|null
	 */
	private function get_slots( $container ) {
		if ( ! isset( $container['innerContent'], $container['innerBlocks'] ) || ! is_array( $container['innerContent'] ) || ! is_array( $container['innerBlocks'] ) ) {
			return null;
		}
		$slots = array();
		foreach ( $container['innerContent'] as $index => $chunk ) {
			if ( null === $chunk ) {
				$slots[] = $index;
			} elseif ( ! is_string( $chunk ) ) {
				return null;
			}
		}
		return count( $slots ) === count( $container['innerBlocks'] ) ? $slots : null;
	}
}
