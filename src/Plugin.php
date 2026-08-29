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
	const REST_NAMESPACE             = 'icon-library/v1';

	/**
	 * Registers WordPress hooks.
	 */
	public function register() {
		$sanitizer           = new SvgSanitizer();
		$manifest_loader     = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$collection_registry = new CollectionRegistry( $manifest_loader );
		$core_registrar      = new CoreIconRegistrar( $collection_registry, $manifest_loader );
		$rest_controller     = new RestController( $collection_registry );

		add_action( 'init', array( $core_registrar, 'register_icons' ), 20 );
		add_filter( 'rest_post_dispatch', array( $core_registrar, 'filter_core_discovery_response' ), 10, 3 );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );

		if ( is_admin() ) {
			$admin_page = new AdminPage( $collection_registry, $manifest_loader, $sanitizer );
			$admin_page->register();
		}
	}

	/**
	 * Sets the initial enabled collections without autoloading large plugin state.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_ENABLED_COLLECTIONS, false ) ) {
			add_option( self::OPTION_ENABLED_COLLECTIONS, array( 'heroicons' ), '', false );
		}
	}
}
