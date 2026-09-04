<?php
/** @package IconLibrary */

use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

class SvgSanitizerTest extends TestCase {
	/** @dataProvider unsafe_svg_provider */
	public function test_rejects_unsafe_svg( $svg, $code ) {
		$result = ( new SvgSanitizer() )->sanitize_custom( $svg );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function unsafe_svg_provider() {
		return array(
			'script'                 => array( '<svg><script>alert(1)</script><path d="M0 0"/></svg>', 'icon_library_svg_element' ),
			'event'                  => array( '<svg onload="alert(1)"><path d="M0 0"/></svg>', 'icon_library_svg_attribute' ),
			'style'                  => array( '<svg><path style="fill:red" d="M0 0"/></svg>', 'icon_library_svg_attribute' ),
			'url'                    => array( '<svg><path fill="url(https://example.com/x)" d="M0 0"/></svg>', 'icon_library_svg_reference' ),
			'css_escape'             => array( '<svg><path fill="j\\61vascript:alert(1)" d="M0 0"/></svg>', 'icon_library_svg_reference' ),
			'declaration'            => array( '<!DOCTYPE svg><svg><path d="M0 0"/></svg>', 'icon_library_svg_declaration' ),
			'processing_instruction' => array( '<?xml-stylesheet href="https://example.com/x.css"?><svg><path d="M0 0"/></svg>', 'icon_library_svg_declaration' ),
		);
	}

	public function test_accepts_core_compatible_svg() {
		$result = ( new SvgSanitizer() )->sanitize_custom( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24z"/></svg>' );
		$this->assertIsString( $result );
	}
}
