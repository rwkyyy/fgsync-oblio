<?php
/**
 * Test-only OrderUtil stub, in its own file so its namespace declaration can
 * be the first statement (required by PHP, and bootstrap.php already has
 * unrelated code before this point).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( __NAMESPACE__ . '\\OrderUtil' ) ) {
	/**
	 * Toggle via $GLOBALS['oblio_test_hpos_enabled'] to switch
	 * Compat\OrderStore between the HPOS and legacy code paths in a test -
	 * real OrderUtil has no such switch, but the plugin's own code only ever
	 * calls custom_orders_table_usage_is_enabled()/get_table_for_orders()/
	 * get_table_for_order_meta(), so stubbing just those three is enough.
	 */
	class OrderUtil {

		public static function custom_orders_table_usage_is_enabled(): bool {
			return ! empty( $GLOBALS['oblio_test_hpos_enabled'] );
		}

		public static function get_table_for_orders(): string {
			global $wpdb;
			return $wpdb->prefix . 'wc_orders';
		}

		public static function get_table_for_order_meta(): string {
			global $wpdb;
			return $wpdb->prefix . 'wc_orders_meta';
		}
	}
}
