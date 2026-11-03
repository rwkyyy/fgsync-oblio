<?php
/**
 * HPOS + legacy order data abstraction.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Compat;

use Automattic\WooCommerce\Utilities\OrderUtil;
use RuntimeException;
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

	/**
	 * Overwrites the plugin's meta (oblio_*) on $order with what the meta table holds. wc_get_order() can return a
	 * copy cached earlier in the same request, which misses a document another request has just saved.
	 *
	 * @param WC_Order $order Order, updated in place, not saved.
	 * @throws RuntimeException When the meta table can't be read, so the caller retries instead of issuing blind.
	 */
	public function reload_plugin_meta( WC_Order $order ): void {
		global $wpdb;

		$meta_table   = $this->meta_table();
		$order_id_col = $this->meta_order_id_column();
		$row_id_col   = $this->is_hpos() ? 'id' : 'meta_id';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$meta_table} WHERE {$order_id_col} = %d AND meta_key LIKE %s ORDER BY {$row_id_col} ASC",
				$order->get_id(),
				'oblio\_%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( esc_html__( 'Nu s-au putut citi documentele comenzii din baza de date; se va reîncerca automat.', 'fgsync-oblio' ) );
		}

		$stored = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( ! array_key_exists( $key, $stored ) ) {
				$stored[ $key ] = (string) $row['meta_value'];
			}
		}

		foreach ( $stored as $key => $raw ) {
			if ( (string) maybe_serialize( $order->get_meta( $key, true, 'edit' ) ) === $raw ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the plugin's own meta, no classes allowed.
			$order->update_meta_data( $key, is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw );
		}

		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) $data['key'];
			if ( ! empty( $data['id'] ) && str_starts_with( $key, 'oblio_' ) && ! array_key_exists( $key, $stored ) ) {
				$order->delete_meta_data( $key );
			}
		}
	}
}
