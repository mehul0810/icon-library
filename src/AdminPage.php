<?php
/**
 * Admin page.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Appearance > Icons.
 */
class AdminPage {
	const MENU_SLUG = 'icon-library';

	/**
	 * Collection registry.
	 *
	 * @var CollectionRegistry
	 */
	private $collection_registry;

	/**
	 * Manifest loader.
	 *
	 * @var ManifestLoader
	 */
	private $manifest_loader;

	/**
	 * SVG sanitizer.
	 *
	 * @var SvgSanitizer
	 */
	private $svg_sanitizer;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 * @param ManifestLoader     $manifest_loader     Manifest loader.
	 * @param SvgSanitizer       $svg_sanitizer       SVG sanitizer.
	 */
	public function __construct( CollectionRegistry $collection_registry, ManifestLoader $manifest_loader, SvgSanitizer $svg_sanitizer ) {
		$this->collection_registry = $collection_registry;
		$this->manifest_loader     = $manifest_loader;
		$this->svg_sanitizer       = $svg_sanitizer;
	}

	/**
	 * Registers admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_icon_library_toggle_collection', array( $this, 'handle_toggle_collection' ) );
	}

	/**
	 * Registers the Appearance submenu.
	 */
	public function register_menu() {
		add_theme_page(
			__( 'Icons', 'icon-library' ),
			__( 'Icons', 'icon-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues screen-specific admin styles.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'appearance_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'icon-library-admin',
			ICON_LIBRARY_URL . 'assets/admin.css',
			array(),
			ICON_LIBRARY_VERSION
		);

		wp_enqueue_script(
			'icon-library-admin',
			ICON_LIBRARY_URL . 'assets/admin.js',
			array( 'wp-api-fetch' ),
			ICON_LIBRARY_VERSION,
			true
		);

		wp_localize_script(
			'icon-library-admin',
			'iconLibraryAdmin',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'restPath' => '/' . Plugin::REST_NAMESPACE . '/collections/',
				'i18n'     => array(
					'updating' => __( 'Updating collection...', 'icon-library' ),
					'error'    => __( 'The collection could not be updated. Try again.', 'icon-library' ),
				),
			)
		);
	}

	/**
	 * Handles collection activation toggles.
	 */
	public function handle_toggle_collection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage icon collections.', 'icon-library' ) );
		}

		check_admin_referer( 'icon_library_toggle_collection' );

		$collection = isset( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$state      = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$enabled    = 'activate' === $state;
		$result     = false;

		if ( in_array( $state, array( 'activate', 'deactivate' ), true ) ) {
			$result = $this->collection_registry->set_collection_enabled( $collection, $enabled );
		}

		$redirect_url = add_query_arg(
			array(
				'page'                 => self::MENU_SLUG,
				'icon-library-updated' => $result ? 1 : 0,
			),
			admin_url( 'themes.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Renders the admin page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$collections = $this->collection_registry->get_collections();
		$filters     = $this->get_filters( $collections );
		$active_tab  = $this->get_active_tab();
		?>
		<div class="wrap icon-library-admin">
			<h1><?php esc_html_e( 'Icons', 'icon-library' ); ?></h1>
			<div class="icon-library-status" role="status" aria-live="polite"></div>
			<?php $this->render_notice(); ?>
			<?php $this->render_tabs( $active_tab ); ?>

			<?php if ( 'browse' === $active_tab ) : ?>
				<?php $this->render_browse_tab( $filters, $collections ); ?>
			<?php else : ?>
				<?php $this->render_library_tab( $collections ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders status notice after a toggle.
	 */
	private function render_notice() {
		if ( ! isset( $_GET['icon-library-updated'] ) ) {
			return;
		}

		$updated = absint( $_GET['icon-library-updated'] );
		$class   = $updated ? 'notice-success' : 'notice-error';
		$message = $updated
			? __( 'Collection settings updated.', 'icon-library' )
			: __( 'Collection settings could not be updated.', 'icon-library' );
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	private function render_tabs( $active_tab ) {
		$tabs = array(
			'library' => __( 'Library', 'icon-library' ),
			'browse'  => __( 'Browse', 'icon-library' ),
		);
		?>
		<nav class="icon-library-tabs" aria-label="<?php esc_attr_e( 'Icon management', 'icon-library' ); ?>">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<?php
				$url = add_query_arg(
					array(
						'page' => self::MENU_SLUG,
						'tab'  => $tab,
					),
					admin_url( 'themes.php' )
				);
				?>
				<a class="icon-library-tab <?php echo $active_tab === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $active_tab === $tab ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the collection library tab.
	 *
	 * @param array[] $collections Collections.
	 */
	private function render_library_tab( $collections ) {
		?>
		<section class="icon-library-panel">
			<h2><?php esc_html_e( 'Collections', 'icon-library' ); ?></h2>
			<div class="icon-library-collection-list">
				<?php foreach ( $collections as $collection ) : ?>
					<?php $this->render_collection_row( $collection ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders the icon browsing tab.
	 *
	 * @param array   $filters     Current filters.
	 * @param array[] $collections Collections.
	 */
	private function render_browse_tab( $filters, $collections ) {
		$icons = $this->collection_registry->get_icons(
			array(
				'collection' => $filters['collection'],
				'variant'    => $filters['variant'],
				'category'   => $filters['category'],
				'search'     => $filters['search'],
				'enabled'    => true,
				'per_page'   => 72,
			)
		);
		?>
		<section class="icon-library-panel">
			<h2><?php esc_html_e( 'Browse Icons', 'icon-library' ); ?></h2>
			<?php $this->render_filters( $filters, $collections ); ?>
			<?php $this->render_icon_grid( $icons ); ?>
		</section>
		<?php
	}

	/**
	 * Renders one collection row.
	 *
	 * @param array $collection Collection summary.
	 */
	private function render_collection_row( $collection ) {
		$enabled      = ! empty( $collection['enabled'] );
		$license      = isset( $collection['license']['name'] ) ? $collection['license']['name'] : '';
		$source       = isset( $collection['source']['name'] ) ? $collection['source']['name'] : '';
		$variant_list = array();

		if ( ! empty( $collection['variants'] ) && is_array( $collection['variants'] ) ) {
			foreach ( $collection['variants'] as $variant ) {
				if ( isset( $variant['label'] ) ) {
					$variant_list[] = $variant['label'];
				}
			}
		}

		$variant_count = count( $variant_list );
		?>
		<div class="icon-library-collection-row">
			<div class="icon-library-collection-main">
				<h3><?php echo esc_html( $collection['name'] ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: 1: icon count, 2: variant labels, 3: collection version, 4: license name, 5: source name. */
						esc_html__( '%1$s icons - %2$s - Version %3$s - %4$s - %5$s', 'icon-library' ),
						esc_html( number_format_i18n( $collection['iconCount'] ) ),
						esc_html( implode( ', ', $variant_list ) ),
						esc_html( $collection['version'] ),
						esc_html( $license ),
						esc_html( $source )
					);
					?>
				</p>
			</div>
			<div class="icon-library-collection-actions">
				<span class="icon-library-variant-summary">
					<?php
					echo esc_html(
						$enabled
							? sprintf(
								/* translators: 1: active variant count, 2: total variant count. */
								__( '%1$d/%2$d variants active', 'icon-library' ),
								$variant_count,
								$variant_count
							)
							: __( 'Inactive', 'icon-library' )
					);
					?>
				</span>
				<form class="icon-library-toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-collection="<?php echo esc_attr( $collection['slug'] ); ?>" data-state="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>">
					<?php wp_nonce_field( 'icon_library_toggle_collection' ); ?>
					<input type="hidden" name="action" value="icon_library_toggle_collection" />
					<input type="hidden" name="collection" value="<?php echo esc_attr( $collection['slug'] ); ?>" />
					<input type="hidden" name="state" value="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>" />
					<button type="submit" class="button button-link">
						<?php echo esc_html( $enabled ? __( 'Deactivate', 'icon-library' ) : __( 'Activate', 'icon-library' ) ); ?>
					</button>
				</form>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders icon browser filters.
	 *
	 * @param array   $filters     Current filters.
	 * @param array[] $collections Collections.
	 */
	private function render_filters( $filters, $collections ) {
		$selected_collection = isset( $collections[ $filters['collection'] ] ) ? $collections[ $filters['collection'] ] : reset( $collections );
		$variants            = isset( $selected_collection['variants'] ) && is_array( $selected_collection['variants'] ) ? $selected_collection['variants'] : array();
		$categories          = $this->get_categories( $filters['collection'] );
		?>
		<form class="icon-library-filters" method="get" action="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="browse" />
			<label>
				<span><?php esc_html_e( 'Collection', 'icon-library' ); ?></span>
				<select name="collection">
					<option value=""><?php esc_html_e( 'All enabled', 'icon-library' ); ?></option>
					<?php foreach ( $collections as $collection ) : ?>
						<?php if ( empty( $collection['enabled'] ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<option value="<?php echo esc_attr( $collection['slug'] ); ?>" <?php selected( $filters['collection'], $collection['slug'] ); ?>>
							<?php echo esc_html( $collection['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Category', 'icon-library' ); ?></span>
				<select name="category">
					<option value=""><?php esc_html_e( 'All categories', 'icon-library' ); ?></option>
					<?php foreach ( $categories as $category ) : ?>
						<option value="<?php echo esc_attr( $category ); ?>" <?php selected( $filters['category'], $category ); ?>>
							<?php echo esc_html( ucwords( str_replace( '-', ' ', $category ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Variant', 'icon-library' ); ?></span>
				<select name="variant">
					<option value=""><?php esc_html_e( 'All variants', 'icon-library' ); ?></option>
					<?php foreach ( $variants as $variant ) : ?>
						<option value="<?php echo esc_attr( $variant['slug'] ); ?>" <?php selected( $filters['variant'], $variant['slug'] ); ?>>
							<?php echo esc_html( $variant['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Search', 'icon-library' ); ?></span>
				<input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" />
			</label>
			<?php submit_button( __( 'Filter', 'icon-library' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Returns categories available for the current collection filter.
	 *
	 * @param string $collection_slug Selected collection slug.
	 * @return string[]
	 */
	private function get_categories( $collection_slug ) {
		$categories = array();
		$slugs      = $collection_slug ? array( $collection_slug ) : $this->collection_registry->get_enabled_collection_slugs();

		foreach ( $slugs as $slug ) {
			$manifest = $this->manifest_loader->get_manifest( $slug );

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				if ( ! empty( $icon['categories'] ) && is_array( $icon['categories'] ) ) {
					$categories = array_merge( $categories, array_map( 'sanitize_key', $icon['categories'] ) );
				}
			}
		}

		$categories = array_values( array_unique( array_filter( $categories ) ) );
		sort( $categories );

		return $categories;
	}

	/**
	 * Renders preview icons.
	 *
	 * @param array[] $icons Icons.
	 */
	private function render_icon_grid( $icons ) {
		if ( empty( $icons ) ) {
			echo '<p>' . esc_html__( 'No icons match the current filters.', 'icon-library' ) . '</p>';
			return;
		}
		?>
		<div class="icon-library-grid">
			<?php foreach ( $icons as $icon ) : ?>
				<?php
				$svg = $this->manifest_loader->get_svg_content( $icon['collection'], $icon['path'] ?? '' );
				$svg = $this->svg_sanitizer->sanitize( $svg );
				?>
				<div class="icon-library-icon">
					<div class="icon-library-icon-preview" aria-hidden="true">
						<?php echo wp_kses( $svg, SvgSanitizer::get_allowed_svg_tags() ); ?>
					</div>
					<div class="icon-library-icon-label"><?php echo esc_html( $icon['label'] ); ?></div>
					<code><?php echo esc_html( $icon['coreIconName'] ); ?></code>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Reads and sanitizes current filters.
	 *
	 * @param array[] $collections Collections.
	 * @return array
	 */
	private function get_filters( $collections ) {
		unset( $collections );

		return array(
			'collection' => isset( $_GET['collection'] ) ? sanitize_key( wp_unslash( $_GET['collection'] ) ) : '',
			'variant'    => isset( $_GET['variant'] ) ? sanitize_key( wp_unslash( $_GET['variant'] ) ) : '',
			'category'   => isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '',
			'search'     => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
		);
	}

	/**
	 * Returns the active page tab.
	 *
	 * @return string
	 */
	private function get_active_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'library';

		return in_array( $tab, array( 'library', 'browse' ), true ) ? $tab : 'library';
	}
}
