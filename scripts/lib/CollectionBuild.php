<?php
/**
 * Deterministic icon collection build utilities.
 *
 * @package IconLibrary
 */

namespace IconLibrary\Build;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Validates collection manifests and SVGs against the WordPress 7.1 contract.
 */
final class CollectionBuild {
	const SCHEMA_VERSION = 2;

	/**
	 * WordPress 7.1 SVG elements and attributes accepted by WP_Icons_Registry.
	 *
	 * @var array<string,string[]>
	 */
	private const ALLOWED_SVG = array(
		'svg'     => array( 'class', 'xmlns', 'width', 'height', 'viewbox', 'aria-hidden', 'role', 'focusable' ),
		'path'    => array( 'fill', 'fill-rule', 'd', 'transform' ),
		'polygon' => array( 'fill', 'fill-rule', 'points', 'transform', 'focusable' ),
	);

	/**
	 * Normalizes a trusted upstream SVG or throws when Core would alter it.
	 *
	 * @param string $svg SVG markup.
	 * @return string
	 */
	public static function normalize_svg( $svg ) {
		if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
			throw new RuntimeException( 'SVG content is empty.' );
		}

		if ( false !== stripos( $svg, '<!doctype' ) || false !== stripos( $svg, '<!entity' ) ) {
			throw new RuntimeException( 'SVG document declarations are not allowed.' );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$loaded   = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $document->documentElement instanceof DOMElement || 'svg' !== strtolower( $document->documentElement->tagName ) ) {
			throw new RuntimeException( 'SVG markup is not a valid SVG document.' );
		}

		$geometry_count = 0;
		$elements       = $document->getElementsByTagName( '*' );

		foreach ( $elements as $element ) {
			$tag = strtolower( $element->tagName );

			if ( ! isset( self::ALLOWED_SVG[ $tag ] ) ) {
				throw new RuntimeException( sprintf( 'Unsupported SVG element: %s.', $tag ) );
			}

			if ( 'path' === $tag || 'polygon' === $tag ) {
				++$geometry_count;
			}

			$remove_attributes = array();

			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name = strtolower( $attribute->name );

				if ( in_array( $name, self::ALLOWED_SVG[ $tag ], true ) ) {
					continue;
				}

				if ( 0 === strpos( $name, 'data-' ) ) {
					$remove_attributes[] = $attribute->name;
					continue;
				}

				// Core strips these presentation hints without removing visible geometry.
				if (
					( 'svg' === $tag && 'fill' === $name && 'currentcolor' === strtolower( $attribute->value ) ) ||
					( 'path' === $tag && 'clip-rule' === $name )
				) {
					continue;
				}

				throw new RuntimeException( sprintf( 'Unsupported %1$s attribute on <%2$s>.', $name, $tag ) );
			}

			foreach ( $remove_attributes as $attribute_name ) {
				$element->removeAttribute( $attribute_name );
			}
		}

		if ( 0 === $geometry_count ) {
			throw new RuntimeException( 'SVG contains no supported visible geometry.' );
		}

		$normalized = $document->saveXML( $document->documentElement );

		if ( ! is_string( $normalized ) || '' === trim( $normalized ) ) {
			throw new RuntimeException( 'SVG normalization failed.' );
		}

		return trim( $normalized );
	}

	/**
	 * Validates a collection manifest and its referenced files.
	 *
	 * @param array  $manifest       Decoded manifest.
	 * @param string $collection_dir Collection directory.
	 * @return string[] Validation errors.
	 */
	public static function validate_manifest( $manifest, $collection_dir ) {
		$errors = array();

		foreach ( array( 'schemaVersion', 'slug', 'name', 'description', 'version', 'license', 'source', 'variants', 'icons' ) as $key ) {
			if ( ! array_key_exists( $key, $manifest ) ) {
				$errors[] = sprintf( 'Missing manifest field: %s.', $key );
			}
		}

		if ( self::SCHEMA_VERSION !== ( $manifest['schemaVersion'] ?? null ) ) {
			$errors[] = sprintf( 'schemaVersion must be %d.', self::SCHEMA_VERSION );
		}

		$slug = $manifest['slug'] ?? '';
		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			$errors[] = 'Collection slug is invalid.';
		}

		foreach ( array( 'license', 'source' ) as $metadata_key ) {
			$metadata = $manifest[ $metadata_key ] ?? null;
			if ( ! is_array( $metadata ) || empty( $metadata['name'] ) || empty( $metadata['url'] ) ) {
				$errors[] = sprintf( '%s metadata requires name and url.', ucfirst( $metadata_key ) );
			}
		}

		if ( empty( $manifest['source']['revision'] ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', $manifest['source']['revision'] ) ) {
			$errors[] = 'Source metadata requires a full Git commit revision.';
		}

		if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
			$errors[] = 'Manifest must contain at least one icon.';
			return $errors;
		}

		$ids   = array();
		$names = array();

		foreach ( $manifest['icons'] as $index => $icon ) {
			$prefix = sprintf( 'icons[%d]', $index );

			if ( ! is_array( $icon ) ) {
				$errors[] = $prefix . ' must be an object.';
				continue;
			}

			foreach ( array( 'id', 'coreIconName', 'label', 'variant', 'categories', 'keywords', 'path', 'sha256' ) as $key ) {
				if ( ! array_key_exists( $key, $icon ) ) {
					$errors[] = sprintf( '%1$s is missing %2$s.', $prefix, $key );
				}
			}

			$id   = $icon['id'] ?? '';
			$name = $icon['coreIconName'] ?? '';

			if ( isset( $ids[ $id ] ) ) {
				$errors[] = sprintf( 'Duplicate icon id: %s.', $id );
			}
			if ( isset( $names[ $name ] ) ) {
				$errors[] = sprintf( 'Duplicate Core icon name: %s.', $name );
			}
			$ids[ $id ]     = true;
			$names[ $name ] = true;

			if ( ! is_string( $name ) || 1 !== preg_match( '/^' . preg_quote( (string) $slug, '/' ) . '\/[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $name ) ) {
				$errors[] = sprintf( '%s has an invalid Core icon name.', $prefix );
			}

			$path = self::resolve_svg_path( $collection_dir, $icon['path'] ?? '' );
			if ( null === $path ) {
				$errors[] = sprintf( '%s references an unreadable SVG path.', $prefix );
				continue;
			}

			$content = file_get_contents( $path );
			try {
				self::normalize_svg( $content );
			} catch ( RuntimeException $exception ) {
				$errors[] = sprintf( '%1$s SVG is incompatible: %2$s', $prefix, $exception->getMessage() );
			}

			if ( ! hash_equals( (string) ( $icon['sha256'] ?? '' ), hash_file( 'sha256', $path ) ) ) {
				$errors[] = sprintf( '%s checksum does not match.', $prefix );
			}
		}

		return $errors;
	}

	/**
	 * Resolves a manifest path without allowing traversal outside the collection.
	 *
	 * @param string $collection_dir Collection directory.
	 * @param string $relative_path  Manifest-relative path.
	 * @return string|null
	 */
	private static function resolve_svg_path( $collection_dir, $relative_path ) {
		$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );

		if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) || 'svg' !== strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		$base = realpath( $collection_dir );
		$file = realpath( rtrim( $collection_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative_path );

		if ( false === $base || false === $file || 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $file ) ) {
			return null;
		}

		return $file;
	}
}
