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

	public function test_archived_icon_can_be_restored_or_purged_explicitly() {
		$this->repository->create( 'lifecycle', 'Lifecycle', '<svg><path d="M0 0h1v1z"/></svg>' );
		$path = $this->repository->get_file_path( 'lifecycle.svg' );

		$this->assertTrue( $this->repository->delete( 'lifecycle' ) );
		$this->assertTrue( $this->repository->restore( 'lifecycle' ) );
		$this->assertArrayNotHasKey( 'archived', $this->repository->get_icons()['lifecycle'] );
		$this->assertTrue( $this->repository->delete( 'lifecycle' ) );
		$this->assertTrue( $this->repository->purge( 'lifecycle' ) );
		$this->assertArrayNotHasKey( 'lifecycle', $this->repository->get_icons() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_svg_validation_errors_are_client_errors() {
		$result = $this->repository->create( 'unsafe', 'Unsafe', '<svg onload="alert(1)"><path d="M0 0"/></svg>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_retained_byte_budget_includes_archived_rows() {
		$GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] = array(
			'old' => array(
				'path'  => 'old.svg',
				'bytes' => CustomIconRepository::MAX_BYTES,
			),
		);

		$result = $this->repository->create( 'new', 'New', '<svg><path d="M0 0h1v1z"/></svg>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_limit', $result->get_error_code() );
	}
}
