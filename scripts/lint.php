<?php
/**
 * Lints all maintained PHP files.
 *
 * @package IconLibrary
 */

$root  = dirname( __DIR__ );
$paths = array( 'icon-library.php', 'uninstall.php', 'src', 'scripts', 'tests' );
$files = array();

foreach ( $paths as $relative_path ) {
	$path = $root . '/' . $relative_path;
	if ( is_file( $path ) ) {
		$files[] = $path;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

sort( $files );
$failed = false;
foreach ( $files as $file ) {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file );
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		$failed = true;
		echo implode( PHP_EOL, $output ) . PHP_EOL;
	}
	$output = array();
}

if ( $failed ) {
	exit( 1 );
}

printf( "PHP syntax valid: %d files.\n", count( $files ) );
