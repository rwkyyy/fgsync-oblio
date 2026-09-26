<?php
/**
 * Enqueues a storno when a WooCommerce refund is created.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Refund;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
final class RefundAutoIssue {

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
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 20, 2 );
	}

	public function on_refunded( $order_id, $refund_id ): void {
		if ( ! $this->settings->is_enabled( 'storno_autogen' ) ) {
			return;
		}
		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			return;
		}

		if ( null === $this->orders->get_order( (int) $order_id ) ) {
			return;
		}

		// The invoice may still be queued (not yet issued) at this point - the
		// job itself retries with backoff until it appears, instead of this
		// giving up here and never revisiting the refund. enqueue_*() also
		// returns false when the storno is already queued (dedup) - checked
		// only now, on the failure path, so that ordinary case isn't logged
		// as an error.
		if ( ! $this->scheduler->enqueue_refund( (int) $order_id, (int) $refund_id )
			&& ! $this->scheduler->has_pending_refund( (int) $order_id, (int) $refund_id ) ) {
			$this->logger->error( sprintf( 'Refund auto-issue: could not schedule storno for order #%d refund #%d', (int) $order_id, (int) $refund_id ) );
		}
	}
}
