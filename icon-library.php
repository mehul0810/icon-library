<?php
/**
 * Plugin Name: Icon Library
 * Description: Enables curated SVG icon collections for the native WordPress Icon block.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Mehul Gohil
 * License: GPL-2.0-or-later
 * Text Domain: icon-library
 *
 * @package IconLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ICON_LIBRARY_VERSION', '0.1.0' );
define( 'ICON_LIBRARY_FILE', __FILE__ );
define( 'ICON_LIBRARY_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICON_LIBRARY_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'IconLibrary\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file           = ICON_LIBRARY_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'IconLibrary\\Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new IconLibrary\Plugin();
		$plugin->register();
	}
);
