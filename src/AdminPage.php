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
 * Renders Appearance > Icon Library.
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
			__( 'Icon Library', 'icon-library' ),
			__( 'Icon Library', 'icon-library' ),
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
		$icons       = $this->collection_registry->get_icons(
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
		<div class="wrap icon-library-admin">
			<h1><?php esc_html_e( 'Icon Library', 'icon-library' ); ?></h1>
			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Collections', 'icon-library' ); ?></h2>
			<div class="icon-library-collections">
				<?php foreach ( $collections as $collection ) : ?>
					<?php $this->render_collection_card( $collection ); ?>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Icon Browser', 'icon-library' ); ?></h2>
			<?php $this->render_filters( $filters, $collections ); ?>
			<?php $this->render_icon_grid( $icons ); ?>
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
	 * Renders one collection card.
	 *
	 * @param array $collection Collection summary.
	 */
	private function render_collection_card( $collection ) {
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
		?>
		<section class="icon-library-card">
			<div>
				<h3><?php echo esc_html( $collection['name'] ); ?></h3>
				<p><?php echo esc_html( $collection['description'] ); ?></p>
				<dl>
					<div>
						<dt><?php esc_html_e( 'Icons', 'icon-library' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( $collection['iconCount'] ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Variants', 'icon-library' ); ?></dt>
						<dd><?php echo esc_html( implode( ', ', $variant_list ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Version', 'icon-library' ); ?></dt>
						<dd><?php echo esc_html( $collection['version'] ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'License', 'icon-library' ); ?></dt>
						<dd><?php echo esc_html( $license ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Source', 'icon-library' ); ?></dt>
						<dd><?php echo esc_html( $source ); ?></dd>
					</div>
				</dl>
			</div>
			<div class="icon-library-card-actions">
				<span class="icon-library-status <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>">
					<?php echo esc_html( $enabled ? __( 'Enabled', 'icon-library' ) : __( 'Disabled', 'icon-library' ) ); ?>
				</span>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'icon_library_toggle_collection' ); ?>
					<input type="hidden" name="action" value="icon_library_toggle_collection" />
					<input type="hidden" name="collection" value="<?php echo esc_attr( $collection['slug'] ); ?>" />
					<input type="hidden" name="state" value="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>" />
					<?php submit_button( $enabled ? __( 'Deactivate', 'icon-library' ) : __( 'Activate', 'icon-library' ), $enabled ? 'secondary' : 'primary', 'submit', false ); ?>
				</form>
			</div>
		</section>
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
		?>
		<form class="icon-library-filters" method="get" action="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
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
}
