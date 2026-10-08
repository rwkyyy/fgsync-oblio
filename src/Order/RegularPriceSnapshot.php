<?php
/**
 * Records each line item's regular and active price at checkout, so invoices
 * issued later show the list price and sale discount the customer actually saw.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Order;

use WC_Order_Item_Product;
use WC_Product;
final class RegularPriceSnapshot {

	public const META_KEY = '_oblio_fgwoo_regular_price';

	public const META_PRICE_KEY = '_oblio_fgwoo_price';

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'capture' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide' ) );
	}

	/**
	 * @param WC_Order_Item_Product $item          New order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array<string,mixed>   $values        Cart item data.
	 */
	public function capture( $item, $cart_item_key, $values ): void {
		$product = $values['data'] ?? null;
		if ( ! $item instanceof WC_Order_Item_Product || ! $product instanceof WC_Product ) {
			return;
		}

		$regular = (string) $product->get_regular_price();
		$active  = (string) $product->get_price();
		if ( '' !== $regular && '' !== $active ) {
			$item->add_meta_data( self::META_KEY, $regular, true );
			$item->add_meta_data( self::META_PRICE_KEY, $active, true );
		}
	}

	/**
	 * @param array<int,string> $keys Hidden order item meta keys.
	 * @return array<int,string>
	 */
	public function hide( $keys ): array {
		$keys   = (array) $keys;
		$keys[] = self::META_KEY;
		$keys[] = self::META_PRICE_KEY;
		return $keys;
	}
}
