<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use PHPUnit\Framework\TestCase;

class CoreIconRegistrarTest extends TestCase {
	private function style_registrar( $enabled = true ) {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( $enabled ? array( 'test' ) : array() );
		$registry->method( 'get_enabled_variants' )->willReturn( $enabled ? array( 'solid', 'mini' ) : array() );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Test',
				'variants' => array(
					array(
						'slug'  => 'solid',
						'label' => 'Solid',
					),
					array(
						'slug'  => 'mini',
						'label' => 'Mini',
					),
					array(
						'slug'  => 'outline',
						'label' => 'Outline',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'test/one-solid',
						'label'        => 'One',
						'variant'      => 'solid',
						'path'         => 'solid/one.svg',
					),
					array(
						'coreIconName' => 'test/one-mini',
						'label'        => 'One',
						'variant'      => 'mini',
						'path'         => 'mini/one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icons();
		return $registrar;
	}

	public function test_registers_styles_and_retains_legacy_icon_names() {
		$this->style_registrar();
		$registered = $GLOBALS['icon_library_test_registered'];
		$this->assertSame( 'Test - Solid', $registered['collections']['test-solid']['label'] );
		$this->assertSame( 'Test - Mini', $registered['collections']['test-mini']['label'] );
		$this->assertArrayNotHasKey( 'test-outline', $registered['collections'] );
		$this->assertArrayHasKey( 'test-solid/one-solid', $registered['icons'] );
		$this->assertArrayHasKey( 'test-mini/one-mini', $registered['icons'] );
		$this->assertArrayNotHasKey( 'test/one-solid', $registered['icons'] );
		$this->assertArrayNotHasKey( 'test-solid/one-mini', $registered['icons'] );
		$registrar = $this->style_registrar();
		$registrar->register_icon_block( array( 'blockName' => 'core/icon', 'attrs' => array( 'icon' => 'test/one-solid' ) ) );
		$this->assertArrayHasKey( 'test/one-solid', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_hides_parent_collection_without_hiding_enabled_styles() {
		$registrar = $this->style_registrar();
		$response  = new WP_REST_Response(
			array(
				array( 'slug' => 'core' ),
				array( 'slug' => 'test' ),
				array( 'slug' => 'test-solid' ),
				array( 'slug' => 'test-mini' ),
			)
		);
		$result    = $registrar->filter_core_discovery_response( $response, null, new WP_REST_Request( 'GET', '/wp/v2/icon-collections' ) );
		$this->assertSame( array( 'core', 'test-solid', 'test-mini' ), array_column( $result->get_data(), 'slug' ) );
	}

	public function test_disabled_collection_registers_nothing_until_rendered() {
		$registrar = $this->style_registrar( false );
		$this->assertSame( array(), $GLOBALS['icon_library_test_registered']['icons'] );

		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'test-solid/one-solid' ),
			)
		);
		$this->assertArrayHasKey( 'test-solid/one-solid', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_individual_icon_response_remains_available_when_uninstalled() {
		$registrar = $this->style_registrar( false );
		foreach ( array( 'test/one-solid', 'test-solid/one-solid' ) as $name ) {
			$data     = array(
				'name'    => $name,
				'content' => '<svg/>',
			);
			$response = new WP_REST_Response( $data );
			$result   = $registrar->filter_core_discovery_response( $response, null, new WP_REST_Request( 'GET', '/wp/v2/icons/' . $name ) );
			$this->assertSame( $data, $result->get_data() );
		}
	}

	public function test_registers_collection_and_icon_with_core() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array() );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'        => 'Test',
				'description' => 'Test icons',
				'icons'       => array(
					array(
						'coreIconName' => 'test/one',
						'label'        => 'One',
						'path'         => 'one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		( new CoreIconRegistrar( $registry ) )->register_icons();

		$this->assertArrayHasKey( 'test', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'test/one', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_marks_core_incompatible_icons_before_core_sanitizes_them() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path', 'get_svg_content' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'outline' ) );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Test',
				'variants' => array(
					array(
						'slug'           => 'outline',
						'label'          => 'Outline',
						'coreCompatible' => false,
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'test/one-outline',
						'label'        => 'One',
						'variant'      => 'outline',
						'path'         => 'outline/one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );
		$registry->method( 'get_svg_content' )->willReturn( '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M1 1h22"/></svg>' );

		( new CoreIconRegistrar( $registry ) )->register_icons();

		$registered = $GLOBALS['icon_library_test_registered']['icons'];
		$this->assertStringContainsString( 'icon-library-stroked', $registered['test-outline/one-outline']['content'] );
		$this->assertArrayNotHasKey( 'test/one-outline', $registered );
		$this->assertArrayNotHasKey( 'file_path', $registered['test-outline/one-outline'] );
	}

	public function test_registers_zero_label_and_legacy_heroicons_size_names() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'heroicons' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'heroicons' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'solid' ) );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Heroicons',
				'variants' => array(
					array(
						'slug'  => 'solid',
						'label' => 'Solid',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'heroicons/0-solid',
						'label'        => '0',
						'path'         => 'solid/0.svg',
						'variant'      => 'solid',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );
		( new CoreIconRegistrar( $registry ) )->register_icons();
		$registered = $GLOBALS['icon_library_test_registered']['icons'];
		$this->assertArrayHasKey( 'heroicons-solid', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayNotHasKey( 'heroicons-mini', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayNotHasKey( 'heroicons/0-solid', $registered );
		$this->assertArrayNotHasKey( 'heroicons/0-24-solid', $registered );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'heroicons/0-24-solid' ),
			)
		);
		$this->assertArrayHasKey( 'heroicons/0-24-solid', $GLOBALS['icon_library_test_registered']['icons'] );
	}
}
