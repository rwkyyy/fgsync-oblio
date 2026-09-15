<?php
/**
 * Maps order shipping and fees to Oblio service lines.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document\Mapper;

use OblioWoo\Document\BuildContext;
use WC_Order;
final class ShippingFeeMapper {

	public function map( WC_Order $order, BuildContext $ctx ): array {
		$products = array();
		$total    = 0.0;

		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			$shipping_tax = (float) $order->get_shipping_tax();
			$value        = $shipping_total + $shipping_tax;
			$products[]   = $this->service_line(
				__( 'Transport', 'oblio-fgwoo' ),
				$value,
				$shipping_total,
				$shipping_tax,
				$ctx
			);
			$total       += $value;
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

	private function service_line( string $name, float $value, float $net, float $tax, BuildContext $ctx ): array {
		$vat_name    = '';
		$vat_percent = 0;
		if ( abs( $tax ) > 0 && 0.0 !== $net ) {
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
			'quantity'                 => 1,
			'productType'              => 'Serviciu',
		);
	}
}
