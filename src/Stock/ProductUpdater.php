<?php
/**
 * Updates a WooCommerce product's stock/price from aggregated Oblio data.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

use FGSyncOblio\Admin\ProductFields;
use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use WC_Product;
final class ProductUpdater {

	private Logger $logger;

	private Settings $settings;

	/** @var array<int,string> */
	private array $type_skips = array();

	public function __construct( Logger $logger, Settings $settings ) {
		$this->logger   = $logger;
		$this->settings = $settings;
	}

	/**
	 * @param array<string,mixed> $product       Oblio product.
	 * @param array<string,mixed> $agg           Stock and price aggregated over the selected warehouses.
	 * @param bool                $update_price  Whether to write the price too.
	 * @param array<int,int>      $reservations  Reserved quantity per product ID.
	 * @param array<string,int>   $sku_map       Product ID per SKU.
	 */
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

		// The same code on another type is different stock, e.g. raw goods vs the finished product.
		$oblio_type = (string) ( $product['productType'] ?? '' );
		if ( '' !== $oblio_type && $this->settings->is_enabled( 'stock_match_product_type' ) ) {
			$shop_type = $this->product_type( $wc );
			if ( $oblio_type !== $shop_type ) {
				$this->type_skips[] = $code;
				$this->logger->info( sprintf( 'Stock sync: skipped %s, the Oblio product is "%s" and the WooCommerce product is "%s"', $code, $oblio_type, $shop_type ) );
				return false;
			}
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
			$quantity  = (int) floor( round( $agg['quantity'] / $package, BuildContext::QUANTITY_DECIMALS ) );
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

			$price_from        = (float) $wc->get_regular_price( 'edit' );
			$sale_price        = (string) $wc->get_sale_price( 'edit' );
			$movement['price'] = array(
				'from' => $price_from,
				'to'   => $price,
			);

			$regular_changed = abs( $price_from - $price ) > 0.00001;
			$clear_sale      = '' !== $sale_price && (float) $sale_price >= $price;
			if ( $regular_changed ) {
				$wc->set_regular_price( (string) $price );
				$changed = true;
			}
			if ( $clear_sale ) {
				$wc->set_sale_price( '' );
				$movement['price']['sale_cleared'] = $sale_price;
				$changed                           = true;
			}

			if ( $regular_changed || $clear_sale ) {
				$wc->set_price( $wc->is_on_sale( 'edit' ) ? (string) $wc->get_sale_price( 'edit' ) : (string) $price );
			}
		}

		if ( $changed ) {
			$wc->save();
		}

		if ( $changed ) {
			$movement['changed'] = true;
			$this->logger->debug( sprintf( 'Stock movement: %s', $code ), $movement );
		}

		return $changed;
	}

	/**
	 * Codes skipped for a type mismatch since the last call.
	 *
	 * @return array<int,string>
	 */
	public function take_type_skips(): array {
		$skips            = $this->type_skips;
		$this->type_skips = array();
		return $skips;
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

	/**
	 * Resolved as on invoice lines, so it names the Oblio product the shop issues.
	 *
	 * @param WC_Product $wc Product.
	 */
	private function product_type( WC_Product $wc ): string {
		$custom = ProductFields::product_type( $wc->get_parent_id() > 0 ? $wc->get_parent_id() : $wc->get_id() );
		if ( '' !== $custom ) {
			return $custom;
		}
		$virtual = (string) $this->settings->get( 'product_type_virtual', '' );
		if ( '' !== $virtual && $wc->is_virtual() ) {
			return $virtual;
		}
		return (string) $this->settings->get( 'product_type', 'Marfa' );
	}

	private function package_number( WC_Product $wc ): float {
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

		return $package > 0 ? $package : 1.0;
	}
}
