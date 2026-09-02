<?php
/** @package IconLibrary */

use IconLibrary\CustomIconRepository;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

class CustomIconRepositoryTest extends TestCase {
	private $repository;

	protected function setUp(): void {
		$GLOBALS['icon_library_test_options']  = array();
		$GLOBALS['icon_library_test_autoload'] = array();
		$this->repository                      = new CustomIconRepository( new SvgSanitizer() );
	}

	protected function tearDown(): void {
		$directory = $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons';
		foreach ( glob( $directory . '/*.svg' ) ?: array() as $file ) {
			unlink( $file );
		}
	}

	public function test_create_uses_non_autoloaded_metadata_and_atomic_file() {
		$result = $this->repository->create( 'test-icon', 'Test Icon', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->assertIsArray( $result );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ CustomIconRepository::OPTION_ICONS ] );
		$this->assertFileExists( $this->repository->get_file_path( 'test-icon.svg' ) );
	}

	public function test_failed_sanitization_persists_nothing() {
		$result = $this->repository->create( 'unsafe', 'Unsafe', '<svg onload="alert(1)"><path d="M0 0"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $this->repository->get_icons() );
		$this->assertNull( $this->repository->get_file_path( 'unsafe.svg' ) );
	}

	public function test_delete_archives_icon_without_removing_saved_block_source() {
		$this->repository->create( 'retained', 'Retained', '<svg><path d="M0 0h1v1z"/></svg>' );
		$path = $this->repository->get_file_path( 'retained.svg' );

		$this->assertTrue( $this->repository->delete( 'retained' ) );
		$this->assertTrue( $this->repository->get_icons()['retained']['archived'] );
		$this->assertFileExists( $path );
	}
}
