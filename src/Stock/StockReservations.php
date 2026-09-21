<?php
/**
 * Computes reserved quantities from recent unfinished orders.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

use FGSyncOblio\Order\OrderMeta;
final class StockReservations {

	private const TRANSIENT     = 'oblio_fgwoo_stock_reservations';
	private const LOOKBACK_DAYS = 30;

	private const PAGE_SIZE = 1000;

	public function map(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map = $this->build();
		set_transient( self::TRANSIENT, $map, HOUR_IN_SECONDS );
		return $map;
	}

	public function reset(): void {
		delete_transient( self::TRANSIENT );
	}

	private function build(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$configured = (int) get_option( 'oblio_fgwoo_stock_reserve_days', self::LOOKBACK_DAYS );
		$lookback   = (int) apply_filters( 'oblio_fgwoo_stock_reservation_lookback_days', $configured > 0 ? $configured : self::LOOKBACK_DAYS );
		$limit      = (int) apply_filters( 'oblio_fgwoo_stock_reservation_max_orders', 0 );
		$statuses   = (array) apply_filters( 'oblio_fgwoo_stock_reservation_statuses', array( 'on-hold', 'processing', 'pending' ) );

		$args = array(
			'status'       => $statuses,
			'date_created' => '>' . ( time() - max( 1, $lookback ) * DAY_IN_SECONDS ),
			'return'       => 'ids',
			'orderby'      => 'ID',
			'order'        => 'ASC',

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once-per-run watchdog, indexed doc meta.
			'meta_query'   => array(
				'relation' => 'OR',
				array(
					'key'     => OrderMeta::key( OrderMeta::TYPE_INVOICE, 'use_stock' ),
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => OrderMeta::key( OrderMeta::TYPE_INVOICE, 'use_stock' ),
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		$order_ids = array();
		$page      = 1;
		do {
			$batch     = wc_get_orders(
				array_merge(
					$args,
					array(
						'limit' => self::PAGE_SIZE,
						'paged' => $page,
					)
				)
			);
			$batch     = array_map( 'intval', (array) $batch );
			$fetched   = count( $batch );
			$order_ids = array_merge( $order_ids, $batch );
			++$page;

			if ( $limit > 0 && count( $order_ids ) >= $limit ) {
				$order_ids = array_slice( $order_ids, 0, $limit );
				break;
			}
		} while ( self::PAGE_SIZE === $fetched );

		return $this->sum_reserved_quantities( $order_ids );
	}

	private function sum_reserved_quantities( array $order_ids ): array {
		$order_ids = array_values( array_filter( $order_ids ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		global $wpdb;
		$map = array();

		foreach ( array_chunk( $order_ids, self::PAGE_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pid.meta_value AS product_id, COALESCE( vid.meta_value, 0 ) AS variation_id, SUM( qty.meta_value ) AS qty
						FROM {$wpdb->prefix}woocommerce_order_items oi
						INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
						INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
						LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta vid ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
						WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ($placeholders)
						GROUP BY product_id, variation_id",
					$chunk
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$variation = (int) ( $row['variation_id'] ?? 0 );
				$product   = (int) ( $row['product_id'] ?? 0 );
				$pid       = $variation > 0 ? $variation : $product;
				if ( $pid <= 0 ) {
					continue;
				}
				$map[ $pid ] = ( $map[ $pid ] ?? 0 ) + (int) ( $row['qty'] ?? 0 );
			}
		}

		return $map;
	}
}
