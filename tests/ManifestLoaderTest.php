<?php
/**
 * Manifest loader cache tests.
 *
 * @package IconLibrary
 */

use IconLibrary\ManifestLoader;
use PHPUnit\Framework\TestCase;

/** Tests request-specific filtering over shared raw manifests. */
class ManifestLoaderTest extends TestCase {
	/** @var string */
	private $directory;

	/** Creates a temporary collection fixture. */
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/icon-library-manifest-' . getmypid();
		wp_mkdir_p( $this->directory . '/test' );
		file_put_contents( $this->directory . '/test/manifest.json', '{"slug":"test","name":"Raw"}' );
		$GLOBALS['icon_library_test_cache']   = array();
		$GLOBALS['icon_library_test_filters'] = array();
	}

	/** Removes the temporary collection fixture. */
	protected function tearDown(): void {
		unlink( $this->directory . '/test/manifest.json' );
		rmdir( $this->directory . '/test' );
		rmdir( $this->directory );
	}

	/** Persistent cache stores raw data and filters each request independently. */
	public function test_bundled_metadata_does_not_hydrate_icon_records() {
		$loader = $this->getMockBuilder( ManifestLoader::class )->setConstructorArgs( array( ICON_LIBRARY_DIR . 'assets/icons' ) )->onlyMethods( array( 'get_manifest' ) )->getMock();
		$loader->expects( $this->never() )->method( 'get_manifest' );
		$registry = new IconLibrary\CollectionRegistry( $loader );
		$this->assertCount( 3, $registry->get_collections() );
		$this->assertSame( 648, $registry->get_collection( 'heroicons' )['iconCount'] );
	}

	public function test_manifest_filter_bypasses_generated_metadata() {
		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) { $manifest['name'] = 'Filtered'; return $manifest; },
		);
		$this->assertSame( 'Filtered', ( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) )->get_metadata( 'heroicons' )['name'] );
	}

	/** Persistent cache stores raw data and filters each request independently. */
	public function test_filter_result_is_not_shared_through_persistent_cache() {
		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) {
				$manifest['name'] = 'First request';
				return $manifest;
			},
		);
		$this->assertSame( 'First request', ( new ManifestLoader( $this->directory ) )->get_manifest( 'test' )['name'] );

		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) {
				$manifest['name'] = 'Second request';
				return $manifest;
			},
		);
		$this->assertSame( 'Second request', ( new ManifestLoader( $this->directory ) )->get_manifest( 'test' )['name'] );
	}
}
