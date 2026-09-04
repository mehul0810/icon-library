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

	public function test_rejects_css_escape_external_references() {
		$this->expectException( RuntimeException::class );
		CollectionBuild::normalize_svg( '<svg><path fill="j\\61vascript:alert(1)" d="M0 0h1v1z"/></svg>' );
	}

	public function test_rejects_processing_instructions() {
		$this->expectException( RuntimeException::class );
		CollectionBuild::normalize_svg( '<?xml-stylesheet href="https://example.com/x.css"?><svg><path d="M0 0h1v1z"/></svg>' );
	}

	public function test_manifest_validator_rejects_wrong_field_types() {
		$errors = CollectionBuild::validate_manifest(
			array(
				'schemaVersion' => 2,
				'slug'          => 'test',
				'name'          => 'Test',
				'description'   => 'Test',
				'version'       => '1.0.0',
				'license'       => array(
					'name' => 'MIT',
					'url'  => 'https://example.com',
				),
				'source'        => array(
					'name'     => 'test',
					'url'      => 'https://example.com',
					'revision' => str_repeat( 'a', 40 ),
				),
				'variants'      => array(
					array(
						'slug'           => 'solid',
						'label'          => 'Solid',
						'defaultEnabled' => 'yes',
					),
				),
				'icons'         => array(),
			),
			sys_get_temp_dir()
		);

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'defaultEnabled must be boolean', implode( ' ', $errors ) );
	}

	public function test_manifest_validator_fails_closed_for_nested_malformed_values() {
		$errors = CollectionBuild::validate_manifest(
			array(
				'schemaVersion' => 2,
				'slug'          => 'test',
				'name'          => 'Test',
				'description'   => 'Test',
				'version'       => '1.0.0',
				'license'       => array( 'name' => 'MIT', 'url' => 'https://example.com' ),
				'source'        => array( 'name' => 'test', 'url' => 'https://example.com', 'revision' => array() ),
				'variants'      => array( array( 'slug' => array(), 'label' => 'Solid' ) ),
				'icons'         => array( array( 'id' => array(), 'coreIconName' => array(), 'label' => 'Broken', 'variant' => array(), 'categories' => array(), 'keywords' => array(), 'path' => array(), 'sha256' => array() ) ),
			),
			sys_get_temp_dir()
		);

		$this->assertNotEmpty( $errors );
	}

	public function test_preserves_narrow_stroke_attributes_for_experimental_sources() {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M1 1h22"/></svg>';
		$result = CollectionBuild::normalize_svg( $svg, true );

		$this->assertStringContainsString( 'stroke="currentColor"', $result );
		$this->assertStringContainsString( 'stroke-width="1.5"', $result );
		$this->assertStringNotContainsString( 'data-', $result );
	}
}
