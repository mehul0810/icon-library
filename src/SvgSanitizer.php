<?php
/**
 * SVG sanitization.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes static SVG icons before output or core registration.
 */
class SvgSanitizer {
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
				'aria-hidden' => true,
				'class'       => true,
				'clip-rule'   => true,
				'fill'        => true,
				'fill-rule'   => true,
				'focusable'   => true,
				'height'      => true,
				'role'        => true,
				'stroke'      => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'stroke-width'    => true,
				'viewbox'     => true,
				'width'       => true,
				'xmlns'       => true,
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
