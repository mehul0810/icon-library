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
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection state service.
	 */
	public function __construct( CollectionRegistry $collection_registry ) {
		$this->collection_registry = $collection_registry;
	}

	/** Registers fallback form handlers. */
	public function register() {
		add_action( 'admin_post_icon_library_toggle_collection', array( $this, 'toggle_collection' ) );
		add_action( 'admin_post_icon_library_toggle_variant', array( $this, 'toggle_variant' ) );
	}

	/** Handles a collection activation toggle. */
	public function toggle_collection() {
		$this->authorize();
		check_admin_referer( 'icon_library_toggle_collection' );
		$collection = isset( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$state      = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$result     = in_array( $state, array( 'activate', 'deactivate' ), true ) && $this->collection_registry->set_collection_enabled( $collection, 'activate' === $state );

		$this->redirect( array( 'icon-library-updated' => $result ? 1 : 0 ) );
	}

	/** Handles a collection variant activation toggle. */
	public function toggle_variant() {
		$this->authorize();
		check_admin_referer( 'icon_library_toggle_variant' );
		$collection = isset( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$variant    = isset( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : '';
		$state      = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$result     = in_array( $state, array( 'activate', 'deactivate' ), true ) && $this->collection_registry->set_variant_enabled( $collection, $variant, 'activate' === $state );

		$this->redirect(
			array(
				'tab'                  => 'library',
				'collection'           => $collection,
				'icon-library-updated' => $result ? 1 : 0,
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
