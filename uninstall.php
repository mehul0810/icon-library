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
delete_option( 'icon_library_custom_icons' );

$uploads   = wp_upload_dir();
$directory = empty( $uploads['error'] ) ? trailingslashit( $uploads['basedir'] ) . 'icon-library/custom-icons' : '';

if ( $directory && is_dir( $directory ) ) {
	$files = glob( $directory . '/*.svg' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			unlink( $file );
		}
	}
	rmdir( $directory );
	$parent = dirname( $directory );
	if ( is_dir( $parent ) && array( '.', '..' ) === scandir( $parent ) ) {
		rmdir( $parent );
	}
}
