<?php
/**
 * Invoice reconciliation watchdog.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use WC_Order_Refund;
final class Reconciler {

	private const LOOKBACK_DAYS    = 7;
	private const BATCH            = 100;
	private const STORNO_MAX_PAGES = 20;

	private Settings $settings;

	private Scheduler $scheduler;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( Settings $settings, Scheduler $scheduler, OrderStore $orders, Logger $logger ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->orders    = $orders;
		$this->logger    = $logger;
	}

	public function register(): void {
		add_action( Scheduler::HOOK_RECONCILE, array( $this, 'run' ) );
	}

	public function run(): void {
		$this->reconcile_invoices();
		$this->reconcile_stornos();
	}

	private function reconcile_invoices(): void {
		if ( ! $this->settings->is_enabled( 'invoice_autogen' ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			return;
		}

		$statuses = $this->settings->invoice_statuses();

		$lookback = (int) apply_filters( 'oblio_fgwoo_reconcile_lookback_days', self::LOOKBACK_DAYS );
		$lookback = $lookback > 0 ? $lookback : self::LOOKBACK_DAYS;

		$order_ids = wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => '>' . ( time() - $lookback * DAY_IN_SECONDS ),
				'limit'        => self::BATCH,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'ids',

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed doc meta, recurring watchdog.
				'meta_query'   => array(
					'relation' => 'AND',
					array(
						'key'     => OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ),
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'oblio_invoice_link',
						'compare' => 'NOT EXISTS',
					),
					// A transient failure that merely exhausted its retries (e.g.
					// an Oblio outage) is still eligible - only a permanent,
					// business-rule failure (see GenerateDocument::fail()) is
					// excluded, since retrying that would just fail again.
					array(
						'key'     => OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ),
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$use_stock = $this->settings->is_enabled( 'invoice_autogen_use_stock' );
		$requeued  = 0;

		foreach ( (array) $order_ids as $order_id ) {
			$order = $this->orders->get_order( (int) $order_id );
			if ( null === $order ) {
				continue;
			}
			if ( OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
				continue;
			}

			if ( $this->scheduler->enqueue_document( (int) $order_id, OrderMeta::TYPE_INVOICE, array( 'use_stock' => $use_stock ) ) ) {
				++$requeued;
			}
		}

		if ( $requeued > 0 ) {
			$this->logger->warning( sprintf( 'Reconcile: %d missed invoice(s) re-queued', $requeued ) );
		}
	}

	/**
	 * GenerateRefund's own invoice-wait budget already covers the common
	 * case (invoice issued shortly after the refund), but nothing revisits a
	 * refund once that budget - or the API-retry budget - is exhausted. This
	 * mirrors reconcile_invoices(): re-enqueue any refund past the lookback
	 * window whose order now has an invoice but no matching storno, unless
	 * that storno's last failure was permanent.
	 */
	private function reconcile_stornos(): void {
		if ( ! $this->settings->is_enabled( 'storno_autogen' ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			return;
		}

		$lookback = (int) apply_filters( 'oblio_fgwoo_reconcile_lookback_days', self::LOOKBACK_DAYS );
		$lookback = $lookback > 0 ? $lookback : self::LOOKBACK_DAYS;

		// The oldest-first window alone isn't enough to make every refund
		// eventually get examined - a store with more than BATCH refunds in the
		// lookback window would otherwise see the exact same oldest page every
		// run, forever, since nothing here excludes already-handled refunds from
		// the query. Page through the whole window instead, bounded by
		// STORNO_MAX_PAGES so a pathological backlog can't turn one reconcile
		// run into an unbounded scan.
		$max_pages = (int) apply_filters( 'oblio_fgwoo_reconcile_storno_max_pages', self::STORNO_MAX_PAGES );
		$max_pages = $max_pages > 0 ? $max_pages : self::STORNO_MAX_PAGES;

		$requeued = 0;
		$page     = 1;

		do {
			$refund_ids = (array) wc_get_orders(
				array(
					'type'         => 'shop_order_refund',
					'date_created' => '>' . ( time() - $lookback * DAY_IN_SECONDS ),
					'limit'        => self::BATCH,
					'paged'        => $page,
					'orderby'      => 'date',
					'order'        => 'ASC',
					'return'       => 'ids',
				)
			);

			foreach ( $refund_ids as $refund_id ) {
				$refund_id = (int) $refund_id;
				$refund    = wc_get_order( $refund_id );
				if ( ! $refund instanceof WC_Order_Refund ) {
					continue;
				}

				$order = $this->orders->get_order( $refund->get_parent_id() );
				if ( null === $order || ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
					continue;
				}
				// A full storno (issued via the manual/bulk "full storno" action,
				// which has no refund_id of its own) already reverses every line
				// on the order - any individual WC refund record on that same
				// order is moot and would otherwise get requeued on every run
				// forever, since nothing else ever marks it as handled.
				if ( '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' ) ) ) {
					continue;
				}
				if ( '' !== (string) $order->get_meta( 'oblio_fgwoo_storno_refund_' . $refund_id ) ) {
					continue;
				}
				if ( '' !== (string) $order->get_meta( 'oblio_fgwoo_storno_failed_permanent_' . $refund_id ) ) {
					continue;
				}

				if ( $this->scheduler->enqueue_refund( $order->get_id(), $refund_id ) ) {
					++$requeued;
				}
			}

			$fetched = count( $refund_ids );
			++$page;
		} while ( self::BATCH === $fetched && $page <= $max_pages );

		if ( $requeued > 0 ) {
			$this->logger->warning( sprintf( 'Reconcile: %d missed storno(s) re-queued', $requeued ) );
		}
	}
}
