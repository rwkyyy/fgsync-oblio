<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs for pure-logic unit tests.
 *
 * These tests exercise the plugin's framework-agnostic logic (encryption,
 * mappers, registries, importer) without a full WordPress install.
 *
 * @package FGSyncOblio
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
define( 'FGSYNC_OBLIO_VERSION', '1.0.2' );
define( 'FGSYNC_OBLIO_DIR', dirname( __DIR__ ) . '/' );
define( 'FGSYNC_OBLIO_URL', 'https://example.test/' );
define( 'FGSYNC_OBLIO_BASENAME', 'fgsync-oblio/fgsync-oblio.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );

$GLOBALS['oblio_test_options']          = array();
$GLOBALS['oblio_test_transients']       = array();
$GLOBALS['oblio_test_filter_overrides'] = array();

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'FGSyncOblio\\' ) ) {
			return;
		}
		$path = FGSYNC_OBLIO_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( 'FGSyncOblio\\' ) ) ) . '.php';
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
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true ) {
		return substr( str_replace( '.', '', uniqid( 'p', true ) ), 0, max( 1, $length ) );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		if ( 'timestamp' === $type || 'U' === $type ) {
			return time();
		}
		return gmdate( (string) $type );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( $min, $max );
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
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( array_key_exists( $tag, $GLOBALS['oblio_test_filter_overrides'] ) ) {
			return $GLOBALS['oblio_test_filter_overrides'][ $tag ];
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {
	}
}

/**
 * Records every add_action() call (hook, callback, priority, accepted_args)
 * instead of dispatching - there is no real hook-firing pub/sub in this
 * bootstrap (do_action() above is a no-op), so a test verifies wiring is
 * correct by asserting on this list, then exercises the callback's actual
 * logic by invoking it directly.
 */
$GLOBALS['oblio_test_add_action_calls'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['oblio_test_add_action_calls'][] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

/**
 * Fake WC_Logger recording every call, so Support\Logger (which no-ops
 * silently unless wc_get_logger() exists) becomes observable in tests
 * instead of always being a black hole.
 */
$GLOBALS['oblio_test_wc_logs'] = array();
if ( ! class_exists( 'FakeWcLogger' ) ) {
	final class FakeWcLogger {
		public function log( string $level, string $message, array $context = array() ): void {
			$GLOBALS['oblio_test_wc_logs'][] = array(
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
		}
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new FakeWcLogger();
	}
}
/**
 * Minimal AJAX-handler stubs. wp_send_json_*() normally ends the request via
 * wp_die() - a real, uncaught exit, which application code never wraps in
 * its own try/catch. Throwing a stand-in here instead would get swallowed by
 * a handler's OWN catch(Throwable) block (every Throwable, by definition,
 * including a made-up marker class) and misread as an application error - so
 * this only RECORDS the payload and returns normally. Callers that rely on
 * wp_die() actually halting (falling through past an early-rejection guard
 * with no explicit return) aren't faithfully covered by this stub; keep that
 * in mind for any future test of a rejection path.
 */
$GLOBALS['oblio_test_json_response'] = null;
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return $GLOBALS['oblio_test_current_user_can'] ?? true;
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		$GLOBALS['oblio_test_json_response'] = array(
			'success' => true,
			'data'    => $data,
		);
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		$GLOBALS['oblio_test_json_response'] = array(
			'success' => false,
			'data'    => $data,
		);
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
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

// Action Scheduler stubs: record every call and let a test flip
// oblio_test_as_fail to make the next scheduling call report failure
// (returns 0), matching Action Scheduler's real failure contract.
$GLOBALS['oblio_test_as_calls']   = array();
$GLOBALS['oblio_test_as_next_id'] = 1;
$GLOBALS['oblio_test_as_fail']    = false;

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
		$GLOBALS['oblio_test_as_calls'][] = array(
			'type'  => 'async',
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		if ( $GLOBALS['oblio_test_as_fail'] ) {
			return 0;
		}
		return $GLOBALS['oblio_test_as_next_id']++;
	}
}
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
		$GLOBALS['oblio_test_as_calls'][] = array(
			'type'      => 'single',
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
		);
		if ( $GLOBALS['oblio_test_as_fail'] ) {
			return 0;
		}
		return $GLOBALS['oblio_test_as_next_id']++;
	}
}
$GLOBALS['oblio_test_as_unschedule_calls'] = array();
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		$GLOBALS['oblio_test_as_unschedule_calls'][] = array(
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return null;
	}
}
if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
		return false;
	}
}
if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
		return false;
	}
}
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ) {
		if ( $GLOBALS['oblio_test_as_fail'] ) {
			return 0;
		}
		return $GLOBALS['oblio_test_as_next_id']++;
	}
}

/**
 * Minimal $wpdb double covering only the fixed query shapes AtomicLock
 * emits (INSERT IGNORE / SELECT / UPDATE ... WHERE value = / DELETE ... WHERE
 * value =). Shares its backing store with the get_option()/update_option()/
 * delete_option() stubs above ($GLOBALS['oblio_test_options']), the same way
 * a real wp_options table backs both raw $wpdb queries and the Options API -
 * AtomicLock::is_locked()/force_release() use the Options API while
 * acquire()/renew()/release() use raw $wpdb, and both need to see the same
 * data for a test to exercise the full lock lifecycle.
 */
if ( ! class_exists( 'FakeWpdb' ) ) {
	final class FakeWpdb {

		public string $options    = 'wp_options';
		public string $prefix     = 'wp_';
		public string $posts      = 'wp_posts';
		public string $postmeta   = 'wp_postmeta';
		public string $last_error = '';

		/** @var array<int,string> */
		public array $get_results_calls = array();

		/** @var array<int,array<string,mixed>>|null */
		public ?array $next_get_results_return = null;

		/**
		 * Records the final (already-prepared) SQL and returns a canned
		 * result set queued via next_get_results_return, or an empty array -
		 * this double never executes a real JOIN, it only lets a test both
		 * assert on the exact WHERE-clause SQL a query builder produced and
		 * feed back rows to exercise the PHP-side aggregation of the result.
		 */
		public function get_results( string $query, $output = 'OBJECT' ) {
			$this->get_results_calls[] = $query;
			if ( null !== $this->next_get_results_return ) {
				$rows                          = $this->next_get_results_return;
				$this->next_get_results_return = null;
				return $rows;
			}
			return array();
		}

		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return (string) preg_replace_callback(
				'/%[sd]/',
				static function ( $matches ) use ( $args, &$i ) {
					$value = $args[ $i ] ?? '';
					++$i;
					if ( '%d' === $matches[0] ) {
						return (string) (int) $value;
					}
					return "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function query( string $sql ) {
			if ( preg_match( "/^INSERT IGNORE INTO {$this->options} \\(option_name, option_value, autoload\\) VALUES \\('(.*)', '(.*)', 'no'\\)$/s", $sql, $m ) ) {
				$name = stripslashes( $m[1] );
				if ( array_key_exists( $name, $GLOBALS['oblio_test_options'] ) ) {
					return 0;
				}
				$GLOBALS['oblio_test_options'][ $name ] = stripslashes( $m[2] );
				return 1;
			}
			if ( preg_match( "/^UPDATE {$this->options} SET option_value = '(.*)' WHERE option_name = '(.*)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
				$value    = stripslashes( $m[1] );
				$name     = stripslashes( $m[2] );
				$expected = stripslashes( $m[3] );
				if ( ! array_key_exists( $name, $GLOBALS['oblio_test_options'] ) || $GLOBALS['oblio_test_options'][ $name ] !== $expected ) {
					return 0;
				}
				$GLOBALS['oblio_test_options'][ $name ] = $value;
				return 1;
			}
			if ( preg_match( "/^DELETE FROM {$this->options} WHERE option_name = '(.*)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
				$name     = stripslashes( $m[1] );
				$expected = stripslashes( $m[2] );
				if ( ! array_key_exists( $name, $GLOBALS['oblio_test_options'] ) || $GLOBALS['oblio_test_options'][ $name ] !== $expected ) {
					return 0;
				}
				unset( $GLOBALS['oblio_test_options'][ $name ] );
				return 1;
			}
			throw new \RuntimeException( 'FakeWpdb: unrecognized query: ' . $sql );
		}

		public function get_var( string $sql ) {
			if ( preg_match( "/^SELECT option_value FROM {$this->options} WHERE option_name = '(.*)'$/s", $sql, $m ) ) {
				$name = stripslashes( $m[1] );
				return $GLOBALS['oblio_test_options'][ $name ] ?? null;
			}
			throw new \RuntimeException( 'FakeWpdb: unrecognized query: ' . $sql );
		}
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/order-util-stub.php';

/**
 * Wraps a single order-meta key/value pair - only get_data() is called
 * anywhere (ClientMapper::find_meta_by_pattern()), so that's the only method
 * that needs to exist; named after the real WC_Meta_Data class on the
 * chance a future test type-hints it.
 */
if ( ! class_exists( 'WC_Meta_Data' ) ) {
	final class WC_Meta_Data {

		private string $key;

		private $value;

		public function __construct( string $key, $value ) {
			$this->key   = $key;
			$this->value = $value;
		}

		public function get_data(): array {
			return array(
				'key'   => $this->key,
				'value' => $this->value,
			);
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private int $id;

		private array $meta = array();

		/** @var array<int,object> */
		private array $refunds = array();

		/** @var array<int,WC_Order_Item_Product> */
		private array $items = array();

		/** @var array<int,WC_Order_Item_Fee> */
		private array $fees = array();

		/** @var array<string,string> */
		private array $billing = array(
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'city'       => '',
			'address_1'  => '',
			'address_2'  => '',
			'email'      => '',
			'phone'      => '',
			'country'    => '',
			'state'      => '',
		);

		private float $total = 0.0;

		private string $currency = 'RON';

		private float $shipping_total = 0.0;

		private float $shipping_tax = 0.0;

		private string $payment_method = '';

		private ?\DateTime $date_created = null;

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

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

		/** @return array<int,WC_Meta_Data> */
		public function get_meta_data(): array {
			$entries = array();
			foreach ( $this->meta as $key => $value ) {
				$entries[] = new WC_Meta_Data( (string) $key, $value );
			}
			return $entries;
		}

		/**
		 * @param array<int,object> $refunds
		 */
		public function set_refunds( array $refunds ): void {
			$this->refunds = $refunds;
		}

		/** @return array<int,object> */
		public function get_refunds(): array {
			return $this->refunds;
		}

		/** @param array<int,WC_Order_Item_Product> $items */
		public function set_items( array $items ): void {
			$this->items = $items;
		}

		/** @return array<int,WC_Order_Item_Product> */
		public function get_items( $type = 'line_item' ) {
			return $this->items;
		}

		/** @param array<int,WC_Order_Item_Fee> $fees */
		public function set_fees( array $fees ): void {
			$this->fees = $fees;
		}

		/** @return array<int,WC_Order_Item_Fee> */
		public function get_fees(): array {
			return $this->fees;
		}

		/** @param array<string,string> $fields */
		public function set_billing( array $fields ): void {
			$this->billing = array_merge( $this->billing, $fields );
		}

		public function get_billing_first_name(): string {
			return $this->billing['first_name'];
		}

		public function get_billing_last_name(): string {
			return $this->billing['last_name'];
		}

		public function get_billing_company(): string {
			return $this->billing['company'];
		}

		public function get_billing_city(): string {
			return $this->billing['city'];
		}

		public function get_billing_address_1(): string {
			return $this->billing['address_1'];
		}

		public function get_billing_address_2(): string {
			return $this->billing['address_2'];
		}

		public function get_billing_email(): string {
			return $this->billing['email'];
		}

		public function get_billing_phone(): string {
			return $this->billing['phone'];
		}

		public function get_billing_country(): string {
			return $this->billing['country'];
		}

		public function get_billing_state(): string {
			return $this->billing['state'];
		}

		public function set_total( float $value ): void {
			$this->total = $value;
		}

		public function get_total() {
			return $this->total;
		}

		public function set_currency( string $value ): void {
			$this->currency = $value;
		}

		public function get_currency() {
			return $this->currency;
		}

		public function set_shipping_total( float $value ): void {
			$this->shipping_total = $value;
		}

		public function get_shipping_total() {
			return $this->shipping_total;
		}

		public function set_shipping_tax( float $value ): void {
			$this->shipping_tax = $value;
		}

		public function get_shipping_tax() {
			return $this->shipping_tax;
		}

		public function set_payment_method( string $value ): void {
			$this->payment_method = $value;
		}

		public function get_payment_method(): string {
			return $this->payment_method;
		}

		public function set_date_created( ?\DateTime $date ): void {
			$this->date_created = $date;
		}

		public function get_date_created(): ?\DateTime {
			return $this->date_created;
		}
	}
}

/**
 * Only the methods LineItemMapper/RefundService actually call are stubbed
 * (get_all_formatted_meta_data() is deliberately absent - LineItemMapper's
 * description() already handles that via method_exists() and skips it).
 */
if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {

		/** @var array<string,mixed> */
		private array $data;

		private $product;

		/**
		 * @param array<string,mixed> $data
		 * @param WC_Product|null     $product
		 */
		public function __construct( array $data = array(), $product = null ) {
			$this->data = array_merge(
				array(
					'name'         => 'Test product',
					'quantity'     => 1.0,
					'total'        => 0.0,
					'total_tax'    => 0.0,
					'subtotal'     => 0.0,
					'subtotal_tax' => 0.0,
					'product_id'   => 1,
					'variation_id' => 0,
				),
				$data
			);
			$this->product = $product;
		}

		public function get_name(): string {
			return (string) $this->data['name'];
		}

		public function get_quantity() {
			return $this->data['quantity'];
		}

		public function get_total() {
			return $this->data['total'];
		}

		public function get_total_tax() {
			return $this->data['total_tax'];
		}

		public function get_subtotal() {
			return $this->data['subtotal'];
		}

		public function get_subtotal_tax() {
			return $this->data['subtotal_tax'];
		}

		public function get_product_id(): int {
			return (int) $this->data['product_id'];
		}

		public function get_variation_id(): int {
			return (int) $this->data['variation_id'];
		}

		/** @return WC_Product|null */
		public function get_product() {
			return $this->product;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Fee' ) ) {
	class WC_Order_Item_Fee {

		/** @var array<string,mixed> */
		private array $data;

		/** @param array<string,mixed> $data */
		public function __construct( array $data = array() ) {
			$this->data = array_merge(
				array(
					'name'      => 'Fee',
					'total'     => 0.0,
					'total_tax' => 0.0,
				),
				$data
			);
		}

		public function get_name(): string {
			return (string) $this->data['name'];
		}

		public function get_total() {
			return $this->data['total'];
		}

		public function get_total_tax() {
			return $this->data['total_tax'];
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {

		/** @var array<string,mixed> */
		private array $data;

		/** @param array<string,mixed> $data */
		public function __construct( array $data = array() ) {
			$this->data = array_merge(
				array(
					'sku'           => '',
					'regular_price' => '',
					'price'         => '',
					'type'          => 'simple',
				),
				$data
			);
		}

		public function get_sku(): string {
			return (string) $this->data['sku'];
		}

		public function get_regular_price() {
			return $this->data['regular_price'];
		}

		public function get_price() {
			return $this->data['price'];
		}

		public function is_type( string $type ): bool {
			return $type === $this->data['type'];
		}

		public function exists(): bool {
			return true;
		}
	}
}

/**
 * Minimal WP HTTP API stubs: a test queues canned responses
 * (oblio_test_http_responses, shifted per call, defaulting to a bare 200 once
 * empty) instead of this stub understanding requests at all - the same
 * pattern FakeWpdb/wc_get_orders() already use. Lets OblioClient run for
 * real (auth + a document call) with the network itself as the only
 * substituted boundary.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {

		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		if ( empty( $args ) ) {
			return $url;
		}
		$separator = false !== strpos( $url, '?' ) ? '&' : '?';
		return $url . $separator . http_build_query( $args );
	}
}
$GLOBALS['oblio_test_http_responses'] = array();
$GLOBALS['oblio_test_http_calls']     = array();
if ( ! function_exists( 'oblio_test_next_http_response' ) ) {
	function oblio_test_next_http_response() {
		if ( ! empty( $GLOBALS['oblio_test_http_responses'] ) ) {
			return array_shift( $GLOBALS['oblio_test_http_responses'] );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['oblio_test_http_calls'][] = array(
			'method' => 'request',
			'url'    => $url,
			'args'   => $args,
		);
		return oblio_test_next_http_response();
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['oblio_test_http_calls'][] = array(
			'method' => 'post',
			'url'    => $url,
			'args'   => $args,
		);
		return oblio_test_next_http_response();
	}
}

/**
 * In-memory product registry backing wc_get_product() - only consulted by
 * LineItemMapper::regular_price() when an item carries a variation_id.
 */
$GLOBALS['oblio_test_products'] = array();
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) {
		return $GLOBALS['oblio_test_products'][ $product_id ] ?? false;
	}
}

/**
 * Backs Admin\ProductFields' package-number/product-type lookups (single=true
 * only - that's the only form this plugin's own code ever calls).
 */
$GLOBALS['oblio_test_post_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( ! isset( $GLOBALS['oblio_test_post_meta'][ $post_id ][ $key ] ) ) {
			return $single ? '' : array();
		}
		$value = $GLOBALS['oblio_test_post_meta'][ $post_id ][ $key ];
		return $single ? $value : array( $value );
	}
}

if ( ! class_exists( 'WC_Order_Refund' ) ) {
	class WC_Order_Refund extends WC_Order {
		private int $parent_id;

		private float $amount = 0.0;

		private string $reason = '';

		public function __construct( int $id = 0, int $parent_id = 0 ) {
			parent::__construct( $id );
			$this->parent_id = $parent_id;
		}

		public function get_parent_id(): int {
			return $this->parent_id;
		}

		public function set_amount( float $amount ): void {
			$this->amount = $amount;
		}

		public function get_amount() {
			return $this->amount;
		}

		public function set_reason( string $reason ): void {
			$this->reason = $reason;
		}

		public function get_reason(): string {
			return $this->reason;
		}
	}
}

/**
 * In-memory order registry backing wc_get_order() - a test populates it
 * directly (`$GLOBALS['oblio_test_orders'][$id] = new WC_Order($id)`) instead
 * of this stub knowing anything about how orders are created.
 */
$GLOBALS['oblio_test_orders'] = array();
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return $GLOBALS['oblio_test_orders'][ $order_id ] ?? false;
	}
}

/**
 * A test seeds the return value directly (`$GLOBALS['oblio_test_wc_orders_result']
 * = [1, 2]`) and can assert on the args wc_get_orders() was called with via
 * `$GLOBALS['oblio_test_wc_orders_calls']` - this stub doesn't interpret the
 * query args itself, the same way FakeWpdb doesn't execute real SQL.
 */
$GLOBALS['oblio_test_wc_orders_result']       = array();
$GLOBALS['oblio_test_wc_orders_result_queue'] = array();
$GLOBALS['oblio_test_wc_orders_calls']        = array();
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		$GLOBALS['oblio_test_wc_orders_calls'][] = $args;
		if ( ! empty( $GLOBALS['oblio_test_wc_orders_result_queue'] ) ) {
			return array_shift( $GLOBALS['oblio_test_wc_orders_result_queue'] );
		}
		return $GLOBALS['oblio_test_wc_orders_result'];
	}
}

function oblio_test_reset(): void {
	$GLOBALS['oblio_test_options']          = array();
	$GLOBALS['oblio_test_transients']       = array();
	$GLOBALS['oblio_test_filter_overrides'] = array();
	$GLOBALS['oblio_test_as_calls']            = array();
	$GLOBALS['oblio_test_as_next_id']          = 1;
	$GLOBALS['oblio_test_as_fail']             = false;
	$GLOBALS['oblio_test_as_unschedule_calls'] = array();
	$GLOBALS['oblio_test_hpos_enabled']     = false;
	$GLOBALS['oblio_test_wc_logs']          = array();
	$GLOBALS['wpdb']->get_results_calls       = array();
	$GLOBALS['wpdb']->next_get_results_return = null;
	$GLOBALS['wpdb']->last_error               = '';
	$GLOBALS['oblio_test_orders']              = array();
	$GLOBALS['oblio_test_wc_orders_result']    = array();
	$GLOBALS['oblio_test_wc_orders_result_queue'] = array();
	$GLOBALS['oblio_test_wc_orders_calls']     = array();
	$GLOBALS['oblio_test_add_action_calls']    = array();
	$GLOBALS['oblio_test_json_response']       = null;
	$GLOBALS['oblio_test_current_user_can']    = true;
	$GLOBALS['oblio_test_products']            = array();
	$GLOBALS['oblio_test_post_meta']           = array();
	$GLOBALS['oblio_test_http_responses']      = array();
	$GLOBALS['oblio_test_http_calls']          = array();
	$_POST                                     = array();
}
