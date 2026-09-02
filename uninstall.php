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
delete_option( 'icon_library_enabled_variants' );
delete_option( 'icon_library_custom_icons' );
delete_option( 'icon_library_custom_icons_lock' );

$icon_library_uploads   = wp_upload_dir();
$icon_library_directory = empty( $icon_library_uploads['error'] ) ? trailingslashit( $icon_library_uploads['basedir'] ) . 'icon-library/custom-icons' : '';

if ( $icon_library_directory && is_dir( $icon_library_directory ) ) {
	$icon_library_files = glob( $icon_library_directory . '/*.svg' );
	if ( is_array( $icon_library_files ) ) {
		foreach ( $icon_library_files as $icon_library_file ) {
			wp_delete_file( $icon_library_file );
		}
	}
	// Remove only the now-empty plugin-owned directories.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $icon_library_directory );
	$icon_library_parent = dirname( $icon_library_directory );
	if ( is_dir( $icon_library_parent ) && array( '.', '..' ) === scandir( $icon_library_parent ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $icon_library_parent );
	}
}
