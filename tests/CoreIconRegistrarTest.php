<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use PHPUnit\Framework\TestCase;

class CoreIconRegistrarTest extends TestCase {
	public function test_registers_collection_and_icon_with_core() {
		$GLOBALS['icon_library_test_registered'] = array( 'collections' => array(), 'icons' => array() );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_manifest' )->willReturn( array( 'name' => 'Test', 'description' => 'Test icons', 'icons' => array( array( 'coreIconName' => 'test/one', 'label' => 'One', 'path' => 'one.svg' ) ) ) );
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		( new CoreIconRegistrar( $registry ) )->register_icons();

		$this->assertArrayHasKey( 'test', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'test/one', $GLOBALS['icon_library_test_registered']['icons'] );
	}
}
