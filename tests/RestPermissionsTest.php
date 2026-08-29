<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CustomIconRepository;
use IconLibrary\RestController;
use PHPUnit\Framework\TestCase;

class RestPermissionsTest extends TestCase {
	private function controller() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$custom   = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		return new RestController( $registry, $custom );
	}

	public function test_mutation_requires_manage_options() {
		$GLOBALS['icon_library_test_capabilities'] = array();
		$result = $this->controller()->can_manage_collections();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_rest_cannot_manage', $result->get_error_code() );
	}

	public function test_administrator_can_mutate() {
		$GLOBALS['icon_library_test_capabilities'] = array( 'manage_options' => true );
		$this->assertTrue( $this->controller()->can_manage_collections() );
	}
}
