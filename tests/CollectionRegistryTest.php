<?php
/**
 * Tests variant activation state.
 *
 * @package IconLibrary
 */

use IconLibrary\CollectionRegistry;
use IconLibrary\ManifestLoader;
use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

class CollectionRegistryTest extends TestCase {
	/**
	 * Creates a registry with a two-variant fixture.
	 *
	 * @return CollectionRegistry
	 */
	private function registry( $slug = 'test' ) {
		$loader   = $this->getMockBuilder( ManifestLoader::class )->disableOriginalConstructor()->onlyMethods( array( 'get_collection_slugs', 'get_manifest' ) )->getMock();
		$manifest = array(
			'slug'        => $slug,
			'name'        => 'Test',
			'description' => 'Test',
			'version'     => '1',
			'variants'    => array(
				array(
					'slug'      => 'solid',
					'label'     => 'Solid',
					'iconCount' => 2,
				),
				array(
					'slug'           => 'outline',
					'label'          => 'Outline',
					'coreCompatible' => false,
					'defaultEnabled' => false,
					'iconCount'      => 1,
				),
			),
			'categories'  => array(
				array(
					'slug'      => 'accessibility',
					'label'     => 'Accessibility',
					'iconCount' => 2,
				),
				array(
					'slug'      => 'business',
					'label'     => 'Business',
					'iconCount' => 1,
				),
			),
			'icons'       => array(
				array(
					'id'           => 'test/solid/one',
					'coreIconName' => 'test/one-solid',
					'label'        => 'One',
					'variant'      => 'solid',
					'categories'   => array( 'accessibility' ),
					'keywords'     => array(),
					'path'         => 'solid/one.svg',
					'sha256'       => str_repeat( 'a', 64 ),
				),
				array(
					'id'           => 'test/solid/two',
					'coreIconName' => 'test/two-solid',
					'label'        => 'Two',
					'variant'      => 'solid',
					'categories'   => array( 'business' ),
					'keywords'     => array(),
					'path'         => 'solid/two.svg',
					'sha256'       => str_repeat( 'b', 64 ),
				),
				array(
					'id'           => 'test/outline/three',
					'coreIconName' => 'test/three-outline',
					'label'        => 'Three',
					'variant'      => 'outline',
					'categories'   => array( 'accessibility' ),
					'keywords'     => array(),
					'path'         => 'outline/three.svg',
					'sha256'       => str_repeat( 'c', 64 ),
				),
			),
		);
		$loader->method( 'get_collection_slugs' )->willReturn( array( $slug ) );
		$loader->method( 'get_manifest' )->willReturn( $manifest );
		return new CollectionRegistry( $loader );
	}

	/** Sets the installed collection fixture. */
	protected function setUp(): void {
		$GLOBALS['icon_library_test_options'] = array( Plugin::OPTION_ENABLED_COLLECTIONS => array( 'test' ) );
	}

	/** Missing state enables only variants marked as defaults. */
	public function test_missing_variant_state_enables_default_variants() {
		$this->assertSame( array( 'solid' ), $this->registry()->get_enabled_variants( 'test' ) );
	}

	/** Disabling a variant leaves its parent library installed. */
	public function test_variant_can_be_disabled_without_disabling_collection() {
		$registry = $this->registry();
		$this->assertTrue( $registry->set_variant_enabled( 'test', 'outline', false ) );
		$this->assertSame( array( 'solid' ), $registry->get_enabled_variants( 'test' ) );
		$this->assertSame( array( 'test' ), $registry->get_enabled_collection_slugs() );
		$this->assertTrue( $registry->set_variant_enabled( 'test', 'outline', false ) );
	}

	/** Applying an existing collection state is an idempotent success. */
	public function test_collection_state_is_idempotent() {
		$registry = $this->registry();
		$this->assertTrue( $registry->set_collection_enabled( 'test', true ) );
		$this->assertTrue( $registry->set_collection_enabled( 'test', false ) );
		$this->assertTrue( $registry->set_collection_enabled( 'test', false ) );
	}

	/** A single query returns pagination data and variant facets. */
	public function test_query_returns_items_total_and_variant_facets() {
		$query = $this->registry()->query_icons(
			array(
				'collection' => 'test',
				'variant'    => 'solid',
				'per_page'   => 1,
			)
		);
		$this->assertCount( 1, $query['items'] );
		$this->assertSame( 2, $query['total'] );
		$this->assertSame(
			array(
				'solid'   => 2,
				'outline' => 1,
			),
			$query['variant_counts']
		);
	}

	/** Unknown variants are rejected. */
	public function test_unknown_variant_is_rejected() {
		$this->assertFalse( $this->registry()->set_variant_enabled( 'test', 'unknown', false ) );
	}

	/** Category metadata is retained in collection summaries and filters. */
	public function test_exposes_category_metadata_and_filters_icons() {
		$registry = $this->registry();
		$summary  = $registry->get_collection( 'test' );

		$this->assertSame( 'Accessibility', $summary['categories'][0]['label'] );
		$this->assertSame(
			array( 'One', 'Three' ),
			wp_list_pluck(
				$registry->get_icons(
					array(
						'collection' => 'test',
						'category'   => 'accessibility',
					)
				),
				'label'
			)
		);
		$this->assertSame(
			0,
			$registry->count_icons(
				array(
					'collection' => 'test',
					'category'   => 'missing',
				)
			)
		);
	}

	/** Variant counts respect the active category and search filters. */
	public function test_counts_icons_by_variant_for_active_filters() {
		$registry = $this->registry();

		$this->assertSame(
			array(
				'solid'   => 1,
				'outline' => 1,
			),
			$registry->count_icons_by_variant(
				array(
					'collection' => 'test',
					'category'   => 'accessibility',
				)
			)
		);
		$this->assertSame(
			1,
			$registry->count_icons_by_variant(
				array(
					'collection' => 'test',
					'category'   => 'business',
				)
			)['solid']
		);
		$this->assertSame(
			array( 'outline' => 1 ),
			$registry->count_icons_by_variant(
				array(
					'collection' => 'test',
					'search'     => 'three',
				)
			)
		);
	}

	/** Explicit activation enables an experimental variant. */
	public function test_experimental_variant_can_be_enabled() {
		$registry = $this->registry();
		$this->assertTrue( $registry->set_variant_enabled( 'test', 'outline', true ) );
		$this->assertSame( array( 'solid', 'outline' ), $registry->get_enabled_variants( 'test' ) );
	}

	/** Legacy 24px Heroicons state maps to the Solid style. */
	public function test_migrates_legacy_heroicons_solid_state() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'heroicons' );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ]    = array( 'heroicons' => array( '24-solid' ) );

		$this->assertSame( array( 'solid' ), $this->registry( 'heroicons' )->get_enabled_variants( 'heroicons' ) );
	}
}
