<?php
/**
 * Maps order shipping and fees to Oblio service lines.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document\Mapper;

use FGSyncOblio\Document\BuildContext;
use WC_Abstract_Order;
use WC_Order;
use WC_Order_Item_Shipping;
final class ShippingFeeMapper {

	public function map( WC_Order $order, BuildContext $ctx ): array {
		$products = array();
		$total    = 0.0;

		foreach ( $this->shipping_lines( $order, $ctx ) as $line ) {
			$products[] = $line;
			$total     += $line['price'];
		}

		foreach ( $order->get_fees() as $fee ) {
			$fee_total = (float) $fee->get_total() + (float) $fee->get_total_tax();
			if ( 0.0 === $fee_total ) {
				continue;
			}
			$products[] = $this->service_line(
				$fee->get_name(),
				$fee_total,
				(float) $fee->get_total(),
				(float) $fee->get_total_tax(),
				$ctx
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
	 * @return array<int,array<string,mixed>>
	 */
	public function shipping_lines( WC_Abstract_Order $order, BuildContext $ctx, int $quantity = 1 ): array {
		$read    = static fn ( $amount ): float => $quantity < 0 ? abs( (float) $amount ) : (float) $amount;
		$amounts = array();
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			if ( $shipping instanceof WC_Order_Item_Shipping ) {
				$amounts[] = array( $shipping->get_name(), $read( $shipping->get_total() ), $read( $shipping->get_total_tax() ) );
			}
		}
		if ( empty( $amounts ) ) {
			$amounts[] = array( '', $read( $order->get_shipping_total() ), $read( $order->get_shipping_tax() ) );
		}
		$amounts = array_filter( $amounts, static fn ( array $amount ): bool => $amount[1] > 0 || $amount[2] > 0 );

		$lines = array();
		foreach ( $amounts as list( $method, $net, $tax ) ) {
			$name    = count( $amounts ) > 1 && '' !== $method ? __( 'Transport', 'fgsync-oblio' ) . ' - ' . $method : __( 'Transport', 'fgsync-oblio' );
			$lines[] = $this->service_line( $name, $net + $tax, $net, $tax, $ctx, $quantity );
		}
		return $lines;
	}

	/**
	 * Builds a service line (shipping, fee) priced VAT-included.
	 *
	 * @param string       $name     Line name.
	 * @param float        $value    Gross unit value.
	 * @param float        $net      Net amount, used for the VAT rate.
	 * @param float        $tax      Tax amount, used for the VAT rate.
	 * @param BuildContext $ctx      Document context.
	 * @param int          $quantity -1 for storno lines.
	 */
	public function service_line( string $name, float $value, float $net, float $tax, BuildContext $ctx, int $quantity = 1 ): array {
		$vat_name    = '';
		$vat_percent = 0;
		if ( 0.0 !== $net && $tax / $net > 0 ) {
			$vat_percent = (int) round( $tax / $net * 100 );
		} else {
			$vat_name = 'SDD';
		}

		return array(
			'name'                     => $name,
			'code'                     => '',
			'description'              => '',
			'price'                    => $value,
			'measuringUnit'            => $ctx->measuring_unit,
			'measuringUnitTranslation' => $ctx->measuring_unit_translation,
			'currency'                 => $ctx->currency,
			'vatName'                  => $ctx->calc_taxes ? $vat_name : '',
			'vatPercentage'            => $ctx->calc_taxes ? $vat_percent : null,
			'vatIncluded'              => true,
			'quantity'                 => $quantity,
			'productType'              => 'Serviciu',
		);
	}
}
