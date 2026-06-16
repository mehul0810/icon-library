<?php
/**
 * Uninstall cleanup.
 *
 * @package IconLibrary
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'icon_library_enabled_collections' );
