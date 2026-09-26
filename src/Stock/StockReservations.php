<?php
/**
 * Computes reserved quantities from recent unfinished orders.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Logger;
use WC_Order;
final class StockReservations {

	private const TRANSIENT     = 'oblio_fgwoo_stock_reservations';
	private const LOOKBACK_DAYS = 30;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( OrderStore $orders, Logger $logger ) {
		$this->orders = $orders;
		$this->logger = $logger;
	}

	/**
	 * @throws ReservationUnavailableException If the query fails. Callers must
	 *                                          not treat that the same as a
	 *                                          legitimate empty map - stock
	 *                                          sync depends on this to decide
	 *                                          whether it's safe to write
	 *                                          quantities without subtracting
	 *                                          reservations.
	 */
	public function map(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map = $this->build();
		if ( null === $map ) {
			// Not cached, so the next call (this run's next page, or the next
			// sync) retries instead of being stuck on a false "nothing
			// reserved" for up to an hour.
			throw new ReservationUnavailableException( 'Stock reservations query failed; see the log for the underlying error.' );
		}
		set_transient( self::TRANSIENT, $map, HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * A long-running stock sync caches the reservation map for up to an hour
	 * (see map()), but new orders and invoice stock deductions keep happening
	 * during that run - without invalidating on those events, a later batch
	 * would restore stock that just got reserved, or double-subtract a
	 * reservation an invoice already discharged. Both events are cheap and
	 * infrequent enough that resetting on every one, even outside an active
	 * sync, isn't worth gating behind the stock-reservation setting.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'reset' ) );
		add_action( 'oblio_fgwoo_document_issued', array( $this, 'on_document_issued' ), 10, 3 );
	}

	/**
	 * @param WC_Order            $order   Unused - only the invoice/use_stock combination matters.
	 * @param DocumentResult      $result  Issued document result.
	 * @param array<string,mixed> $options Document build options passed to DocumentService::issue().
	 */
	public function on_document_issued( WC_Order $order, DocumentResult $result, array $options ): void {
		unset( $order );
		if ( OrderMeta::TYPE_INVOICE === $result->doc_type && ! empty( $options['use_stock'] ) ) {
			$this->reset();
		}
	}

	public function reset(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * One aggregated query - no order ID list is ever materialized in PHP.
	 * Reads order status/date directly off the orders table (HPOS wc_orders,
	 * or legacy wp_posts, via Compat\OrderStore) instead of going through
	 * WC_Order_Query.
	 *
	 * The oblio_fgwoo_stock_reservation_max_orders filter no longer applies -
	 * there is no "N orders" concept left to cap once aggregation happens in
	 * one grouped query rather than an order-by-order PHP loop.
	 *
	 * @return array<int,int>|null Product/variation ID => reserved quantity, or
	 *                              null on a query failure (distinct from a
	 *                              legitimate empty result).
	 */
	private function build(): ?array {
		global $wpdb;

		$configured = (int) get_option( 'oblio_fgwoo_stock_reserve_days', self::LOOKBACK_DAYS );
		$lookback   = (int) apply_filters( 'oblio_fgwoo_stock_reservation_lookback_days', $configured > 0 ? $configured : self::LOOKBACK_DAYS );
		$statuses   = array_values(
			array_filter(
				array_map(
					'strval',
					(array) apply_filters( 'oblio_fgwoo_stock_reservation_statuses', array( 'on-hold', 'processing', 'pending' ) )
				)
			)
		);
		if ( empty( $statuses ) ) {
			return array();
		}

		$is_hpos      = $this->orders->is_hpos();
		$orders_table = $this->orders->orders_table();
		$meta_table   = $this->orders->meta_table();
		$meta_id_col  = $this->orders->meta_order_id_column();
		$order_id_col = $is_hpos ? 'id' : 'ID';
		$status_col   = $is_hpos ? 'status' : 'post_status';
		$date_col     = $is_hpos ? 'date_created_gmt' : 'post_date_gmt';
		$type_col     = $is_hpos ? 'type' : 'post_type';

		// Both HPOS (wc_orders.status) and legacy (post_status) store the status
		// with the wc- prefix - get_status() strips it at the object level, but
		// the stored column value keeps it on both backends. Only add the prefix
		// if a customization via the filter hasn't already supplied it.
		$status_values = array_map(
			static fn ( string $status ): string => str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status,
			$statuses
		);
		$placeholders  = implode( ',', array_fill( 0, count( $status_values ), '%s' ) );
		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - max( 1, $lookback ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE WHEN vid.meta_value IS NOT NULL AND CAST( vid.meta_value AS UNSIGNED ) > 0 THEN vid.meta_value ELSE pid.meta_value END AS pid,
					SUM( qty.meta_value ) AS qty
				FROM {$orders_table} o
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.{$order_id_col} AND oi.order_item_type = 'line_item'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta vid ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
				WHERE o.{$type_col} = 'shop_order'
					AND o.{$status_col} IN ($placeholders)
					AND o.{$date_col} > %s
					AND NOT EXISTS (
						SELECT 1 FROM {$meta_table} use_stock
						WHERE use_stock.{$meta_id_col} = o.{$order_id_col}
							AND use_stock.meta_key = 'oblio_fgwoo_invoice_use_stock'
							AND use_stock.meta_value = '1'
					)
				GROUP BY pid",
				array_merge( $status_values, array( $cutoff ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// get_results() with ARRAY_A returns an empty array, not null, on a
		// query failure - last_error is the only reliable failure signal here.
		if ( '' !== $wpdb->last_error ) {
			$this->logger->error( 'Stock reservations: query failed - ' . $wpdb->last_error );
			return null;
		}

		$map = array();
		foreach ( (array) $rows as $row ) {
			$pid = (int) ( $row['pid'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$map[ $pid ] = ( $map[ $pid ] ?? 0 ) + (int) ( $row['qty'] ?? 0 );
		}
		return $map;
	}
}
