<?php
/**
 * Verifies the bundled Font Awesome Free manifest metadata.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

class FontAwesomeManifestTest extends TestCase {
	/** Font Awesome Free keeps its three upstream styles distinct. */
	public function test_exposes_free_styles_and_category_index() {
		$manifest = json_decode(
			file_get_contents( dirname( __DIR__ ) . '/assets/icons/font-awesome/manifest.json' ),
			true
		);

		$this->assertIsArray( $manifest );
		$this->assertSame( array( 'solid', 'regular', 'brands' ), wp_list_pluck( $manifest['variants'], 'slug' ) );
		$this->assertSame( array( 'Solid', 'Regular', 'Brands' ), wp_list_pluck( $manifest['variants'], 'label' ) );
		$this->assertGreaterThanOrEqual( 68, count( $manifest['categories'] ) );
		$this->assertSame( 'Accessibility', $manifest['categories'][0]['label'] );
		$this->assertSame( 'Charts + Diagrams', $this->find_category( $manifest, 'charts-diagrams' )['label'] );
	}

	/** Canonical and legacy alias entries share the upstream search taxonomy. */
	public function test_maps_aliases_to_canonical_search_metadata() {
		$manifest = json_decode(
			file_get_contents( dirname( __DIR__ ) . '/assets/icons/font-awesome/manifest.json' ),
			true
		);
		$search   = $this->find_icon( $manifest, 'font-awesome/solid/search' );
		$apple    = $this->find_icon( $manifest, 'font-awesome/brands/apple' );

		$this->assertContains( 'maps', $search['categories'] );
		$this->assertContains( 'magnifying glass', $search['keywords'] );
		$this->assertContains( 'brands', $apple['categories'] );
	}

	/**
	 * Finds a category descriptor.
	 *
	 * @param array  $manifest Font Awesome manifest.
	 * @param string $slug     Category slug.
	 * @return array
	 */
	private function find_category( $manifest, $slug ) {
		foreach ( $manifest['categories'] as $category ) {
			if ( $slug === $category['slug'] ) {
				return $category;
			}
		}

		return array();
	}

	/**
	 * Finds an icon descriptor.
	 *
	 * @param array  $manifest Font Awesome manifest.
	 * @param string $id       Icon ID.
	 * @return array
	 */
	private function find_icon( $manifest, $id ) {
		foreach ( $manifest['icons'] as $icon ) {
			if ( $id === $icon['id'] ) {
				return $icon;
			}
		}

		return array();
	}
}
