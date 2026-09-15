<?php
/**
 * Updates a WooCommerce product's stock/price from aggregated Oblio data.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Stock;

use OblioWoo\Admin\ProductFields;
use OblioWoo\Support\Logger;
use WC_Product;
final class ProductUpdater {

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public function update( array $product, array $agg, bool $update_price, array $reservations = array(), array $sku_map = array() ): bool {
		$code = (string) ( $product['code'] ?? '' );
		if ( '' === $code ) {
			return false;
		}

		$product_id = (int) ( $sku_map[ $code ] ?? 0 );
		if ( $product_id <= 0 && empty( $sku_map ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $code );
		}
		if ( $product_id <= 0 ) {
			return false;
		}

		$wc = wc_get_product( $product_id );
		if ( ! $wc instanceof WC_Product ) {
			return false;
		}

		$manages   = $wc->get_manage_stock();
		$has_stock = $agg['has_stock'] ?? true;
		$do_stock  = $manages && $has_stock;
		if ( ! $do_stock && ! $update_price ) {
			return false;
		}

		$package = $this->package_number( $wc );
		$changed = false;

		$movement = array(
			'sku'       => $code,
			'sources'   => $agg['sources'] ?? array(),
			'oblio_qty' => $agg['quantity'] ?? null,
			'package'   => $package,
		);

		if ( $do_stock ) {
			$quantity  = (int) floor( $agg['quantity'] / $package );
			$reserved  = (int) ( $reservations[ $product_id ] ?? 0 );
			$quantity -= $reserved;

			$quantity = (int) apply_filters( 'oblio_fgwoo_stock_quantity', $quantity, $product_id, $wc );
			$status   = $this->stock_status( $wc, $quantity );

			$stock_from        = (int) $wc->get_stock_quantity();
			$movement['stock'] = array(
				'from'     => $stock_from,
				'to'       => $quantity,
				'reserved' => $reserved,
			);

			if ( $stock_from !== $quantity ) {
				$wc->set_stock_quantity( $quantity );
				$changed = true;
			}
			if ( $wc->get_stock_status() !== $status ) {
				$wc->set_stock_status( $status );
				$changed = true;
			}
		}

		if ( $update_price && $this->currency_matches( $agg, $movement ) ) {
			$price = $this->convert_price( $agg['price'], $agg['vatPercentage'], $agg['vatIncluded'] ) * $package;

			$price = (float) apply_filters( 'oblio_fgwoo_stock_price', $price, $product_id, $wc );

			$price_from        = (float) $wc->get_regular_price();
			$had_sale          = '' !== (string) $wc->get_sale_price();
			$movement['price'] = array(
				'from' => $price_from,
				'to'   => $price,
			);

			$regular_changed = abs( $price_from - $price ) > 0.00001;
			if ( $regular_changed ) {
				$wc->set_regular_price( (string) $price );
				$changed = true;
			}
			if ( $had_sale ) {
				$wc->set_sale_price( '' );
				$changed = true;
			}

			if ( $regular_changed || $had_sale ) {
				$wc->set_price( (string) $price );
			}
		}

		if ( $changed ) {
			$wc->save();
		}

		$movement['changed'] = $changed;
		$this->logger->debug( sprintf( 'Stock %s: %s', $changed ? 'movement' : 'unchanged', $code ), $movement );

		return $changed;
	}

	private function stock_status( WC_Product $wc, int $quantity ): string {
		if ( $quantity > 0 ) {
			return 'instock';
		}

		return 'no' === $wc->get_backorders() ? 'outofstock' : 'onbackorder';
	}

	private function currency_matches( array $agg, array &$movement ): bool {
		$oblio = strtoupper( (string) ( $agg['currency'] ?? '' ) );
		$shop  = strtoupper( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '' );
		if ( '' === $oblio || '' === $shop || $oblio === $shop ) {
			return true;
		}
		$movement['price'] = array(
			'skipped' => 'currency',
			'oblio'   => $oblio,
			'shop'    => $shop,
		);
		return false;
	}

	private function convert_price( float $price, float $vat_percent, bool $oblio_incl ): float {
		$wc_incl = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();

		if ( $oblio_incl && ! $wc_incl ) {
			return $price / ( 1 + $vat_percent / 100 );
		}
		if ( ! $oblio_incl && $wc_incl ) {
			return $price * ( 1 + $vat_percent / 100 );
		}
		return $price;
	}

	private function package_number( WC_Product $wc ): int {
		$package = ProductFields::package_number( $wc->get_id() );

		if ( $wc->is_type( 'variation' ) ) {
			$variation_package = ProductFields::variation_package_number( $wc->get_id() );
			if ( $variation_package > 0 ) {
				$package = $variation_package;
			} else {
				$parent = wc_get_product( $wc->get_parent_id() );
				if ( $parent instanceof WC_Product ) {
					$package = ProductFields::package_number( $parent->get_id() );
				}
			}
		}

		return $package > 0 ? $package : 1;
	}
}
