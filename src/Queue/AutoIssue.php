<?php
/**
 * Order event hooks that enqueue document generation.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Settings;
use WC_Order;
final class AutoIssue {

	private Settings $settings;

	private Scheduler $scheduler;

	private OrderStore $orders;

	public function __construct( Settings $settings, Scheduler $scheduler, OrderStore $orders ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->orders    = $orders;
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_thankyou', array( $this, 'on_thankyou' ), 20, 1 );
	}

	public function on_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		if ( ! $this->settings->is_enabled( 'invoice_autogen' ) && ! $this->settings->is_enabled( 'proforma_autogen' ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = $this->orders->get_order( (int) $order_id );
		}
		if ( null === $order ) {
			return;
		}

		$this->maybe_issue_invoice( $order, (string) $from, (string) $to );
		$this->maybe_issue_proforma( $order, (string) $from, (string) $to );
	}

	private function maybe_issue_invoice( WC_Order $order, string $from, string $to ): void {
		if ( ! $this->settings->is_enabled( 'invoice_autogen' ) ) {
			return;
		}

		if ( 'event' !== (string) $this->settings->get( 'invoice_generation', 'event' ) ) {
			return;
		}
		if ( ! $this->entered( $from, $to, $this->settings->invoice_statuses() ) ) {
			return;
		}
		if ( OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			return;
		}
		$this->scheduler->enqueue_document(
			$order->get_id(),
			OrderMeta::TYPE_INVOICE,
			array( 'use_stock' => $this->settings->is_enabled( 'invoice_autogen_use_stock' ) )
		);
	}

	private function maybe_issue_proforma( WC_Order $order, string $from, string $to ): void {
		if ( ! $this->settings->is_enabled( 'proforma_autogen' ) || $this->settings->is_enabled( 'proforma_on_received' ) ) {
			return;
		}
		$targets = array_values( array_filter( array_map( 'strval', (array) $this->settings->get( 'proforma_autogen_statuses' ) ) ) );
		if ( ! $this->entered( $from, $to, $targets ) ) {
			return;
		}
		if ( OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) || OrderMeta::has( $order, OrderMeta::TYPE_PROFORMA ) ) {
			return;
		}
		$this->scheduler->enqueue_document( $order->get_id(), OrderMeta::TYPE_PROFORMA );
	}

	private function entered( string $from, string $to, array $targets ): bool {
		return in_array( $to, $targets, true ) && ! in_array( $from, $targets, true );
	}

	public function on_thankyou( $order_id ): void {
		if ( ! $this->settings->is_enabled( 'proforma_autogen' ) || ! $this->settings->is_enabled( 'proforma_on_received' ) || ! $this->is_configured() ) {
			return;
		}

		$order = $this->orders->get_order( (int) $order_id );
		if ( null === $order ) {
			return;
		}
		if ( OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) || OrderMeta::has( $order, OrderMeta::TYPE_PROFORMA ) ) {
			return;
		}

		$this->scheduler->enqueue_document( (int) $order_id, OrderMeta::TYPE_PROFORMA );
	}

	private function is_configured(): bool {
		return $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
	}
}
