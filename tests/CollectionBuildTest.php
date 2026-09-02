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

	public function test_removes_non_rendering_comments_from_svg_payloads() {
		$svg = '<svg><!-- upstream license --><path d="M0 0h24v24z"/></svg>';

		$this->assertStringNotContainsString( 'upstream license', CollectionBuild::normalize_svg( $svg ) );
	}

	public function test_rejects_unsupported_geometry() {
		$this->expectException( RuntimeException::class );
		CollectionBuild::normalize_svg( '<svg><circle cx="1" cy="1" r="1"/></svg>' );
	}

	public function test_rejects_external_presentation_references() {
		$this->expectException( RuntimeException::class );
		CollectionBuild::normalize_svg( '<svg><path fill="url(https://example.com/icon.svg#paint)" d="M0 0h1v1z"/></svg>' );
	}

	public function test_preserves_narrow_stroke_attributes_for_experimental_sources() {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M1 1h22"/></svg>';
		$result = CollectionBuild::normalize_svg( $svg, true );

		$this->assertStringContainsString( 'stroke="currentColor"', $result );
		$this->assertStringContainsString( 'stroke-width="1.5"', $result );
		$this->assertStringNotContainsString( 'data-', $result );
	}
}
