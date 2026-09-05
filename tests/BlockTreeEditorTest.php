<?php
/** @package IconLibrary */

use IconLibrary\BlockTreeEditor;
use PHPUnit\Framework\TestCase;

class BlockTreeEditorTest extends TestCase {
	/** @dataProvider positions */
	public function test_nested_insertion_and_removal_preserve_original_content( $position ) {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Last</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$blocks = parse_blocks( $content );
		$icon = parse_blocks( '<!-- wp:icon {"icon":"test/arrow"} /-->' )[0];
		$editor = new BlockTreeEditor();
		$this->assertTrue( $editor->insert( $blocks, array( 0 ), $position, $icon ) );
		$roundtrip = parse_blocks( serialize_blocks( $blocks ) );
		$this->assertCount( 3, $roundtrip[0]['innerBlocks'] );
		$this->assertSame( 'core/icon', $roundtrip[0]['innerBlocks'][$position]['blockName'] );
		$this->assertTrue( $editor->remove( $roundtrip, array( 0, $position ) ) );
		$this->assertSame( $content, serialize_blocks( $roundtrip ) );
	}

	public function positions() {
		return array( array( 0 ), array( 1 ), array( 2 ) );
	}

	public function test_empty_known_container_and_deep_parent_roundtrip() {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group --></div><!-- /wp:group -->';
		$blocks = parse_blocks( $content );
		$editor = new BlockTreeEditor();
		$icon = parse_blocks( '<!-- wp:icon /-->' )[0];
		$this->assertTrue( $editor->insert( $blocks, array( 0, 0 ), 0, $icon ) );
		$blocks = parse_blocks( serialize_blocks( $blocks ) );
		$this->assertSame( 'core/icon', $blocks[0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] );
		$this->assertTrue( $editor->remove( $blocks, array( 0, 0, 0 ) ) );
		$this->assertSame( $content, serialize_blocks( $blocks ) );
	}

	public function test_ambiguous_empty_parent_is_rejected_without_changes() {
		$content = '<!-- wp:cover --><div><div></div></div><!-- /wp:cover -->';
		$blocks = parse_blocks( $content );
		$this->assertFalse( ( new BlockTreeEditor() )->insert( $blocks, array( 0 ), 0, parse_blocks( '<!-- wp:icon /-->' )[0] ) );
		$this->assertSame( $content, serialize_blocks( $blocks ) );
	}
}
