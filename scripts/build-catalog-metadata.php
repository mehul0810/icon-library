<?php
/**
 * Generates compact metadata without icon records for admin library listings.
 *
 * @package IconLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$check  = in_array( '--check', $argv, true );
$failed = false;
foreach ( glob( dirname( __DIR__ ) . '/assets/icons/*/manifest.json' ) as $path ) {
	$manifest = json_decode( file_get_contents( $path ), true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['icons'] ) ) {
		fwrite( STDERR, "Invalid manifest: $path\n" );
		exit( 1 );
	}
	$counts = array();
	$total  = 0;
	foreach ( $manifest['icons'] as $icon ) {
		if ( ! empty( $icon['archived'] ) ) {
			continue;
		}
		++$total;
		$variant            = $icon['variant'] ?? '';
		$counts[ $variant ] = ( $counts[ $variant ] ?? 0 ) + 1;
	}
	unset( $manifest['icons'] );
	$manifest['iconCount'] = $total;
	foreach ( $manifest['variants'] as &$variant ) {
		$variant['iconCount'] = $counts[ $variant['slug'] ] ?? 0;
	}
	unset( $variant );
	$output = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	$target = dirname( $path ) . '/metadata.json';
	if ( $check ) {
		if ( ! is_file( $target ) || file_get_contents( $target ) !== $output ) {
			fwrite( STDERR, "Stale catalog metadata: $target\n" );
			$failed = true;
		}
	} elseif ( false === file_put_contents( $target, $output ) ) {
		$failed = true;
	}
}
exit( $failed ? 1 : 0 );
