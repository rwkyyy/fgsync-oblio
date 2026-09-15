<?php
/**
 * Issues storno (refund) documents for WooCommerce refunds.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Refund;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Compat\OrderStore;
use OblioWoo\Document\BuildContext;
use OblioWoo\Document\DocumentException;
use OblioWoo\Document\DocumentResult;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
use WC_Order;
use WC_Order_Refund;
final class RefundService implements RefundIssuer {

	private Settings $settings;

	private ClientFactory $factory;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( Settings $settings, ClientFactory $factory, OrderStore $orders, Logger $logger ) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->orders   = $orders;
		$this->logger   = $logger;
	}

	public function issue_for_refund( int $order_id, int $refund_id ): ?DocumentResult {
		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			return null;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof WC_Order_Refund ) {
			return null;
		}

		$guard_key = 'oblio_fgwoo_storno_refund_' . $refund_id;
		if ( '' !== (string) $order->get_meta( $guard_key ) ) {
			return null;
		}

		$invoice = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		if ( null === $invoice ) {
			throw new DocumentException( esc_html__( 'Nu există factură pentru care să se emită storno.', 'oblio-fgwoo' ) );
		}

		if ( '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' ) ) ) {
			return null;
		}

		$is_full = $this->is_full_refund( $order, $refund );
		$payload = $this->build_storno( $order, $invoice, $refund, $is_full, $refund_id );

		$data   = $this->factory->create()->create_document( OrderMeta::TYPE_INVOICE, $payload );
		$result = DocumentResult::from_api( OrderMeta::TYPE_STORNO, $data );

		if ( '' === $result->series_name && '' === $result->number ) {
			throw new DocumentException( esc_html__( 'Răspuns invalid de la Oblio la emiterea storno.', 'oblio-fgwoo' ) );
		}

		$order->update_meta_data(
			$guard_key,
			(string) wp_json_encode(
				array(
					'series' => $result->series_name,
					'number' => $result->number,
					'link'   => $result->link,
				)
			)
		);
		if ( $is_full ) {
			$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' ), current_time( 'mysql' ) );
		}
		$this->record_storno( $order, $result, $is_full );
		OrderMeta::save( $order, $result );

		do_action( 'oblio_fgwoo_storno_issued', $order, $result, $refund_id, $is_full );

		$this->logger->info( sprintf( 'Order #%d refund #%d: storno %s %s (%s)', $order_id, $refund_id, $result->series_name, $result->number, $is_full ? 'full' : 'partial' ) );

		return $result;
	}

	public function issue_full_storno( WC_Order $order ): DocumentResult {
		$invoice = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		if ( null === $invoice ) {
			throw new DocumentException( esc_html__( 'Nu există factură pentru care să se emită storno.', 'oblio-fgwoo' ) );
		}

		$full_key = OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' );

		if ( '' !== (string) $order->get_meta( $full_key ) ) {
			$existing = OrderMeta::get( $order, OrderMeta::TYPE_STORNO );
			if ( null !== $existing ) {
				return new DocumentResult( OrderMeta::TYPE_STORNO, $existing['series'], $existing['number'], $existing['link'] );
			}
		}

		if ( null !== OrderMeta::get( $order, OrderMeta::TYPE_STORNO ) ) {
			throw new DocumentException( esc_html__( 'Există deja un storno pentru această factură. Storneaz restul printr-o rambursare WooCommerce.', 'oblio-fgwoo' ) );
		}

		$payload = array(
			'cif'               => (string) $this->settings->get( 'cif' ),
			'seriesName'        => (string) $this->settings->get( 'series_invoice' ),
			'referenceDocument' => array(
				'type'       => 'Factura',
				'refund'     => 1,
				'seriesName' => $invoice['series'],
				'number'     => $invoice['number'],
			),
			'idempotencyKey'    => sprintf( 'woocommerce-%s-storno', str_pad( (string) $order->get_id(), 15, '0', STR_PAD_LEFT ) ),
		);

		$payload = (array) apply_filters( 'oblio_fgwoo_storno_data', $payload, $order, null, true );

		$data   = $this->factory->create()->create_document( OrderMeta::TYPE_INVOICE, $payload );
		$result = DocumentResult::from_api( OrderMeta::TYPE_STORNO, $data );

		if ( '' === $result->series_name && '' === $result->number ) {
			throw new DocumentException( esc_html__( 'Răspuns invalid de la Oblio la emiterea storno.', 'oblio-fgwoo' ) );
		}

		$order->update_meta_data( $full_key, current_time( 'mysql' ) );
		$this->record_storno( $order, $result, true );
		OrderMeta::save( $order, $result );
		do_action( 'oblio_fgwoo_storno_issued', $order, $result, 0, true );
		$this->logger->info( sprintf( 'Order #%d: manual full storno %s %s', $order->get_id(), $result->series_name, $result->number ) );

		return $result;
	}

	private function record_storno( WC_Order $order, DocumentResult $result, bool $is_full ): void {
		$list   = $order->get_meta( OrderMeta::STORNO_LIST );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'series' => $result->series_name,
			'number' => $result->number,
			'link'   => $result->link,
			'full'   => $is_full,
			'date'   => current_time( 'mysql' ),
		);
		$order->update_meta_data( OrderMeta::STORNO_LIST, $list );
	}

	private function is_full_refund( WC_Order $order, WC_Order_Refund $refund ): bool {
		$refund_amount = abs( (float) $refund->get_amount() );
		$order_total   = (float) $order->get_total();
		return abs( $refund_amount - $order_total ) < 0.01;
	}

	private function build_storno( WC_Order $order, array $invoice, WC_Order_Refund $refund, bool $is_full, int $refund_id ): array {
		$payload = array(
			'cif'               => (string) $this->settings->get( 'cif' ),
			'seriesName'        => (string) $this->settings->get( 'series_invoice' ),
			'referenceDocument' => array(
				'type'       => 'Factura',

				'refund'     => $is_full ? 1 : 0,
				'seriesName' => $invoice['series'],
				'number'     => $invoice['number'],
			),
			'idempotencyKey'    => sprintf( 'woocommerce-%s-storno-%d', str_pad( (string) $order->get_id(), 15, '0', STR_PAD_LEFT ), $refund_id ),
		);

		if ( ! $is_full ) {

			$products = $this->refund_products( $order, $refund );
			if ( empty( $products ) ) {

				$products = $this->amount_only_line( $order, $refund );
			}
			$payload['products'] = $this->reconcile_products( $products, $order, $refund );
		}

		return (array) apply_filters( 'oblio_fgwoo_storno_data', $payload, $order, $refund, $is_full );
	}

	private function refund_products( WC_Order $order, WC_Order_Refund $refund ): array {
		$currency = substr( (string) $order->get_currency(), 0, 3 );
		$currency = 'lei' === strtolower( $currency ) ? 'RON' : $currency;
		$ctx      = BuildContext::from_settings( $this->settings, $currency );

		$products = array();

		foreach ( $refund->get_items() as $item ) {
			$qty   = abs( (float) $item->get_quantity() );
			$total = abs( (float) $item->get_total() );
			$tax   = abs( (float) $item->get_total_tax() );
			$value = $total + $tax;
			if ( $value <= 0 ) {
				continue;
			}
			$unit_qty = $qty > 0 ? $qty : 1;
			$product  = $item->get_product();

			$products[] = array(
				'name'          => $item->get_name(),
				'code'          => $product ? (string) $product->get_sku() : '',
				'price'         => round( $value / $unit_qty, $ctx->precision + 2 ),
				'measuringUnit' => $ctx->measuring_unit,
				'currency'      => $currency,
				'vatName'       => $ctx->calc_taxes ? ( $tax > 0 ? '' : 'SDD' ) : '',
				'vatPercentage' => $ctx->calc_taxes ? ( $tax > 0 && $total > 0 ? (int) round( $tax / $total * 100 ) : 0 ) : null,
				'vatIncluded'   => true,
				'quantity'      => -$unit_qty,
				'productType'   => $ctx->product_type,
			);
		}

		foreach ( $refund->get_items( 'fee' ) as $fee ) {
			$total = abs( (float) $fee->get_total() );
			$tax   = abs( (float) $fee->get_total_tax() );
			$value = $total + $tax;
			if ( $value <= 0 ) {
				continue;
			}
			$products[] = array(
				'name'          => $fee->get_name(),
				'code'          => '',
				'price'         => $value,
				'measuringUnit' => $ctx->measuring_unit,
				'currency'      => $currency,
				'vatName'       => $ctx->calc_taxes ? ( $tax > 0 ? '' : 'SDD' ) : '',
				'vatPercentage' => $ctx->calc_taxes ? ( $tax > 0 && $total > 0 ? (int) round( $tax / $total * 100 ) : 0 ) : null,
				'vatIncluded'   => true,
				'quantity'      => -1,
				'productType'   => 'Serviciu',
			);
		}

		$shipping = abs( (float) $refund->get_shipping_total() );
		$ship_tax = abs( (float) $refund->get_shipping_tax() );
		if ( $shipping + $ship_tax > 0 ) {
			$products[] = array(
				'name'          => __( 'Transport', 'oblio-fgwoo' ),
				'code'          => '',
				'price'         => $shipping + $ship_tax,
				'measuringUnit' => $ctx->measuring_unit,
				'currency'      => $currency,
				'vatName'       => $ctx->calc_taxes ? ( $ship_tax > 0 ? '' : 'SDD' ) : '',
				'vatPercentage' => $ctx->calc_taxes ? ( $ship_tax > 0 && $shipping > 0 ? (int) round( $ship_tax / $shipping * 100 ) : 0 ) : null,
				'vatIncluded'   => true,
				'quantity'      => -1,
				'productType'   => 'Serviciu',
			);
		}

		return $products;
	}

	private function amount_only_line( WC_Order $order, WC_Order_Refund $refund ): array {
		$amount = abs( (float) $refund->get_amount() );
		if ( $amount <= 0 ) {
			return array();
		}

		$currency = substr( (string) $order->get_currency(), 0, 3 );
		$currency = 'lei' === strtolower( $currency ) ? 'RON' : $currency;
		$ctx      = BuildContext::from_settings( $this->settings, $currency );
		$reason   = trim( (string) $refund->get_reason() );

		return array(
			array(
				'name'          => '' !== $reason ? $reason : __( 'Rambursare', 'oblio-fgwoo' ),
				'code'          => '',
				'price'         => round( $amount, $ctx->precision + 2 ),
				'measuringUnit' => $ctx->measuring_unit,
				'currency'      => $currency,
				'vatName'       => '',
				'vatPercentage' => null,
				'vatIncluded'   => true,
				'quantity'      => -1,
				'productType'   => 'Serviciu',
			),
		);
	}

	private function reconcile_products( array $products, WC_Order $order, WC_Order_Refund $refund ): array {
		$target = round( abs( (float) $refund->get_amount() ), 2 );
		if ( $target <= 0 || empty( $products ) ) {
			return $products;
		}

		$magnitude = 0.0;
		foreach ( $products as $line ) {

			$magnitude += (float) $line['price'] * abs( (float) $line['quantity'] );
		}

		$adjustment = self::storno_adjustment( $target, $magnitude );
		if ( null === $adjustment ) {
			return $products;
		}

		$currency = substr( (string) $order->get_currency(), 0, 3 );
		$currency = 'lei' === strtolower( $currency ) ? 'RON' : $currency;
		$ctx      = BuildContext::from_settings( $this->settings, $currency );

		$products[] = array(
			'name'          => __( 'Ajustare storno', 'oblio-fgwoo' ),
			'code'          => '',
			'price'         => $adjustment['price'],
			'measuringUnit' => $ctx->measuring_unit,
			'currency'      => $currency,
			'vatName'       => '',
			'vatPercentage' => null,
			'vatIncluded'   => true,
			'quantity'      => $adjustment['quantity'],
			'productType'   => 'Serviciu',
		);

		return $products;
	}

	public static function storno_adjustment( float $target, float $magnitude ): ?array {
		$difference = round( $target - round( $magnitude, 2 ), 2 );
		if ( abs( $difference ) < 0.01 ) {
			return null;
		}
		return array(
			'price'    => abs( $difference ),

			'quantity' => $difference > 0 ? -1 : 1,
		);
	}
}
