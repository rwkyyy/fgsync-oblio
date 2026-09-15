<?php
/**
 * Maps order line items to Oblio product lines (with VAT + discounts).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document\Mapper;

use OblioWoo\Admin\ProductFields;
use OblioWoo\Document\BuildContext;
use WC_Order;
use WC_Order_Item_Product;
final class LineItemMapper {

	public function map( WC_Order $order, BuildContext $ctx ): array {
		$products = array();
		$total    = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product  = $item->get_product();
			$quantity = (float) $item->get_quantity();
			if ( $quantity <= 0 ) {
				continue;
			}

			$package = $this->package_number( $item );

			$item_total     = (float) $item->get_total();
			$item_total_tax = (float) $item->get_total_tax();

			$vat_name    = '';
			$is_taxable  = $item_total_tax > 0 && 0.0 !== $item_total;
			$vat_percent = 0;
			if ( $is_taxable ) {
				$vat_percent = (int) round( $item_total_tax / $item_total * 100 );
			} else {
				$vat_name = 'SDD';
			}

			$subtotal = number_format(
				round( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax(), $ctx->price_decimals ) / $quantity,
				4,
				'.',
				''
			);
			$price    = number_format(
				round( $item_total + $item_total_tax, $ctx->price_decimals ) / $quantity,
				4,
				'.',
				''
			);

			$regular_price = $this->regular_price( $item, $product, $subtotal, $price );

			$product_price = $ctx->discount_in_product ? $price : $regular_price;
			$total        += round( (float) $price * $quantity, $ctx->precision + 2 );

			$products[] = array(
				'name'                     => $item->get_name(),
				'code'                     => $this->sku( $item, $product ),
				'description'              => $ctx->hide_description ? '&nbsp;' : $this->description( $item ),
				'price'                    => round( (float) $product_price / $package, $ctx->precision + 2 ),
				'measuringUnit'            => $ctx->measuring_unit,
				'measuringUnitTranslation' => $ctx->measuring_unit_translation,
				'currency'                 => $ctx->currency,
				'vatName'                  => $ctx->calc_taxes ? $vat_name : '',
				'vatPercentage'            => $ctx->calc_taxes ? $vat_percent : null,
				'vatIncluded'              => true,
				'quantity'                 => round( $quantity * $package, $ctx->precision ),
				'productType'              => $this->product_type( $item, $ctx->product_type ),
				'management'               => $ctx->management,
				'save'                     => $ctx->save_price,
			);

			if ( ! $ctx->discount_in_product && number_format( (float) $regular_price, 4, '.', '' ) !== $price ) {
				$discount = ( (float) $regular_price * $quantity ) - ( $item_total + $item_total_tax );
				$discount = round( $discount, $ctx->precision, PHP_ROUND_HALF_DOWN );
				if ( $discount > 0 ) {
					$products[] = array(
						'name'         => sprintf( 'Discount "%s"', $item->get_name() ),
						'discount'     => $discount,
						'discountType' => 'valoric',
					);
				} else {

					$last                       = array_key_last( $products );
					$products[ $last ]['price'] = round( (float) $price / $package, $ctx->precision + 2 );
				}
			}
		}

		return array(
			'products' => $products,
			'total'    => $total,
		);
	}

	private function regular_price( WC_Order_Item_Product $item, $product, string $subtotal, string $price ): string {
		if ( $subtotal !== $price || ! $product ) {
			return $subtotal;
		}

		$regular = (string) $product->get_regular_price();

		if ( $item->get_variation_id() > 0 ) {
			$variation = wc_get_product( $item->get_variation_id() );
			if ( $variation && $variation->exists() ) {
				$regular = (string) $variation->get_regular_price();
			}
		}

		if ( '0.00' === number_format( (float) $regular, 2, '.', '' ) ) {
			$regular = (string) $product->get_price();
		}

		return $regular;
	}

	private function package_number( WC_Order_Item_Product $item ): int {
		$package = ProductFields::package_number( $item->get_product_id() );

		if ( $item->get_variation_id() > 0 ) {
			$variation_package = ProductFields::variation_package_number( $item->get_variation_id() );
			if ( $variation_package > 0 ) {
				$package = $variation_package;
			}
		}

		return $package > 0 ? $package : 1;
	}

	private function product_type( WC_Order_Item_Product $item, string $default ): string {
		$custom = ProductFields::product_type( $item->get_product_id() );
		return '' !== $custom ? $custom : $default;
	}

	private function sku( WC_Order_Item_Product $item, $product ): string {
		if ( $item->get_variation_id() > 0 ) {
			$sku = get_post_meta( $item->get_variation_id(), '_sku', true );
			if ( ! empty( $sku ) ) {
				return (string) $sku;
			}
		}
		return $product ? (string) $product->get_sku() : '';
	}

	private function description( WC_Order_Item_Product $item ): string {
		if ( ! method_exists( $item, 'get_all_formatted_meta_data' ) ) {
			return '';
		}

		$hidden = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reusing WooCommerce's own filter for parity.
			'woocommerce_hidden_order_itemmeta',
			array( '_qty', '_tax_class', '_product_id', '_variation_id', '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', 'method_id', 'cost', '_reduced_stock', '_restock_refunded_items' )
		);

		$description = '';
		foreach ( $item->get_all_formatted_meta_data( '' ) as $meta ) {
			if ( in_array( $meta->key, $hidden, true ) ) {
				continue;
			}
			$description .= wp_kses_post( $meta->display_key ) . ': ' . wp_strip_all_tags( $meta->display_value ) . ' ';
		}

		return trim( $description );
	}
}
