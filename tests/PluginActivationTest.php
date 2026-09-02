<?php
/** @package IconLibrary */

use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

class PluginActivationTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ] );
	}

	public function test_fresh_activation_installs_no_collections() {
		Plugin::activate();

		$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
	}

	public function test_activation_preserves_existing_collection_state() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'heroicons' );

		Plugin::activate();

		$this->assertSame( array( 'heroicons' ), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
	}
}
