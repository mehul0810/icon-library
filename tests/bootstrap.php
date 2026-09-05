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
$GLOBALS['icon_library_test_registered']   = array(
	'collections' => array(),
	'icons'       => array(),
);
$GLOBALS['icon_library_test_cache']        = array();
$GLOBALS['icon_library_test_filters']      = array();
$GLOBALS['icon_library_test_upload_dir']   = sys_get_temp_dir() . '/icon-library-tests-' . getmypid();
$GLOBALS['icon_library_test_actions']      = array();
$GLOBALS['icon_library_test_abilities']    = array();
$GLOBALS['icon_library_test_categories']   = array();
$GLOBALS['icon_library_test_posts']       = array();

class WP_REST_Request extends ArrayObject {
	private $method;
	private $route;
	private $params;

	public function __construct( $method, $route, $params = array() ) {
		parent::__construct( $params );
		$this->method = $method;
		$this->route  = $route;
		$this->params = $params;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_param( $key ) {
		return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
	}
}

class WP_REST_Response {
	private $data;

	public function __construct( $data ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}

	public function set_data( $data ) {
		$this->data = $data;
	}
}

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

class WP_Icons_Registry {
	private static $instance;
	private $icons = array();

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function set_registered_icon( $name, $properties ) {
		$properties['name']       = $name;
		$properties['collection'] = strtok( $name, '/' );
		if ( ! isset( $properties['content'] ) ) {
			$properties['content'] = '<svg><path d="M0 0"/></svg>';
		}
		$this->icons[ $name ] = $properties;
	}

	public function get_registered_icon( $name ) {
		return isset( $this->icons[ $name ] ) ? $this->icons[ $name ] : null;
	}
}

function __( $text ) {
	return $text;
}


function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['icon_library_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['icon_library_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function wp_register_ability_category( $slug, $args ) {
	$GLOBALS['icon_library_test_categories'][ $slug ] = $args;
	return true;
}

function wp_has_ability_category( $slug ) {
	return isset( $GLOBALS['icon_library_test_categories'][ $slug ] );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['icon_library_test_abilities'][ $name ] = $args;
	return true;
}

function wp_has_ability( $name ) {
	return isset( $GLOBALS['icon_library_test_abilities'][ $name ] );
}

function wp_cache_get( $key, $group = '' ) {
	$cache_key = $group . ':' . $key;
	return array_key_exists( $cache_key, $GLOBALS['icon_library_test_cache'] ) ? $GLOBALS['icon_library_test_cache'][ $cache_key ] : false;
}

function wp_cache_set( $key, $value, $group = '' ) {
	$GLOBALS['icon_library_test_cache'][ $group . ':' . $key ] = $value;
	return true;
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

function absint( $value ) {
	return abs( (int) $value );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function wp_list_pluck( $list, $field ) {
	$values = array();
	foreach ( (array) $list as $item ) {
		if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
			$values[] = $item[ $field ];
		}
	}
	return $values;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['icon_library_test_options'] ) ? $GLOBALS['icon_library_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$changed                                        = ! array_key_exists( $name, $GLOBALS['icon_library_test_options'] ) || $GLOBALS['icon_library_test_options'][ $name ] !== $value;
	$GLOBALS['icon_library_test_options'][ $name ]  = $value;
	$GLOBALS['icon_library_test_autoload'][ $name ] = $autoload;
	return $changed;
}

function add_option( $name, $value, $deprecated = '', $autoload = true ) {
	unset( $deprecated );
	if ( array_key_exists( $name, $GLOBALS['icon_library_test_options'] ) ) {
		return false;
	}
	$GLOBALS['icon_library_test_options'][ $name ]  = $value;
	$GLOBALS['icon_library_test_autoload'][ $name ] = $autoload;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['icon_library_test_options'][ $name ] );
}

function wp_upload_dir() {
	return array(
		'basedir' => $GLOBALS['icon_library_test_upload_dir'],
		'error'   => false,
	);
}

function wp_mkdir_p( $directory ) {
	return is_dir( $directory ) || mkdir( $directory, 0775, true );
}

function wp_delete_file( $file ) {
	return file_exists( $file ) ? unlink( $file ) : true;
}

function get_post( $post_id = null ) {
	$post_id = (int) $post_id;
	return isset( $GLOBALS['icon_library_test_posts'][ $post_id ] ) ? $GLOBALS['icon_library_test_posts'][ $post_id ] : null;
}

function wp_update_post( $postarr, $wp_error = false ) {
	unset( $wp_error );
	$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( ! $post_id || ! isset( $GLOBALS['icon_library_test_posts'][ $post_id ] ) ) {
		return 0;
	}
	if ( isset( $postarr['post_content'] ) ) {
		$GLOBALS['icon_library_test_posts'][ $post_id ]->post_content = $postarr['post_content'];
	}
	return $post_id;
}

$core_dir = getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__, 4 );
if ( ! is_file( $core_dir . '/wp-includes/blocks.php' ) ) {
	throw new RuntimeException( 'Set WP_CORE_DIR to a WordPress checkout to run real block serialization tests.' );
}
require_once $core_dir . '/wp-includes/class-wp-block-parser.php';
require_once $core_dir . '/wp-includes/blocks.php';

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

function has_filter( $hook ) {
	return ! empty( $GLOBALS['icon_library_test_filters'][ $hook ] );
}

function wp_slash( $value ) {
	return $value;
}

function get_post_types( $args = array(), $output = 'names' ) {
	unset( $args, $output );
	return array();
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['icon_library_test_capabilities'][ $capability ] );
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
	WP_Icons_Registry::get_instance()->set_registered_icon( $name, $args );
	return true;
}

function rest_is_field_included( $field, $fields ) {
	return in_array( $field, $fields, true ) || in_array( '*', $fields, true );
}

function rest_ensure_response( $value ) {
	return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value );
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
