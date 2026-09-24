<?php
/**
 * "Oblio" column on the orders list (HPOS + legacy).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use WC_Order;
final class OrderListColumn {

	private OrderStore $orders;

	public function __construct( OrderStore $orders ) {
		$this->orders = $orders;
	}

	public function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render' ), 10, 2 );
	}

	public function add_column( array $columns ): array {
		$columns['oblio_fgwoo'] = __( 'Oblio', 'fgsync-oblio' );
		return $columns;
	}

	public function render( $column, $order_or_id = null ): void {
		if ( 'oblio_fgwoo' !== $column ) {
			return;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : $this->orders->get_order( (int) $order_or_id );
		if ( null === $order ) {
			return;
		}

		$has_output = false;

		$invoice = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		if ( null !== $invoice ) {
			printf(
				'<a href="%s" target="_blank">%s %s</a>',
				esc_url( $invoice['link'] ),
				esc_html( $invoice['series'] ),
				esc_html( $invoice['number'] )
			);
			$has_output = true;
		} else {
			$proforma = OrderMeta::get( $order, OrderMeta::TYPE_PROFORMA );
			if ( null !== $proforma ) {
				printf(
					'<a href="%1$s" target="_blank">%2$s %3$s %4$s</a>',
					esc_url( $proforma['link'] ),
					esc_html__( 'Proformă', 'fgsync-oblio' ),
					esc_html( $proforma['series'] ),
					esc_html( $proforma['number'] )
				);
				$has_output = true;
			} else {
				$reason = (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) );
				if ( '' !== $reason ) {
					printf(
						'<span class="oblio-fgwoo-list-failed" title="%1$s">!<span class="screen-reader-text">%2$s</span></span>',
						esc_attr( $reason ),
						esc_html__( 'Emiterea facturii a eșuat', 'fgsync-oblio' )
					);
					$has_output = true;
				}
			}
		}

		if ( null !== OrderMeta::get( $order, OrderMeta::TYPE_STORNO ) ) {
			if ( $has_output ) {
				echo '<br>';
			}
			echo '<span class="oblio-fgwoo-list-storno">' . esc_html__( 'storno', 'fgsync-oblio' ) . '</span>';
		}
	}
}
