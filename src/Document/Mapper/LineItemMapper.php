<?php
/**
 * Maps order line items to Oblio product lines (with VAT + discounts).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document\Mapper;

use FGSyncOblio\Admin\ProductFields;
use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Order\RegularPriceSnapshot;
use FGSyncOblio\Support\Settings;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
final class LineItemMapper {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function map( WC_Order $order, BuildContext $ctx ): array {
		$products    = array();
		$total       = 0.0;
		$skip_bundle = 'skip' === $this->settings->get( 'bundle_line_mode', 'skip' );

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $skip_bundle && $product instanceof WC_Product && $product->is_type( 'bundle' ) ) {
				if ( abs( (float) $item->get_total() ) > 0.005 ) {
					throw new DocumentException(
						esc_html__( 'Comanda conține un pachet (Bundle) al cărui preț este pus pe linia principală, nu pe componente. Setarea curentă sare peste acea linie, ceea ce ar pierde valoarea de pe factură. Schimbă "Linia produsului tip pachet" (Avansat) pe "Include" sau contactează suportul.', 'fgsync-oblio' )
					);
				}
				continue;
			}

			$quantity = (float) $item->get_quantity();
			if ( $quantity <= 0 ) {
				continue;
			}

			$package = $this->package_number( $item );

			$item_total     = (float) $item->get_total();
			$item_total_tax = (float) $item->get_total_tax();

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

			$regular_price = $this->regular_price( $item, $product, $subtotal, $price, $ctx );

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
			)
				+ LineVat::fields( $item_total, $item_total_tax, LineVat::item_taxes( $item ), $ctx )
				+ array(
					'quantity'    => round( $quantity * $package, BuildContext::QUANTITY_DECIMALS ),
					'productType' => $this->product_type( $item, $ctx->product_type ),
					'management'  => $ctx->management,
					'save'        => $ctx->save_price,
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

	/**
	 * Builds a storno line for a refunded item, using the same package, SKU,
	 * product type and VAT rules as the invoice line.
	 *
	 * @param WC_Order_Item_Product $item Refund line item (negative amounts).
	 * @param BuildContext          $ctx  Document context.
	 * @return array<string,mixed>|null Null when the item refunds no value.
	 */
	public function storno_line( WC_Order_Item_Product $item, BuildContext $ctx ): ?array {
		$net   = abs( (float) $item->get_total() );
		$tax   = abs( (float) $item->get_total_tax() );
		$value = $net + $tax;
		if ( $value <= 0 ) {
			return null;
		}

		$quantity = abs( (float) $item->get_quantity() );
		$quantity = $quantity > 0 ? $quantity : 1.0;
		$package  = $this->package_number( $item );

		return array(
			'name'                     => $item->get_name(),
			'code'                     => $this->sku( $item, $item->get_product() ),
			'price'                    => round( $value / $quantity / $package, $ctx->precision + 2 ),
			'measuringUnit'            => $ctx->measuring_unit,
			'measuringUnitTranslation' => $ctx->measuring_unit_translation,
			'currency'                 => $ctx->currency,
		)
			+ LineVat::fields( $net, $tax, LineVat::item_taxes( $item ), $ctx )
			+ array(
				'quantity'    => -round( $quantity * $package, BuildContext::QUANTITY_DECIMALS ),
				'productType' => $this->product_type( $item, $ctx->product_type ),
				'management'  => $ctx->management,
			);
	}

	/**
	 * List price behind a sale discount: unit price paid x regular/active price
	 * recorded at checkout (current product prices for older or admin orders).
	 * Scaling the paid price keeps WooCommerce's tax handling intact, including
	 * VAT-exempt customers and other-country rates.
	 *
	 * @param WC_Order_Item_Product $item     Order line item.
	 * @param WC_Product|false|null $product  Line product.
	 * @param string                $subtotal Gross unit subtotal (before coupons).
	 * @param string                $price    Gross unit price paid.
	 * @param BuildContext          $ctx      Document context.
	 */
	private function regular_price( WC_Order_Item_Product $item, $product, string $subtotal, string $price, BuildContext $ctx ): string {
		if ( $subtotal !== $price || ! $product ) {
			return $subtotal;
		}

		$regular = (string) $item->get_meta( RegularPriceSnapshot::META_KEY );
		$active  = (string) $item->get_meta( RegularPriceSnapshot::META_PRICE_KEY );
		if ( '' === $regular || '' === $active ) {
			$source = $product;
			if ( $item->get_variation_id() > 0 ) {
				$variation = wc_get_product( $item->get_variation_id() );
				if ( $variation && $variation->exists() ) {
					$source = $variation;
				}
			}
			$regular = (string) $source->get_regular_price();
			$active  = (string) $source->get_price();
		}

		if ( (float) $regular <= 0 || (float) $active <= 0 ) {
			return $price;
		}

		return (string) round( (float) $price * (float) $regular / (float) $active, $ctx->price_decimals );
	}

	private function package_number( WC_Order_Item_Product $item ): float {
		$package = ProductFields::package_number( $item->get_product_id() );

		if ( $item->get_variation_id() > 0 ) {
			$variation_package = ProductFields::variation_package_number( $item->get_variation_id() );
			if ( $variation_package > 0 ) {
				$package = $variation_package;
			}
		}

		return $package > 0 ? $package : 1.0;
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
