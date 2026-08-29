<?php
/** @package IconLibrary */

use IconLibrary\Build\CollectionBuild;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/scripts/lib/CollectionBuild.php';

class CollectionBuildTest extends TestCase {
	public function test_normalizes_core_compatible_svg() {
		$svg = '<svg viewBox="0 0 24 24"><path d="M0 0h24v24z"/></svg>';
		$this->assertStringContainsString( '<path', CollectionBuild::normalize_svg( $svg ) );
	}

	public function test_rejects_unsupported_geometry() {
		$this->expectException( RuntimeException::class );
		CollectionBuild::normalize_svg( '<svg><circle cx="1" cy="1" r="1"/></svg>' );
	}
}
