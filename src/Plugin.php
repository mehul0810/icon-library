<?php
/**
 * Plugin composition root.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin services to WordPress hooks.
 */
class Plugin {
	const OPTION_ENABLED_COLLECTIONS = 'icon_library_enabled_collections';
	const OPTION_ENABLED_VARIANTS    = 'icon_library_enabled_variants';
	const REST_NAMESPACE             = 'icon-library/v1';

	/**
	 * Registers WordPress hooks.
	 */
	public function register() {
		$sanitizer           = new SvgSanitizer();
		$custom_icons        = new CustomIconRepository( $sanitizer );
		$manifest_loader     = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$collection_registry = new CollectionRegistry( $manifest_loader, $custom_icons );
		$core_registrar      = new CoreIconRegistrar( $collection_registry );
		$rest_controller     = new RestController( $collection_registry, $custom_icons );

		add_action( 'init', array( $core_registrar, 'register_icons' ), 20 );
		add_action( 'wp', array( $core_registrar, 'register_queried_post_icons' ) );
		add_filter( 'render_block_data', array( $core_registrar, 'register_icon_block' ) );
		add_action( 'enqueue_block_assets', array( $core_registrar, 'enqueue_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $core_registrar, 'enqueue_styles' ) );
		add_filter( 'rest_request_after_callbacks', array( $core_registrar, 'filter_core_discovery_response' ), 10, 3 );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );

		if ( is_admin() ) {
			$admin_page = new AdminPage( $collection_registry, $sanitizer );
			$admin_page->register();
			( new AdminActions( $collection_registry ) )->register();
		}
	}

	/**
	 * Sets the initial enabled collections without autoloading large plugin state.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_ENABLED_COLLECTIONS, false ) ) {
			add_option( self::OPTION_ENABLED_COLLECTIONS, array(), '', false );
		}
	}
}
