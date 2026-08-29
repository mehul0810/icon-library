<?php
/**
 * Minimal WordPress-compatible unit test bootstrap.
 *
 * @package IconLibrary
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'ICON_LIBRARY_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['icon_library_test_options']      = array();
$GLOBALS['icon_library_test_capabilities'] = array();
$GLOBALS['icon_library_test_registered']   = array( 'collections' => array(), 'icons' => array() );
$GLOBALS['icon_library_test_upload_dir']   = sys_get_temp_dir() . '/icon-library-tests-' . getmypid();

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}

	public function add_data( $data ) {
		$this->data = $data;
	}
}

function __( $text ) {
	return $text;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['icon_library_test_options'] ) ? $GLOBALS['icon_library_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$changed = ! array_key_exists( $name, $GLOBALS['icon_library_test_options'] ) || $GLOBALS['icon_library_test_options'][ $name ] !== $value;
	$GLOBALS['icon_library_test_options'][ $name ] = $value;
	$GLOBALS['icon_library_test_autoload'][ $name ] = $autoload;
	return $changed;
}

function delete_option( $name ) {
	unset( $GLOBALS['icon_library_test_options'][ $name ] );
}

function wp_upload_dir() {
	return array( 'basedir' => $GLOBALS['icon_library_test_upload_dir'], 'error' => false );
}

function wp_mkdir_p( $directory ) {
	return is_dir( $directory ) || mkdir( $directory, 0775, true );
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['icon_library_test_capabilities'][ $capability ] );
}

function get_post_types() {
	return array();
}

function rest_authorization_required_code() {
	return 401;
}

function wp_register_icon_collection( $slug, $args ) {
	$GLOBALS['icon_library_test_registered']['collections'][ $slug ] = $args;
	return true;
}

function wp_register_icon( $name, $args ) {
	$GLOBALS['icon_library_test_registered']['icons'][ $name ] = $args;
	return true;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'IconLibrary\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = ICON_LIBRARY_DIR . 'src/' . substr( $class_name, strlen( $prefix ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
