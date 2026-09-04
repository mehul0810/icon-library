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
	 * Verifies that an importer is reading an unmodified, expected checkout.
	 *
	 * @param string $source_dir       Source checkout.
	 * @param string $expected_url     Expected upstream repository URL.
	 * @param string $license_relative License path inside the checkout.
	 * @return void
	 * @throws RuntimeException When the checkout is not trustworthy.
	 */
	public static function validate_source_checkout( $source_dir, $expected_url, $license_relative ) {
		$root = realpath( $source_dir );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new RuntimeException( 'Source checkout path is invalid.' );
		}

		$status = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' status --porcelain 2>/dev/null' ) );
		if ( '' !== $status ) {
			throw new RuntimeException( 'Source checkout must have a clean Git worktree.' );
		}

		$remote    = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' remote get-url origin 2>/dev/null' ) );
		$normalize = static function ( $url ) {
			$url = trim( (string) $url );
			$url = preg_replace( '#^git@([^:]+):#', 'https://$1/', $url );
			$url = preg_replace( '#\.git$#', '', $url );
			return rtrim( (string) $url, '/' );
		};
		if ( '' === $remote || $normalize( $remote ) !== $normalize( $expected_url ) ) {
			throw new RuntimeException( 'Source checkout remote does not match the expected upstream.' );
		}

		self::get_contained_source_file( $root, $license_relative );
	}

	/**
	 * Resolves one regular, non-symlink file inside an upstream checkout.
	 *
	 * @param string $source_dir       Source checkout.
	 * @param string $relative_path    File path relative to the checkout.
	 * @return string Canonical file path.
	 * @throws RuntimeException When the file is missing or escapes the checkout.
	 */
	public static function get_contained_source_file( $source_dir, $relative_path ) {
		$root = realpath( $source_dir );
		if ( false === $root || ! is_dir( $root ) || ! is_string( $relative_path ) ) {
			throw new RuntimeException( 'Source file path is invalid.' );
		}

		$relative = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$path     = $root . '/' . $relative;
		$resolved = realpath( $path );
		if ( false === $resolved || is_link( $path ) || 0 !== strpos( $resolved, $root . DIRECTORY_SEPARATOR ) || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			throw new RuntimeException( 'Source file must be a readable regular file inside the checkout.' );
		}

		return $resolved;
	}

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
	 * Build-time allowlist for experimental stroked icons.
	 *
	 * WordPress 7.1 does not preserve these attributes at runtime. Keeping the
	 * source allowlist narrow lets us inspect that Core behavior without allowing
	 * scripts, external references, or arbitrary SVG elements into the bundle.
	 *
	 * @var array<string,string[]>
	 */
	private const ALLOWED_STROKED_SVG = array(
		'svg'     => array( 'class', 'xmlns', 'width', 'height', 'viewbox', 'aria-hidden', 'role', 'focusable', 'fill', 'stroke', 'stroke-linecap', 'stroke-linejoin', 'stroke-width' ),
		'path'    => array( 'fill', 'fill-rule', 'd', 'transform', 'stroke', 'stroke-linecap', 'stroke-linejoin', 'stroke-width' ),
		'polygon' => array( 'fill', 'fill-rule', 'points', 'transform', 'focusable', 'stroke', 'stroke-linecap', 'stroke-linejoin', 'stroke-width' ),
		'rect'    => array( 'fill', 'fill-rule', 'x', 'y', 'width', 'height', 'rx', 'ry', 'transform' ),
	);

	/**
	 * Normalizes a trusted upstream SVG or throws when Core would alter it.
	 *
	 * @param string $svg          SVG markup.
	 * @param bool   $allow_stroke Preserve the narrow stroked-icon allowlist.
	 * @return string
	 * @throws RuntimeException When SVG markup is incompatible.
	 */
	public static function normalize_svg( $svg, $allow_stroke = false ) {
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
		$processing_instructions = ( new \DOMXPath( $document ) )->query( '//processing-instruction()' );
		if ( $processing_instructions && $processing_instructions->length > 0 ) {
			throw new RuntimeException( 'SVG processing instructions are not allowed.' );
		}

		$xpath    = new \DOMXPath( $document );
		$comments = $xpath->query( '//comment()' );
		if ( $comments ) {
			foreach ( $comments as $comment ) {
				if ( $comment->parentNode ) {
					$comment->parentNode->removeChild( $comment );
				}
			}
		}

		$allowed_svg    = $allow_stroke ? self::ALLOWED_STROKED_SVG : self::ALLOWED_SVG;
		$geometry_count = 0;
		$elements       = $document->getElementsByTagName( '*' );

		foreach ( $elements as $element ) {
			$tag = strtolower( $element->tagName );

			if ( ! isset( $allowed_svg[ $tag ] ) ) {
				throw new RuntimeException( sprintf( 'Unsupported SVG element: %s.', $tag ) );
			}

			if ( in_array( $tag, array( 'path', 'polygon', 'rect' ), true ) ) {
				++$geometry_count;
			}

			$remove_attributes = array();

			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name = strtolower( $attribute->name );
				if ( $attribute->namespaceURI && 'http://www.w3.org/2000/xmlns/' !== $attribute->namespaceURI ) {
					throw new RuntimeException( 'Namespaced SVG attributes are not supported.' );
				}
				if ( false !== strpos( $attribute->value, '\\' ) || 1 === preg_match( '/(?:url\s*\(|javascript:|data:|https?:|\/\/)/i', $attribute->value ) ) {
					throw new RuntimeException( 'External or executable SVG references are not allowed.' );
				}

				if ( in_array( $name, $allowed_svg[ $tag ], true ) ) {
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
		if ( ! is_array( $manifest ) ) {
			return array( 'Manifest must decode to an object.' );
		}

		foreach ( array( 'schemaVersion', 'slug', 'name', 'description', 'version', 'license', 'source', 'variants', 'icons' ) as $key ) {
			if ( ! array_key_exists( $key, $manifest ) ) {
				$errors[] = sprintf( 'Missing manifest field: %s.', $key );
			}
		}

		if ( self::SCHEMA_VERSION !== ( $manifest['schemaVersion'] ?? null ) ) {
			$errors[] = sprintf( 'schemaVersion must be %d.', self::SCHEMA_VERSION );
		}
		foreach ( array( 'slug', 'name', 'description', 'version' ) as $text_key ) {
			if ( ! is_string( $manifest[ $text_key ] ?? null ) || ( 'description' !== $text_key && '' === trim( $manifest[ $text_key ] ) ) ) {
				$errors[] = sprintf( '%s must be a string%s.', $text_key, 'description' === $text_key ? '' : ' containing text' );
			}
		}

		$slug = is_string( $manifest['slug'] ?? null ) ? $manifest['slug'] : '';
		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			$errors[] = 'Collection slug is invalid.';
		}

		foreach ( array( 'license', 'source' ) as $metadata_key ) {
			$metadata = $manifest[ $metadata_key ] ?? null;
			if ( ! is_array( $metadata ) || ! is_string( $metadata['name'] ?? null ) || '' === trim( $metadata['name'] ?? '' ) || ! is_string( $metadata['url'] ?? null ) || false === filter_var( $metadata['url'], FILTER_VALIDATE_URL ) ) {
				$errors[] = sprintf( '%s metadata requires name and url.', ucfirst( $metadata_key ) );
			}
		}

		$revision = is_array( $manifest['source'] ?? null ) ? ( $manifest['source']['revision'] ?? '' ) : '';
		if ( ! is_string( $revision ) || '' === $revision || 1 !== preg_match( '/^[a-f0-9]{40}$/', $revision ) ) {
			$errors[] = 'Source metadata requires a full Git commit revision.';
		}

		$variant_slugs = array();
		if ( ! is_array( $manifest['variants'] ?? null ) || empty( $manifest['variants'] ) ) {
			$errors[] = 'Manifest must contain at least one variant.';
		}
		foreach ( (array) ( $manifest['variants'] ?? array() ) as $variant_index => $variant ) {
			$prefix = sprintf( 'variants[%d]', $variant_index );
			if ( ! is_array( $variant ) || ! is_string( $variant['slug'] ?? null ) || ! is_string( $variant['label'] ?? null ) || '' === trim( $variant['slug'] ?? '' ) || '' === trim( $variant['label'] ?? '' ) ) {
				$errors[] = $prefix . ' requires slug and label.';
				continue;
			}
			$variant_slug = $variant['slug'];
			if ( ! is_string( $variant_slug ) ) {
				$variant_slug = '';
			}
			if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $variant_slug ) ) {
				$errors[] = sprintf( '%s has an invalid slug.', $prefix );
			}
			if ( isset( $variant_slugs[ $variant_slug ] ) ) {
				$errors[] = sprintf( 'Duplicate variant slug: %s.', $variant_slug );
			}
			if ( array_key_exists( 'iconCount', $variant ) && ( ! is_int( $variant['iconCount'] ) || $variant['iconCount'] < 0 ) ) {
				$errors[] = $prefix . ' iconCount must be a non-negative integer.';
			}
			foreach ( array( 'coreCompatible', 'defaultEnabled' ) as $boolean_key ) {
				if ( array_key_exists( $boolean_key, $variant ) && ! is_bool( $variant[ $boolean_key ] ) ) {
					$errors[] = sprintf( '%s %s must be boolean.', $prefix, $boolean_key );
				}
			}
			$variant_slugs[ $variant_slug ] = false !== ( $variant['coreCompatible'] ?? true );
		}

		if ( array_key_exists( 'categories', $manifest ) ) {
			$category_slugs = array();
			if ( ! is_array( $manifest['categories'] ) ) {
				$errors[] = 'Manifest categories must be an array.';
			} else {
				foreach ( $manifest['categories'] as $category_index => $category ) {
					$prefix = sprintf( 'categories[%d]', $category_index );
					if ( ! is_array( $category ) || empty( $category['slug'] ) || empty( $category['label'] ) || ! array_key_exists( 'iconCount', $category ) ) {
						$errors[] = $prefix . ' requires slug, label, and iconCount.';
						continue;
					}
					$category_slug = $category['slug'];
					if ( ! is_string( $category_slug ) ) {
						$category_slug = '';
					}
					if ( isset( $category_slugs[ $category_slug ] ) ) {
						$errors[] = sprintf( 'Duplicate category slug: %s.', $category_slug );
					}
					if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category_slug ) ) {
						$errors[] = sprintf( '%s has an invalid slug.', $prefix );
					}
					if ( ! is_string( $category['label'] ) || '' === trim( $category['label'] ) ) {
						$errors[] = sprintf( '%s label must be a non-empty string.', $prefix );
					}
					if ( ! is_int( $category['iconCount'] ) || $category['iconCount'] < 0 ) {
						$errors[] = sprintf( '%s iconCount must be a non-negative integer.', $prefix );
					}
					$category_slugs[ $category_slug ] = true;
				}
			}
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

			$id       = $icon['id'] ?? '';
			$name     = $icon['coreIconName'] ?? '';
			$variant  = $icon['variant'] ?? '';
			$id_key   = is_string( $id ) ? $id : '';
			$name_key = is_string( $name ) ? $name : '';
			$variant  = is_string( $variant ) ? $variant : '';
			if ( ! is_string( $id ) || '' === trim( $id ) ) {
				$errors[] = $prefix . ' id must be a non-empty string.';
			}
			if ( ! is_string( $icon['label'] ?? null ) || '' === trim( $icon['label'] ?? '' ) ) {
				$errors[] = $prefix . ' label must be a non-empty string.';
			}
			if ( ! is_string( $icon['variant'] ?? null ) || '' === trim( $variant ) ) {
				$errors[] = $prefix . ' variant must be a non-empty string.';
			}
			foreach ( array( 'categories', 'keywords' ) as $list_key ) {
				if ( ! is_array( $icon[ $list_key ] ?? null ) || array_filter( (array) ( $icon[ $list_key ] ?? array() ), 'is_string' ) !== (array) ( $icon[ $list_key ] ?? array() ) ) {
					$errors[] = sprintf( '%s %s must be an array of strings.', $prefix, $list_key );
				}
			}
			if ( ! is_string( $icon['sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $icon['sha256'] ?? '' ) ) {
				$errors[] = $prefix . ' sha256 must be a lowercase SHA-256 checksum.';
			}

			if ( '' !== $id_key && isset( $ids[ $id_key ] ) ) {
				$errors[] = sprintf( 'Duplicate icon id: %s.', $id );
			}
			if ( '' !== $name_key && isset( $names[ $name_key ] ) ) {
				$errors[] = sprintf( 'Duplicate Core icon name: %s.', $name );
			}
			if ( '' !== $id_key ) {
				$ids[ $id_key ] = true;
			}
			if ( '' !== $name_key ) {
				$names[ $name_key ] = true;
			}

			if ( ! is_string( $name ) || 1 !== preg_match( '/^' . preg_quote( (string) $slug, '/' ) . '\/[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $name ) ) {
				$errors[] = sprintf( '%s has an invalid Core icon name.', $prefix );
			}
			if ( ! isset( $variant_slugs[ $variant ] ) ) {
				$errors[] = sprintf( '%s references an unknown variant.', $prefix );
			}

			if ( ! is_string( $icon['path'] ?? null ) ) {
				$errors[] = $prefix . ' path must be a relative SVG path.';
			}
			$path = self::resolve_svg_path( $collection_dir, $icon['path'] ?? '' );
			if ( null === $path ) {
				$errors[] = sprintf( '%s references an unreadable SVG path.', $prefix );
				continue;
			}

			$content = file_get_contents( $path );
			try {
				self::normalize_svg( $content, isset( $variant_slugs[ $variant ] ) && ! $variant_slugs[ $variant ] );
			} catch ( RuntimeException $exception ) {
				$errors[] = sprintf( '%1$s SVG is incompatible: %2$s', $prefix, $exception->getMessage() );
			}

			$checksum = is_string( $icon['sha256'] ?? null ) ? $icon['sha256'] : '';
			if ( '' === $checksum || ! hash_equals( $checksum, hash_file( 'sha256', $path ) ) ) {
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
		if ( ! is_string( $relative_path ) ) {
			return null;
		}
		$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );

		if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) || 'svg' !== strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		$base = realpath( $collection_dir );
		$file = realpath( rtrim( $collection_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative_path );

		$source_path = rtrim( $collection_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative_path;
		if ( false === $base || false === $file || is_link( $source_path ) || 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		return $file;
	}
}
