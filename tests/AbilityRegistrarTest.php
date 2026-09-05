<?php
/** @package IconLibrary */

use IconLibrary\AbilityRegistrar;
use IconLibrary\CollectionRegistry;
use IconLibrary\IconBlockService;
use PHPUnit\Framework\TestCase;

class AbilityRegistrarTest extends TestCase {
	/** @var CollectionRegistry */
	private $registry;

	/** @var IconBlockService */
	private $service;

	protected function setUp(): void {
		$GLOBALS['icon_library_test_abilities']  = array();
		$GLOBALS['icon_library_test_categories'] = array();
		$GLOBALS['icon_library_test_actions']    = array();
		$GLOBALS['icon_library_test_capabilities'] = array( 'edit_post' => true, 'edit_posts' => true );
		$GLOBALS['icon_library_test_posts']      = array();

		$this->registry = $this->getMockBuilder( CollectionRegistry::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query_icons', 'get_icon_by_core_name' ) )
			->getMock();
		$this->registry->method( 'query_icons' )->willReturn(
			array(
				'items'          => array(
					array(
						'id'           => 'test/solid/arrow',
						'coreIconName' => 'test/arrow',
						'label'        => 'Arrow',
						'collection'   => 'test',
						'variant'      => 'solid',
						'categories'   => array( 'navigation' ),
						'keywords'     => array( 'forward' ),
						'path'         => 'solid/arrow.svg',
					),
				),
				'total'          => 1,
				'variant_counts' => array( 'solid' => 1 ),
			)
		);
		$this->registry->method( 'get_icon_by_core_name' )->willReturnCallback(
			static function ( $name ) {
				if ( ! in_array( $name, array( 'test/arrow', 'test/new' ), true ) ) {
					return null;
				}
				return array(
					'id'           => 'test/solid/' . ( 'test/new' === $name ? 'new' : 'arrow' ),
					'coreIconName' => $name,
					'label'        => 'Icon',
					'collection'   => 'test',
					'variant'      => 'solid',
					'categories'   => array(),
					'keywords'     => array(),
				);
			}
		);
		$this->service = new IconBlockService( $this->registry );
	}

	public function test_registers_public_catalog_and_block_abilities() {
		$registrar = new AbilityRegistrar( $this->registry );
		$registrar->register_category();
		$registrar->register_abilities();

		$this->assertArrayHasKey( 'icon-library', $GLOBALS['icon_library_test_categories'] );
		$this->assertCount( 6, $GLOBALS['icon_library_test_abilities'] );
		$this->assertTrue( $GLOBALS['icon_library_test_abilities']['icon-library/insert-icon-block']['meta']['show_in_rest'] );
		$this->assertTrue( $GLOBALS['icon_library_test_abilities']['icon-library/remove-icon-block']['meta']['annotations']['destructive'] );
	}

	public function test_search_returns_catalog_metadata_without_source_paths() {
		$result = $this->service->search_icons( array( 'search' => 'arrow' ) );

		$this->assertSame( 'test/arrow', $result['items'][0]['name'] );
		$this->assertSame( 'test/solid/arrow', $result['items'][0]['id'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertArrayNotHasKey( 'path', $result['items'][0] );
	}

	public function test_lists_nested_icon_blocks_with_stable_paths() {
		$this->set_post(
			array(
				$this->group_block(
					array(
						$this->icon_block( 'test/arrow' ),
					)
				),
			)
		);

		$result = $this->service->list_icon_blocks( array( 'post_id' => 7 ) );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( array( 0, 0 ), $result['items'][0]['path'] );
		$this->assertSame( 'test/arrow', $result['items'][0]['icon'] );
		$this->assertSame( '2026-01-01 00:00:00', $result['modified_gmt'] );
	}

	public function test_listing_omits_malformed_icon_names_and_unsafe_attributes() {
		$invalid_attributes = $this->icon_block( 'test/arrow' );
		$invalid_attributes['attrs']['ariaLabel']       = str_repeat( 'x', 201 );
		$invalid_attributes['attrs']['rotation']        = 999;
		$invalid_attributes['attrs']['flipHorizontal'] = 'true';
		$this->set_post(
			array(
				$this->icon_block( str_repeat( 'x', 201 ) ),
				$invalid_attributes,
			)
		);

		$result = $this->service->list_icon_blocks( array( 'post_id' => 7 ) );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( array(), $result['items'][0]['attributes'] );
	}

	public function test_inserts_and_replaces_icon_blocks() {
		$this->set_post( array( $this->group_block( array() ) ) );

		$inserted = $this->service->insert_icon_block(
			array(
				'post_id'     => 7,
				'icon'        => 'test/arrow',
				'parent_path' => array( 0 ),
				'attributes'  => array( 'ariaLabel' => 'Navigate', 'rotation' => 90 ),
			)
		);
		$this->assertSame( array( 0, 0 ), $inserted['path'] );

		$replaced = $this->service->replace_icon_block(
			array(
				'post_id' => 7,
				'path'    => array( 0, 0 ),
				'icon'    => 'test/new',
			)
		);
		$this->assertTrue( $replaced['changed'] );
		$blocks = parse_blocks( get_post( 7 )->post_content );
		$this->assertSame( 'test/new', $blocks[0]['innerBlocks'][0]['attrs']['icon'] );
		$this->assertSame( 'Navigate', $blocks[0]['innerBlocks'][0]['attrs']['ariaLabel'] );
	}

	public function test_does_not_insert_an_icon_inside_an_icon_block() {
		$this->set_post( array( $this->icon_block( 'test/arrow' ) ) );

		$result = $this->service->insert_icon_block(
			array(
				'post_id'     => 7,
				'icon'        => 'test/new',
				'parent_path' => array( 0 ),
			)
		);

		$this->assertSame( 'icon_library_ability_parent_not_allowed', $result->get_error_code() );
	}

	public function test_removes_only_core_icon_blocks() {
		$this->set_post( array( $this->icon_block( 'test/arrow' ), $this->paragraph_block() ) );

		$result = $this->service->remove_icon_block( array( 'post_id' => 7, 'path' => array( 0 ) ) );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'test/arrow', $result['icon'] );
		$this->assertCount( 1, parse_blocks( get_post( 7 )->post_content ) );
		$this->assertSame( 'core/paragraph', parse_blocks( get_post( 7 )->post_content )[0]['blockName'] );
	}

	public function test_rejects_unknown_icons_and_stale_posts() {
		$this->set_post( array() );
		$unknown = $this->service->insert_icon_block( array( 'post_id' => 7, 'icon' => 'missing/icon' ) );
		$this->assertSame( 'icon_library_ability_icon_not_available', $unknown->get_error_code() );

		$stale = $this->service->insert_icon_block(
			array(
				'post_id'                => 7,
				'icon'                   => 'test/arrow',
				'expected_modified_gmt'  => '1999-01-01 00:00:00',
			)
		);
		$this->assertSame( 'icon_library_ability_stale_post', $stale->get_error_code() );
	}

	public function test_requires_post_edit_capability() {
		$GLOBALS['icon_library_test_capabilities'] = array();
		$result = $this->service->can_edit_post( array( 'post_id' => 7 ) );

		$this->assertSame( 'icon_library_ability_cannot_edit_post', $result->get_error_code() );
	}

	public function test_rejects_paths_that_would_exceed_the_output_schema() {
		$this->set_post( array() );
		$result = $this->service->insert_icon_block( array( 'post_id' => 7, 'icon' => 'test/arrow', 'parent_path' => array_fill( 0, 100, 0 ) ) );
		$this->assertSame( 'icon_library_ability_invalid_path', $result->get_error_code() );
		$this->assertSame( '', get_post( 7 )->post_content );
	}

	public function test_rejects_existing_trees_deeper_than_supported_paths() {
		$blocks = array( $this->icon_block( 'test/arrow' ) );
		for ( $depth = 0; $depth < 100; ++$depth ) {
			$blocks = array( $this->group_block( $blocks ) );
		}
		$this->set_post( $blocks );
		$result = $this->service->list_icon_blocks( array( 'post_id' => 7 ) );
		$this->assertSame( 'icon_library_ability_block_limit', $result->get_error_code() );
	}

	public function test_catalog_permission_requires_editor_access() {
		$this->assertTrue( $this->service->can_read_icons() );
		$GLOBALS['icon_library_test_capabilities'] = array();
		$result = $this->service->can_read_icons();

		$this->assertSame( 'icon_library_ability_cannot_read', $result->get_error_code() );
	}

	private function set_post( $blocks ) {
		$GLOBALS['icon_library_test_posts'][7] = (object) array(
			'ID'                => 7,
			'post_content'      => serialize_blocks( $blocks ),
			'post_modified_gmt' => '2026-01-01 00:00:00',
		);
	}

	private function icon_block( $icon ) {
		return array(
			'blockName'    => 'core/icon',
			'attrs'        => array( 'icon' => $icon ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array( '' ),
		);
	}

	private function group_block( $children ) {
		return array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => $children,
			'innerHTML'    => '<div class="wp-block-group"></div>',
			'innerContent' => array_merge( array( '<div class="wp-block-group">' ), array_fill( 0, count( $children ), null ), array( '</div>' ) ),
		);
	}

	private function paragraph_block() {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>Text</p>',
			'innerContent' => array( '<p>Text</p>' ),
		);
	}
}
