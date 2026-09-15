<?php
/**
 * Invoice reconciliation watchdog.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Queue;

use OblioWoo\Compat\OrderStore;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
final class Reconciler {

	private const LOOKBACK_DAYS = 7;
	private const BATCH         = 100;

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

			if ( '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) ) {
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
}
