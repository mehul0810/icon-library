<?php
/**
 * SVG sanitization.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use DOMDocument;
use DOMElement;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes static SVG icons before output or core registration.
 */
class SvgSanitizer {
	const MAX_FILE_SIZE = 65536;

	/**
	 * WordPress 7.1 icon geometry contract.
	 *
	 * @var array<string,string[]>
	 */
	private const CUSTOM_ALLOWED = array(
		'svg'     => array( 'class', 'xmlns', 'width', 'height', 'viewbox', 'aria-hidden', 'role', 'focusable' ),
		'path'    => array( 'fill', 'fill-rule', 'd', 'transform' ),
		'polygon' => array( 'fill', 'fill-rule', 'points', 'transform', 'focusable' ),
	);

	/**
	 * Strictly validates administrator-provided SVG before persistence.
	 *
	 * @param string $svg SVG markup.
	 * @return string|WP_Error Normalized SVG or an error.
	 */
	public function sanitize_custom( $svg ) {
		if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
			return new WP_Error( 'icon_library_svg_empty', __( 'The SVG file is empty.', 'icon-library' ) );
		}

		if ( self::MAX_FILE_SIZE < strlen( $svg ) ) {
			return new WP_Error( 'icon_library_svg_too_large', __( 'SVG files must be 64 KB or smaller.', 'icon-library' ) );
		}

		if ( false !== stripos( $svg, '<!doctype' ) || false !== stripos( $svg, '<!entity' ) ) {
			return new WP_Error( 'icon_library_svg_declaration', __( 'SVG document declarations are not allowed.', 'icon-library' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$loaded   = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $document->documentElement instanceof DOMElement || 'svg' !== strtolower( $document->documentElement->tagName ) ) {
			return new WP_Error( 'icon_library_svg_invalid', __( 'The file is not a valid SVG document.', 'icon-library' ) );
		}

		$namespace = $document->documentElement->namespaceURI;
		if ( $namespace && 'http://www.w3.org/2000/svg' !== $namespace ) {
			return new WP_Error( 'icon_library_svg_namespace', __( 'The SVG namespace is not supported.', 'icon-library' ) );
		}

		$geometry_count = 0;
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			$tag = strtolower( $element->localName ? $element->localName : $element->tagName );

			if ( ! isset( self::CUSTOM_ALLOWED[ $tag ] ) ) {
				/* translators: %s: SVG element name. */
				return new WP_Error( 'icon_library_svg_element', sprintf( __( 'Unsupported SVG element: %s.', 'icon-library' ), $tag ) );
			}

			if ( 'path' === $tag || 'polygon' === $tag ) {
				++$geometry_count;
			}

			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name = strtolower( $attribute->localName ? $attribute->localName : $attribute->name );
				if ( ! in_array( $name, self::CUSTOM_ALLOWED[ $tag ], true ) ) {
					/* translators: 1: SVG attribute name, 2: SVG element name. */
					return new WP_Error( 'icon_library_svg_attribute', sprintf( __( 'Unsupported SVG attribute: %1$s on %2$s.', 'icon-library' ), $name, $tag ) );
				}
				if ( $attribute->namespaceURI && 'http://www.w3.org/2000/xmlns/' !== $attribute->namespaceURI ) {
					return new WP_Error( 'icon_library_svg_namespace', __( 'Namespaced SVG attributes are not supported.', 'icon-library' ) );
				}
				if ( 1 === preg_match( '/(?:url\s*\(|javascript:|data:|https?:|\/\/)/i', $attribute->value ) ) {
					return new WP_Error( 'icon_library_svg_reference', __( 'External or executable SVG references are not allowed.', 'icon-library' ) );
				}
			}
		}

		if ( 0 === $geometry_count ) {
			return new WP_Error( 'icon_library_svg_geometry', __( 'The SVG contains no supported visible geometry.', 'icon-library' ) );
		}

		$normalized = $document->saveXML( $document->documentElement );
		if ( ! is_string( $normalized ) || '' === trim( $normalized ) ) {
			return new WP_Error( 'icon_library_svg_invalid', __( 'The SVG could not be normalized.', 'icon-library' ) );
		}

		return trim( $normalized );
	}
	/**
	 * Sanitizes SVG markup.
	 *
	 * @param string $svg SVG markup.
	 * @return string
	 */
	public function sanitize( $svg ) {
		if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
			return '';
		}

		$svg = preg_replace( '/<\?xml.*?\?>/is', '', $svg );
		$svg = preg_replace( '/<!doctype.*?>/is', '', $svg );
		$svg = preg_replace( '/<!--.*?-->/s', '', $svg );

		$sanitized = wp_kses( $svg, self::get_allowed_svg_tags() );

		if ( ! is_string( $sanitized ) || false === stripos( $sanitized, '<svg' ) ) {
			return '';
		}

		/**
		 * Filters sanitized SVG markup before registration or output.
		 *
		 * @param string $sanitized Sanitized SVG markup.
		 * @param string $svg       Original SVG markup.
		 */
		return apply_filters( 'icon_library_svg_markup', $sanitized, $svg );
	}

	/**
	 * Returns the SVG allowlist for curated bundled icons.
	 *
	 * @return array
	 */
	public static function get_allowed_svg_tags() {
		$common_shape_attributes = array(
			'class'           => true,
			'clip-rule'       => true,
			'cx'              => true,
			'cy'              => true,
			'd'               => true,
			'fill'            => true,
			'fill-rule'       => true,
			'height'          => true,
			'opacity'         => true,
			'points'          => true,
			'r'               => true,
			'rx'              => true,
			'ry'              => true,
			'stroke'          => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'stroke-width'    => true,
			'transform'       => true,
			'viewbox'         => true,
			'width'           => true,
			'x'               => true,
			'x1'              => true,
			'x2'              => true,
			'y'               => true,
			'y1'              => true,
			'y2'              => true,
		);

		return array(
			'svg'      => array(
				'aria-hidden'     => true,
				'class'           => true,
				'clip-rule'       => true,
				'fill'            => true,
				'fill-rule'       => true,
				'focusable'       => true,
				'height'          => true,
				'role'            => true,
				'stroke'          => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'stroke-width'    => true,
				'viewbox'         => true,
				'width'           => true,
				'xmlns'           => true,
			),
			'g'        => $common_shape_attributes,
			'path'     => $common_shape_attributes,
			'circle'   => $common_shape_attributes,
			'ellipse'  => $common_shape_attributes,
			'line'     => $common_shape_attributes,
			'polygon'  => $common_shape_attributes,
			'polyline' => $common_shape_attributes,
			'rect'     => $common_shape_attributes,
		);
	}
}
