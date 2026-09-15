<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs for pure-logic unit tests.
 *
 * These tests exercise the plugin's framework-agnostic logic (encryption,
 * mappers, registries, importer) without a full WordPress install.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

define( 'ABSPATH', sys_get_temp_dir() . '/' );
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'phpunit-auth-key-0123456789' );
}
define( 'OBLIO_FGWOO_VERSION', '1.0.2' );
define( 'OBLIO_FGWOO_DIR', dirname( __DIR__ ) . '/' );
define( 'OBLIO_FGWOO_URL', 'https://example.test/' );
define( 'OBLIO_FGWOO_BASENAME', 'oblio-fgwoo/oblio-fgwoo.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['oblio_test_options']    = array();
$GLOBALS['oblio_test_transients'] = array();

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'OblioWoo\\' ) ) {
			return;
		}
		$path = OBLIO_FGWOO_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( 'OblioWoo\\' ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['oblio_test_options'] ) ? $GLOBALS['oblio_test_options'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['oblio_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value, $x = '', $y = '' ) {
		if ( ! array_key_exists( $key, $GLOBALS['oblio_test_options'] ) ) {
			$GLOBALS['oblio_test_options'][ $key ] = $value;
		}
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['oblio_test_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['oblio_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['oblio_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['oblio_test_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $key ) {
		return $GLOBALS['oblio_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'phpunit-salt';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {
	}
}
if ( ! function_exists( 'self_admin_url' ) ) {
	function self_admin_url( $path ) {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action ) {
		return $url;
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private array $meta = array();

		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ): void {
			unset( $this->meta[ $key ] );
		}

		public function save(): void {
		}
	}
}
function oblio_test_reset(): void {
	$GLOBALS['oblio_test_options']    = array();
	$GLOBALS['oblio_test_transients'] = array();
}
