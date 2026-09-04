<?php
/**
 * Non-JavaScript admin mutation controller.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles nonce-protected Appearance > Icons form submissions.
 */
class AdminActions {
	/**
	 * Collection state service.
	 *
	 * @var CollectionRegistry
	 */
	private $collection_registry;

	/**
	 * Custom icon repository.
	 *
	 * @var CustomIconRepository
	 */
	private $custom_icons;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry   $collection_registry Collection state service.
	 * @param CustomIconRepository $custom_icons        Custom icon repository.
	 */
	public function __construct( CollectionRegistry $collection_registry, CustomIconRepository $custom_icons ) {
		$this->collection_registry = $collection_registry;
		$this->custom_icons        = $custom_icons;
	}

	/** Registers fallback form handlers. */
	public function register() {
		add_action( 'admin_post_icon_library_toggle_collection', array( $this, 'toggle_collection' ) );
		add_action( 'admin_post_icon_library_toggle_variant', array( $this, 'toggle_variant' ) );
		add_action( 'admin_post_icon_library_upload_custom_icon', array( $this, 'upload_custom_icon' ) );
	}

	/** Handles a collection activation toggle. */
	public function toggle_collection() {
		$this->authorize();
		check_admin_referer( 'icon_library_toggle_collection' );
		$collection = isset( $_POST['collection'] ) && is_string( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$state      = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$result     = in_array( $state, array( 'activate', 'deactivate' ), true ) && $this->collection_registry->set_collection_enabled( $collection, 'activate' === $state );

		$this->redirect( array( 'icon-library-updated' => $result ? 1 : 0 ) );
	}

	/** Handles a collection variant activation toggle. */
	public function toggle_variant() {
		$this->authorize();
		check_admin_referer( 'icon_library_toggle_variant' );
		$collection = isset( $_POST['collection'] ) && is_string( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$variant    = isset( $_POST['variant'] ) && is_string( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : '';
		$state      = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$result     = in_array( $state, array( 'activate', 'deactivate' ), true ) && $this->collection_registry->set_variant_enabled( $collection, $variant, 'activate' === $state );

		$this->redirect(
			array(
				'tab'                  => 'library',
				'collection'           => $collection,
				'icon-library-updated' => $result ? 1 : 0,
			)
		);
	}

	/** Handles a custom SVG upload when JavaScript is unavailable. */
	public function upload_custom_icon() {
		$this->authorize();
		check_admin_referer( 'icon_library_upload_custom_icon' );

		$name      = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$label     = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$file      = isset( $_FILES['svg'] ) && is_array( $_FILES['svg'] ) ? $_FILES['svg'] : array();
		$error     = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;
		$tmp_name  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$file_name = isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '';
		$valid     = UPLOAD_ERR_OK === $error && '' !== $tmp_name && is_uploaded_file( $tmp_name ) && is_readable( $tmp_name );

		if ( $valid && ( '' === $file_name || 'svg' !== strtolower( pathinfo( sanitize_file_name( $file_name ), PATHINFO_EXTENSION ) ) ) ) {
			$valid = false;
		}
		$size = $valid ? filesize( $tmp_name ) : false;
		if ( $valid && ( false === $size || SvgSanitizer::MAX_FILE_SIZE < $size ) ) {
			$valid = false;
		}

		$result = $valid ? $this->custom_icons->create( $name, $label, (string) file_get_contents( $tmp_name ) ) : false;
		$this->redirect(
			array(
				'tab'                  => 'custom',
				'icon-library-updated' => ! is_wp_error( $result ) && false !== $result ? 1 : 0,
			)
		);
	}

	/**
	 * Enforces the capability check shared by mutation handlers.
	 */
	private function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage icon libraries.', 'icon-library' ) );
		}
	}

	/**
	 * Redirects to the plugin screen.
	 *
	 * @param array $args Query arguments.
	 */
	private function redirect( $args ) {
		$args['page'] = AdminPage::MENU_SLUG;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'themes.php' ) ) );
		exit;
	}
}
