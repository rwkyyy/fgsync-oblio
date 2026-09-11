<?php
/**
 * HPOS + legacy order data abstraction.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Compat;

use Automattic\WooCommerce\Utilities\OrderUtil;
use OblioWoo\Order\OrderMeta;
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

	public function find_by_document( string $doc_type, string $series_name, string $number ): ?WC_Order {
		if ( '' === $series_name || '' === $number || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => OrderMeta::key( $doc_type, 'series' ),
				'value' => $series_name,
			),
			array(
				'key'   => OrderMeta::key( $doc_type, 'number' ),
				'value' => $number,
			),
		);

		if ( in_array( $doc_type, array( OrderMeta::TYPE_INVOICE, OrderMeta::TYPE_PROFORMA ), true ) ) {
			$meta_query = array(
				'relation' => 'OR',
				$meta_query,
				array(
					'relation' => 'AND',
					array(
						'key'   => 'oblio_' . $doc_type . '_series_name',
						'value' => $series_name,
					),
					array(
						'key'   => 'oblio_' . $doc_type . '_number',
						'value' => $number,
					),
				),
			);
		}

		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Rare lookup (webhook), on indexed document meta.
				'meta_query' => $meta_query,
			)
		);

		return ! empty( $ids ) ? $this->get_order( (int) $ids[0] ) : null;
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
