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
use FGSyncOblio\Document\PriorRefunds;
use FGSyncOblio\Order\RegularPriceSnapshot;
use FGSyncOblio\Support\Settings;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Product;
final class LineItemMapper {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param WC_Order          $order   Order.
	 * @param BuildContext      $ctx     Document context.
	 * @param PriorRefunds|null $refunds Refunds to take off the lines.
	 * @return array{products:array<int,array<string,mixed>>,total:float}
	 */
	public function map( WC_Order $order, BuildContext $ctx, ?PriorRefunds $refunds = null ): array {
		$products = array();
		$total    = 0.0;

		foreach ( $this->lines( $order->get_items(), $ctx, $refunds ?? PriorRefunds::none() ) as $line ) {
			$item    = $line['item'];
			$amounts = $line['amounts'];
			$product = $item->get_product();

			$quantity = $line['quantity'];
			if ( $quantity <= 0 || ( $line['refunded'] && $amounts['total'] + $amounts['total_tax'] <= 0.005 ) ) {
				continue;
			}

			$package = $this->package_number( $item );

			$item_total     = $amounts['total'];
			$item_total_tax = $amounts['total_tax'];

			$subtotal = number_format(
				round( $amounts['subtotal'] + $amounts['subtotal_tax'], $ctx->price_decimals ) / $quantity,
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

			$regular_price = $line['from_bundle'] ? $subtotal : $this->regular_price( $item, $product, $subtotal, $price, $ctx );

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
				+ LineVat::fields( $item_total, $item_total_tax, $amounts['taxes'], $ctx )
				+ array(
					'quantity'    => round( $quantity * $package, BuildContext::QUANTITY_DECIMALS ),
					'productType' => $this->product_type( $item, $ctx ),
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
	 * Order lines with their amounts. Unless the bundle line is set to be
	 * included, a WooCommerce Product Bundles container line is dropped and
	 * any value it carries (a fixed-price bundle) is spread over its component
	 * lines, so the invoice keeps the bundle price and stock comes off the
	 * components' real SKUs.
	 *
	 * @param array<int|string,mixed> $items   Order items.
	 * @param BuildContext            $ctx     Document context.
	 * @param PriorRefunds            $refunds Refunds to take off the lines.
	 * @return array<int|string,array{item:WC_Order_Item_Product,amounts:array<string,mixed>,quantity:float,refunded:bool,from_bundle:bool}>
	 * @throws DocumentException When a priced bundle line has no components on the order.
	 */
	private function lines( array $items, BuildContext $ctx, PriorRefunds $refunds ): array {
		$include_bundle = 'include' === $this->settings->get( 'bundle_line_mode', 'auto' );

		$lines      = array();
		$containers = array();
		foreach ( $items as $key => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$refunded      = $refunds->for_item( $item->get_id() );
			$quantity      = (float) $item->get_quantity();
			$lines[ $key ] = array(
				'item'        => $item,
				'amounts'     => self::amounts( $item, $refunded ),
				'quantity'    => round( $quantity - $refunded['quantity'], BuildContext::QUANTITY_DECIMALS ),
				'refunded'    => $refunded['total'] + $refunded['total_tax'] > 0,
				'from_bundle' => false,
			);
			$product       = $item->get_product();
			if ( ! $include_bundle && $product instanceof WC_Product && $product->is_type( 'bundle' ) ) {
				$containers[] = $key;
			}
		}

		foreach ( $containers as $key ) {
			$container = $lines[ $key ];
			unset( $lines[ $key ] );

			$lines = self::scale_refunded_components( $container, $lines );

			$value = $container['amounts']['total'] + $container['amounts']['total_tax'];
			if ( abs( $value ) <= 0.005 ) {
				continue;
			}

			$components = self::component_keys( $container['item'], $lines );
			if ( array() === $components ) {
				throw new DocumentException(
					esc_html__( 'Comanda conține un pachet (Bundle) cu preț pe linia principală, dar componentele lui nu apar pe comandă, deci prețul nu poate fi împărțit pe ele. Schimbă "Produse pachet (Bundles)" (Avansat) pe "Include" sau contactează suportul.', 'fgsync-oblio' )
				);
			}

			$lines = self::spread( $container['amounts'], $components, $lines, $ctx );
		}

		return $lines;
	}

	/**
	 * Line amounts less what was refunded. The subtotal (before coupons) has
	 * no refund figure of its own, so it shrinks with the quantity left.
	 *
	 * @param WC_Order_Item_Product                                                           $item     Order line.
	 * @param array{quantity:float,total:float,total_tax:float,taxes:array<int|string,float>} $refunded Refunded on this line.
	 * @return array{total:float,total_tax:float,subtotal:float,subtotal_tax:float,taxes:array<int|string,float>}
	 */
	private static function amounts( WC_Order_Item_Product $item, array $refunded ): array {
		$quantity = (float) $item->get_quantity();
		$kept     = $quantity > 0 && $refunded['quantity'] > 0 ? max( 0.0, ( $quantity - $refunded['quantity'] ) / $quantity ) : 1.0;

		$taxes = array_map( 'floatval', array_filter( LineVat::item_taxes( $item ), 'is_numeric' ) );
		foreach ( $refunded['taxes'] as $rate_id => $amount ) {
			if ( isset( $taxes[ $rate_id ] ) ) {
				$taxes[ $rate_id ] -= $amount;
			}
		}

		return array(
			'total'        => (float) $item->get_total() - $refunded['total'],
			'total_tax'    => (float) $item->get_total_tax() - $refunded['total_tax'],
			'subtotal'     => (float) $item->get_subtotal() * $kept,
			'subtotal_tax' => (float) $item->get_subtotal_tax() * $kept,
			'taxes'        => $taxes,
		);
	}

	/**
	 * A refunded bundle quantity takes the same share off component lines
	 * that weren't refunded on their own.
	 *
	 * @param array<string,mixed>                   $container Bundle container line.
	 * @param array<int|string,array<string,mixed>> $lines     Remaining order lines.
	 * @return array<int|string,array<string,mixed>>
	 */
	private static function scale_refunded_components( array $container, array $lines ): array {
		$ordered = (float) $container['item']->get_quantity();
		if ( $ordered <= 0 || $container['quantity'] >= $ordered ) {
			return $lines;
		}
		$kept = max( 0.0, $container['quantity'] / $ordered );
		foreach ( self::component_keys( $container['item'], $lines ) as $key ) {
			if ( (float) $lines[ $key ]['item']->get_quantity() !== $lines[ $key ]['quantity'] ) {
				continue;
			}
			$lines[ $key ]['quantity'] = round( $lines[ $key ]['quantity'] * $kept, BuildContext::QUANTITY_DECIMALS );
			foreach ( array( 'total', 'total_tax', 'subtotal', 'subtotal_tax' ) as $field ) {
				$lines[ $key ]['amounts'][ $field ] *= $kept;
			}
			foreach ( $lines[ $key ]['amounts']['taxes'] as $rate_id => $amount ) {
				$lines[ $key ]['amounts']['taxes'][ $rate_id ] = $amount * $kept;
			}
		}
		return $lines;
	}

	/**
	 * Keys of the lines WooCommerce Product Bundles added for this container.
	 *
	 * @param WC_Order_Item_Product                 $container Bundle container line.
	 * @param array<int|string,array<string,mixed>> $lines     Remaining order lines.
	 * @return array<int,int|string>
	 */
	private static function component_keys( WC_Order_Item_Product $container, array $lines ): array {
		$cart_key = (string) $container->get_meta( '_bundle_cart_key' );
		if ( '' === $cart_key ) {
			return array();
		}

		$keys = array();
		foreach ( $lines as $key => $line ) {
			if ( (string) $line['item']->get_meta( '_bundled_by' ) === $cart_key ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Adds the container's amounts to its components, weighted by each
	 * component's regular price x quantity (by quantity when no prices are
	 * set). The last component takes the rounding remainder, so the totals
	 * stay exact.
	 *
	 * @param array<string,mixed>                   $container  Container amounts.
	 * @param array<int,int|string>                 $components Component line keys.
	 * @param array<int|string,array<string,mixed>> $lines      Order lines.
	 * @param BuildContext                          $ctx        Document context.
	 * @return array<int|string,array<string,mixed>>
	 */
	private static function spread( array $container, array $components, array $lines, BuildContext $ctx ): array {
		$weights = array();
		foreach ( $components as $key ) {
			$item            = $lines[ $key ]['item'];
			$product         = $item->get_product();
			$regular         = $product instanceof WC_Product ? (float) $product->get_regular_price() : 0.0;
			$weights[ $key ] = max( 0.0, $regular ) * $lines[ $key ]['quantity'];
		}
		if ( array_sum( $weights ) <= 0 ) {
			foreach ( $components as $key ) {
				$weights[ $key ] = max( 0.0, $lines[ $key ]['quantity'] );
			}
		}
		$weight_sum = array_sum( $weights );
		if ( $weight_sum <= 0 ) {
			$weights    = array_fill_keys( $components, 1.0 );
			$weight_sum = (float) count( $components );
		}

		$last      = end( $components );
		$allocated = array();
		foreach ( $components as $key ) {
			$share = $weights[ $key ] / $weight_sum;
			foreach ( array( 'total', 'total_tax', 'subtotal', 'subtotal_tax' ) as $field ) {
				$part                                = $key === $last
					? $container[ $field ] - ( $allocated[ $field ] ?? 0.0 )
					: round( $container[ $field ] * $share, $ctx->price_decimals );
				$allocated[ $field ]                 = ( $allocated[ $field ] ?? 0.0 ) + $part;
				$lines[ $key ]['amounts'][ $field ] += $part;
			}
			foreach ( $container['taxes'] as $rate_id => $amount ) {
				$part                           = $key === $last
					? $amount - ( $allocated['taxes'][ $rate_id ] ?? 0.0 )
					: round( $amount * $share, $ctx->price_decimals );
				$allocated['taxes'][ $rate_id ] = ( $allocated['taxes'][ $rate_id ] ?? 0.0 ) + $part;
				$lines[ $key ]['amounts']['taxes'][ $rate_id ] = ( $lines[ $key ]['amounts']['taxes'][ $rate_id ] ?? 0.0 ) + $part;
			}
			$lines[ $key ]['from_bundle'] = true;
		}

		return $lines;
	}

	/**
	 * Storno lines for a refund's items. A refunded bundle container is split
	 * over its components the same way the invoice split it, so the storno
	 * references the components' SKUs instead of the bundle's.
	 *
	 * @param WC_Order        $order  Order the refund belongs to.
	 * @param WC_Order_Refund $refund Refund.
	 * @param BuildContext    $ctx    Document context.
	 * @return array<int,array<string,mixed>>
	 */
	public function storno_lines( WC_Order $order, WC_Order_Refund $refund, BuildContext $ctx ): array {
		$split_bundles = 'include' !== $this->settings->get( 'bundle_line_mode', 'auto' );

		$lines = array();
		foreach ( $refund->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product   = $item->get_product();
			$bundled   = $split_bundles && $product instanceof WC_Product && $product->is_type( 'bundle' )
				? $this->bundle_storno_lines( $order, $item, $ctx )
				: null;
			$item_rows = $bundled ?? array( $this->storno_line( $item, $ctx ) );
			foreach ( $item_rows as $line ) {
				if ( null !== $line ) {
					$lines[] = $line;
				}
			}
		}
		return $lines;
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
		return $this->storno_row(
			$item,
			abs( (float) $item->get_total() ),
			abs( (float) $item->get_total_tax() ),
			array_map( 'abs', array_map( 'floatval', array_filter( LineVat::item_taxes( $item ), 'is_numeric' ) ) ),
			abs( (float) $item->get_quantity() ),
			$ctx
		);
	}

	/**
	 * @param WC_Order_Item_Product   $item     Line the storno names.
	 * @param float                   $net      Net value refunded.
	 * @param float                   $tax      Tax refunded.
	 * @param array<int|string,float> $taxes    Tax rate ID => amount refunded.
	 * @param float                   $quantity Quantity refunded (0 when refunded by value only).
	 * @param BuildContext            $ctx      Document context.
	 * @return array<string,mixed>|null
	 */
	private function storno_row( WC_Order_Item_Product $item, float $net, float $tax, array $taxes, float $quantity, BuildContext $ctx ): ?array {
		$value = $net + $tax;
		if ( $value <= 0 ) {
			return null;
		}

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
			+ LineVat::fields( $net, $tax, $taxes, $ctx )
			+ array(
				'quantity'    => -round( $quantity * $package, BuildContext::QUANTITY_DECIMALS ),
				'productType' => $this->product_type( $item, $ctx ),
				'management'  => $ctx->management,
			);
	}

	/**
	 * Splits a refunded bundle container over the order's component lines,
	 * with quantities in the same proportion as the refund (a refund by value
	 * only stays quantity-less, like any other line).
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item  Refunded container line.
	 * @param BuildContext          $ctx   Document context.
	 * @return array<int,array<string,mixed>|null>|null Null when the original line or its components aren't found.
	 */
	private function bundle_storno_lines( WC_Order $order, WC_Order_Item_Product $item, BuildContext $ctx ): ?array {
		$original_id = (int) $item->get_meta( '_refunded_item_id' );
		$original    = null;
		$lines       = array();
		foreach ( $order->get_items() as $key => $order_item ) {
			if ( ! $order_item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( $original_id > 0 && $order_item->get_id() === $original_id ) {
				$original = $order_item;
				continue;
			}
			$lines[ $key ] = array(
				'item'        => $order_item,
				'amounts'     => array(
					'total'        => 0.0,
					'total_tax'    => 0.0,
					'subtotal'     => 0.0,
					'subtotal_tax' => 0.0,
					'taxes'        => array(),
				),
				'quantity'    => (float) $order_item->get_quantity(),
				'refunded'    => false,
				'from_bundle' => false,
			);
		}
		if ( null === $original ) {
			return null;
		}

		$components = self::component_keys( $original, $lines );
		if ( array() === $components ) {
			return null;
		}

		$net   = abs( (float) $item->get_total() );
		$tax   = abs( (float) $item->get_total_tax() );
		$lines = self::spread(
			array(
				'total'        => $net,
				'total_tax'    => $tax,
				'subtotal'     => $net,
				'subtotal_tax' => $tax,
				'taxes'        => array_map( 'abs', array_map( 'floatval', array_filter( LineVat::item_taxes( $item ), 'is_numeric' ) ) ),
			),
			$components,
			$lines,
			$ctx
		);

		$refunded_quantity = abs( (float) $item->get_quantity() );
		$original_quantity = (float) $original->get_quantity();
		$ratio             = $original_quantity > 0 ? $refunded_quantity / $original_quantity : 0.0;

		$rows = array();
		foreach ( $components as $key ) {
			$component = $lines[ $key ];
			$rows[]    = $this->storno_row(
				$component['item'],
				$component['amounts']['total'],
				$component['amounts']['total_tax'],
				$component['amounts']['taxes'],
				round( (float) $component['item']->get_quantity() * $ratio, BuildContext::QUANTITY_DECIMALS ),
				$ctx
			);
		}
		return $rows;
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

	/**
	 * The product's own Oblio type wins, then the type for virtual products
	 * (when set), then the default.
	 *
	 * @param WC_Order_Item_Product $item Order line.
	 * @param BuildContext          $ctx  Document context.
	 */
	private function product_type( WC_Order_Item_Product $item, BuildContext $ctx ): string {
		$custom = ProductFields::product_type( $item->get_product_id() );
		if ( '' !== $custom ) {
			return $custom;
		}
		$product = $item->get_product();
		if ( '' !== $ctx->virtual_product_type && $product && $product->is_virtual() ) {
			return $ctx->virtual_product_type;
		}
		return $ctx->product_type;
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
