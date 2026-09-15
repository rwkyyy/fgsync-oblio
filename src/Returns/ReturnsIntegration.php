<?php
/**
 * Bridge to the WooCommerce 11 Returns feature.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Returns;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use OblioWoo\Compat\OrderStore;
use OblioWoo\Refund\RefundIssuer;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
use Throwable;
use WC_Order;
use WC_Order_Refund;
final class ReturnsIntegration {

	private Settings $settings;

	private RefundIssuer $refunds;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( Settings $settings, RefundIssuer $refunds, OrderStore $orders, Logger $logger ) {
		$this->settings = $settings;
		$this->refunds  = $refunds;
		$this->orders   = $orders;
		$this->logger   = $logger;
	}

	public function register(): void {

		if ( ! (bool) apply_filters( 'oblio_fgwoo_returns_experimental', false ) ) {
			return;
		}
		if ( ! $this->settings->is_enabled( 'returns_storno' ) || ! $this->is_feature_active() ) {
			return;
		}

		foreach ( $this->candidate_hooks() as $hook ) {
			add_action( $hook, array( $this, 'on_return_event' ), 20, 2 );
		}
		$this->logger->debug( 'Returns integration active' );
	}

	public function is_feature_active(): bool {
		if ( ! class_exists( FeaturesUtil::class ) || ! method_exists( FeaturesUtil::class, 'feature_is_enabled' ) ) {
			return false;
		}

		$slugs = (array) apply_filters(
			'oblio_fgwoo_returns_feature_slug',
			array( 'returns', 'order_returns', 'customer_returns', 'returns_and_warranty' )
		);

		foreach ( $slugs as $slug ) {
			if ( FeaturesUtil::feature_is_enabled( (string) $slug ) ) {
				return true;
			}
		}
		return false;
	}

	public function on_return_event( $primary = null, $secondary = null ): void {
		$order = $this->resolve_order( $primary, $secondary );
		if ( null === $order ) {
			$this->logger->warning( 'Returns: could not identify the order from the event' );
			return;
		}

		$refund_id = $this->resolve_refund_id( $primary, $secondary );
		if ( 0 === $refund_id ) {

			$this->logger->debug( sprintf( 'Returns: event for order #%d has no refund yet, deferring', $order->get_id() ) );
			return;
		}

		try {
			$this->refunds->issue_for_refund( $order->get_id(), $refund_id );
		} catch ( Throwable $exception ) {
			$this->logger->error( sprintf( 'Returns: storno for order #%d refund #%d failed: %s', $order->get_id(), $refund_id, $exception->getMessage() ) );
		}
	}

	private function candidate_hooks(): array {
		return (array) apply_filters(
			'oblio_fgwoo_returns_hooks',
			array(
				'woocommerce_order_return_approved',
				'woocommerce_return_request_approved',
				'woocommerce_customer_return_processed',
			)
		);
	}

	private function resolve_order( $primary, $secondary ): ?WC_Order {
		foreach ( array( $primary, $secondary ) as $candidate ) {
			if ( $candidate instanceof WC_Order ) {
				return $candidate;
			}
			if ( is_object( $candidate ) && method_exists( $candidate, 'get_order_id' ) ) {
				$order = $this->orders->get_order( (int) $candidate->get_order_id() );
				if ( null !== $order ) {
					return $order;
				}
			}
			if ( is_numeric( $candidate ) ) {
				$order = $this->orders->get_order( (int) $candidate );
				if ( null !== $order ) {
					return $order;
				}
			}
		}
		return null;
	}

	private function resolve_refund_id( $primary, $secondary ): int {
		foreach ( array( $primary, $secondary ) as $candidate ) {
			if ( $candidate instanceof WC_Order_Refund ) {
				return (int) $candidate->get_id();
			}
			if ( is_object( $candidate ) && method_exists( $candidate, 'get_refund_id' ) ) {
				return (int) $candidate->get_refund_id();
			}
		}
		return 0;
	}
}
