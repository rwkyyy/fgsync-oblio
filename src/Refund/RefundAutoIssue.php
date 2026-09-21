<?php
/**
 * Enqueues a storno when a WooCommerce refund is created.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Refund;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Settings;
final class RefundAutoIssue {

	private Settings $settings;

	private Scheduler $scheduler;

	private OrderStore $orders;

	public function __construct( Settings $settings, Scheduler $scheduler, OrderStore $orders ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->orders    = $orders;
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

		$order = $this->orders->get_order( (int) $order_id );
		if ( null === $order || ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			return;
		}

		$this->scheduler->enqueue_refund( (int) $order_id, (int) $refund_id );
	}
}
