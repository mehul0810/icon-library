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
