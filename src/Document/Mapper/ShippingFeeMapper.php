<?php
/**
 * Maps order shipping and fees to Oblio service lines.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document\Mapper;

use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\PriorRefunds;
use WC_Abstract_Order;
use WC_Order;
use WC_Order_Item_Shipping;
final class ShippingFeeMapper {

	/**
	 * @param WC_Order          $order   Order.
	 * @param BuildContext      $ctx     Document context.
	 * @param PriorRefunds|null $refunds Refunds to take off shipping and fees.
	 * @return array{products:array<int,array<string,mixed>>,total:float}
	 */
	public function map( WC_Order $order, BuildContext $ctx, ?PriorRefunds $refunds = null ): array {
		$refunds  = $refunds ?? PriorRefunds::none();
		$products = array();
		$total    = 0.0;

		foreach ( $this->shipping_lines( $order, $ctx, 1, $refunds ) as $line ) {
			$products[] = $line;
			$total     += $line['price'];
		}

		foreach ( $order->get_fees() as $fee ) {
			$refunded  = $refunds->for_item( (int) $fee->get_id() );
			$fee_net   = (float) $fee->get_total() - ( (float) $fee->get_total() < 0 ? -$refunded['total'] : $refunded['total'] );
			$fee_tax   = (float) $fee->get_total_tax() - ( (float) $fee->get_total_tax() < 0 ? -$refunded['total_tax'] : $refunded['total_tax'] );
			$fee_total = $fee_net + $fee_tax;
			if ( abs( $fee_total ) < 0.005 ) {
				continue;
			}
			$products[] = $this->service_line(
				$fee->get_name(),
				$fee_total,
				$fee_net,
				$fee_tax,
				$ctx,
				1,
				LineVat::item_taxes( $fee )
			);
			$total     += $fee_total;
		}

		return array(
			'products' => $products,
			'total'    => $total,
		);
	}

	/**
	 * One line per shipping method, so each keeps its own VAT rate. Refunds pass
	 * $quantity = -1 and are read as absolute amounts.
	 *
	 * @param WC_Abstract_Order $order    Order or refund.
	 * @param BuildContext      $ctx      Document context.
	 * @param int               $quantity -1 for storno lines.
	 * @param PriorRefunds|null $refunds  Refunds to take off an order's shipping.
	 * @return array<int,array<string,mixed>>
	 */
	public function shipping_lines( WC_Abstract_Order $order, BuildContext $ctx, int $quantity = 1, ?PriorRefunds $refunds = null ): array {
		$refunds = $refunds ?? PriorRefunds::none();
		$read    = static fn ( $amount ): float => $quantity < 0 ? abs( (float) $amount ) : (float) $amount;
		$amounts = array();
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			if ( $shipping instanceof WC_Order_Item_Shipping ) {
				$refunded  = $refunds->for_item( (int) $shipping->get_id() );
				$amounts[] = array( $shipping->get_name(), $read( $shipping->get_total() ) - $refunded['total'], $read( $shipping->get_total_tax() ) - $refunded['total_tax'], LineVat::item_taxes( $shipping ) );
			}
		}
		if ( empty( $amounts ) ) {
			$amounts[] = array( '', $read( $order->get_shipping_total() ), $read( $order->get_shipping_tax() ), array() );
		}
		$amounts = array_filter( $amounts, static fn ( array $amount ): bool => $amount[1] > 0 || $amount[2] > 0 );

		$lines = array();
		foreach ( $amounts as list( $method, $net, $tax, $taxes ) ) {
			$name    = count( $amounts ) > 1 && '' !== $method ? __( 'Transport', 'fgsync-oblio' ) . ' - ' . $method : __( 'Transport', 'fgsync-oblio' );
			$lines[] = $this->service_line( $name, $net + $tax, $net, $tax, $ctx, $quantity, $taxes );
		}
		return $lines;
	}

	/**
	 * Builds a service line (shipping, fee) priced VAT-included.
	 *
	 * @param string                  $name     Line name.
	 * @param float                   $value    Gross unit value.
	 * @param float                   $net      Net amount, used for the VAT rate.
	 * @param float                   $tax      Tax amount, used for the VAT rate.
	 * @param BuildContext            $ctx      Document context.
	 * @param int                     $quantity -1 for storno lines.
	 * @param array<int|string,mixed> $taxes WooCommerce tax rate ID => amount charged on the item.
	 */
	public function service_line( string $name, float $value, float $net, float $tax, BuildContext $ctx, int $quantity = 1, array $taxes = array() ): array {
		return array(
			'name'                     => $name,
			'code'                     => '',
			'description'              => '',
			'price'                    => $value,
			'measuringUnit'            => $ctx->measuring_unit,
			'measuringUnitTranslation' => $ctx->measuring_unit_translation,
			'currency'                 => $ctx->currency,
		)
			+ LineVat::fields( $net, $tax, $taxes, $ctx )
			+ array(
				'quantity'    => $quantity,
				'productType' => 'Serviciu',
			);
	}
}
