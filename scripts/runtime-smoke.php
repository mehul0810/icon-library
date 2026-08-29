<?php
/**
 * Runs a local WordPress editor/frontend lifecycle smoke test.
 *
 * @package IconLibrary
 */

$wp_load = getenv( 'ICON_LIBRARY_WP_LOAD' );
if ( ! $wp_load || ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Set ICON_LIBRARY_WP_LOAD to a readable WordPress wp-load.php path.\n" );
	exit( 1 );
}

require $wp_load;

if ( version_compare( get_bloginfo( 'version' ), '7.1', '<' ) ) {
	fwrite( STDERR, "WordPress 7.1 or newer is required.\n" );
	exit( 1 );
}

$icon_name = 'heroicons/academic-cap-24-solid';
$svg       = wp_get_icon( $icon_name );
if ( ! is_string( $svg ) || false === strpos( $svg, '<svg' ) ) {
	fwrite( STDERR, "The representative Heroicon was not registered.\n" );
	exit( 1 );
}

$content = '<!-- wp:icon {"icon":"' . $icon_name . '"} /-->';
$post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_title'   => 'Icon Library automated smoke',
		'post_content' => $content,
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	fwrite( STDERR, 'Temporary post could not be created: ' . $post_id->get_error_message() . "\n" );
	exit( 1 );
}

$failure = '';
try {
	$stored   = get_post( $post_id );
	$rendered = $stored ? do_blocks( $stored->post_content ) : '';
	if ( ! $stored || $content !== $stored->post_content || false === strpos( $rendered, '<svg' ) ) {
		$failure = 'Icon block save, reload, or frontend rendering failed.';
	}
} finally {
	wp_delete_post( $post_id, true );
}

if ( $failure ) {
	fwrite( STDERR, $failure . "\n" );
	exit( 1 );
}

echo "WordPress 7.1 icon save, reload, and frontend render smoke passed.\n";
