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
		$result                                    = $this->controller()->can_manage_collections();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_rest_cannot_manage', $result->get_error_code() );
	}

	public function test_administrator_can_mutate() {
		$GLOBALS['icon_library_test_capabilities'] = array( 'manage_options' => true );
		$this->assertTrue( $this->controller()->can_manage_collections() );
	}

	public function test_repeated_variant_state_is_an_idempotent_success() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_collection', 'set_variant_enabled' ) )->getMock();
		$registry->method( 'get_collection' )->willReturn(
			array(
				'enabled'  => true,
				'variants' => array( array( 'slug' => 'solid' ) ),
			)
		);
		$registry->expects( $this->once() )->method( 'set_variant_enabled' )->willReturn( true );
		$custom = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		$result = ( new RestController( $registry, $custom ) )->activate_variant(
			new WP_REST_Request(
				'POST',
				'',
				array(
					'slug'    => 'test',
					'variant' => 'solid',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
	}

	public function test_variant_write_failure_is_not_reported_as_not_found() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_collection', 'set_variant_enabled' ) )->getMock();
		$registry->method( 'get_collection' )->willReturn(
			array(
				'enabled'  => true,
				'variants' => array( array( 'slug' => 'solid' ) ),
			)
		);
		$registry->method( 'set_variant_enabled' )->willReturn( false );
		$custom = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		$result = ( new RestController( $registry, $custom ) )->activate_variant(
			new WP_REST_Request(
				'POST',
				'',
				array(
					'slug'    => 'test',
					'variant' => 'solid',
				)
			)
		);

		$this->assertSame( 'icon_library_variant_update_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_icon_catalog_returns_pagination_metadata() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'query_icons' ) )->getMock();
		$registry->expects( $this->once() )->method( 'query_icons' )->willReturn(
			array(
				'items'          => array( array( 'id' => 'test/solid/one' ) ),
				'total'          => 3,
				'variant_counts' => array( 'solid' => 3 ),
			)
		);
		$custom   = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		$response = ( new RestController( $registry, $custom ) )->get_icons(
			new WP_REST_Request(
				'GET',
				'',
				array(
					'page'     => 2,
					'per_page' => 2,
				)
			)
		);

		$this->assertSame( 2, $response->get_data()['page'] );
		$this->assertSame( 2, $response->get_data()['per_page'] );
		$this->assertSame( 2, $response->get_data()['total_pages'] );
	}
}
