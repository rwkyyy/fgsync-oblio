<?php
/**
 * HPOS + legacy order data abstraction.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Compat;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
final class OrderStore {

	public function is_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public function get_order( int $order_id ): ?WC_Order {
		if ( $order_id <= 0 ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	public function get_meta( WC_Order $order, string $key, bool $single = true ) {
		return $order->get_meta( $key, $single );
	}

	public function update_meta( WC_Order $order, string $key, $value ): void {
		$order->update_meta_data( $key, $value );
		$order->save();
	}

	public function update_meta_bulk( WC_Order $order, array $data ): void {
		foreach ( $data as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
	}

	public function delete_meta( WC_Order $order, string $key ): void {
		$order->delete_meta_data( $key );
		$order->save();
	}

	public function orders_table(): string {
		global $wpdb;
		return $this->is_hpos() ? OrderUtil::get_table_for_orders() : $wpdb->posts;
	}

	public function meta_table(): string {
		global $wpdb;
		return $this->is_hpos() ? OrderUtil::get_table_for_order_meta() : $wpdb->postmeta;
	}

	public function meta_order_id_column(): string {
		return $this->is_hpos() ? 'order_id' : 'post_id';
	}
}
