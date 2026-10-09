<?php
/**
 * Immutable snapshot of settings used while building a document.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Support\Settings;
use WC_Order;
use WC_Order_Item_Tax;
final class BuildContext {

	public const QUANTITY_DECIMALS = 4;

	// Oblio only accepts a document precision of 2-4; price_decimals keeps the shop's own value.
	private const MIN_PRECISION = 2;

	private const MAX_PRECISION = 4;

	public function __construct(
		public readonly string $currency,
		public readonly int $precision,
		public readonly int $price_decimals,
		public readonly bool $calc_taxes,
		public readonly bool $discount_in_product,
		public readonly bool $hide_description,
		public readonly string $measuring_unit,
		public readonly string $measuring_unit_translation,
		public readonly string $product_type,
		public readonly string $management,
		public readonly bool $save_price,
		public readonly string $language,
		/** @var array<int|string,float> */
		public readonly array $tax_rates = array(),
		public readonly ?float $main_tax_rate = null,
		public readonly string $untaxed_vat_name = 'SDD',
		public readonly string $virtual_product_type = ''
	) {}

	public static function for_order( Settings $settings, WC_Order $order ): self {
		return self::from_settings( $settings, self::order_currency( $settings, $order ), self::order_language( $settings, $order ), self::order_tax_rates( $order ), self::order_main_tax_rate( $order ) );
	}

	/**
	 * @param Settings                $settings  Plugin settings.
	 * @param string                  $currency  Document currency.
	 * @param string|null             $language  Document language, or null for the setting.
	 * @param array<int|string,float> $tax_rates WooCommerce tax rate ID => percent charged on the order.
	 * @param float|null              $main_tax_rate Percent of the order's largest tax line, 0 when untaxed, null when unknown.
	 */
	public static function from_settings( Settings $settings, string $currency, ?string $language = null, array $tax_rates = array(), ?float $main_tax_rate = null ): self {
		$language       = ( null !== $language && '' !== $language ) ? $language : (string) $settings->get( 'language' );
		$unit           = (string) $settings->get( 'measuring_unit' );
		$price_decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : (int) get_option( 'woocommerce_price_num_decimals', 2 );
		$calc_taxes     = 'yes' === get_option( 'woocommerce_calc_taxes' );

		return new self(
			$currency,
			max( self::MIN_PRECISION, min( self::MAX_PRECISION, $price_decimals ) ),
			$price_decimals,
			$calc_taxes,
			$settings->is_enabled( 'invoice_discount_in_product' ),
			$settings->is_enabled( 'hide_description' ),
			'' !== $unit ? $unit : 'buc',
			'RO' === $language ? '' : (string) $settings->get( 'measuring_unit_translation', '' ),
			(string) $settings->get( 'product_type', 'Marfa' ),
			(string) $settings->get( 'management', '' ),
			! $settings->is_enabled( 'notsave_price' ),
			'' !== $language ? $language : 'RO',
			$tax_rates,
			$main_tax_rate,
			(string) $settings->get( 'vat_untaxed_category', 'SDD' ),
			(string) $settings->get( 'product_type_virtual', '' )
		);
	}

	/**
	 * The percent of each tax rate as recorded on the order when it was placed,
	 * so later rate-table changes (19% to 21%) don't rewrite old orders.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int|string,float>
	 */
	private static function order_tax_rates( WC_Order $order ): array {
		$rates = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( $tax instanceof WC_Order_Item_Tax && null !== $tax->get_rate_percent() ) {
				$rates[ $tax->get_rate_id() ] = (float) $tax->get_rate_percent();
			}
		}
		return $rates;
	}

	/**
	 * Rate for lines that aren't tied to one item (balancing, refund by amount,
	 * storno adjustment): the order tax line that collected the most tax; 0.0
	 * for an order without tax, null when the order has tax but no tax lines.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function order_main_tax_rate( WC_Order $order ): ?float {
		$main    = null;
		$largest = 0.0;
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! $tax instanceof WC_Order_Item_Tax || null === $tax->get_rate_percent() ) {
				continue;
			}
			$collected = abs( (float) $tax->get_tax_total() + (float) $tax->get_shipping_tax_total() );
			if ( null === $main || $collected > $largest ) {
				$main    = (float) $tax->get_rate_percent();
				$largest = $collected;
			}
		}
		if ( null === $main ) {
			return (float) $order->get_total_tax() > 0 ? null : 0.0;
		}
		return $main;
	}

	private static function order_currency( Settings $settings, WC_Order $order ): string {
		$currency = substr( (string) $order->get_currency(), 0, 3 );
		$currency = 'lei' === strtolower( $currency ) ? 'RON' : $currency;

		if ( $settings->is_enabled( 'oss_eur_currency' ) ) {
			$billing_country = $order->get_billing_country();
			if ( '' !== $billing_country && 'RO' !== $billing_country ) {
				$currency = 'EUR';
			}
		}

		return (string) apply_filters( 'oblio_fgwoo_document_currency', $currency, $order );
	}

	private static function order_language( Settings $settings, WC_Order $order ): string {
		$order_lang = (string) $order->get_meta( 'wpml_language' );
		if ( '' !== $order_lang ) {
			$code = strtoupper( substr( $order_lang, 0, 2 ) );
			$code = 'ES' === $code ? 'SP' : $code;
		} else {
			$code = (string) $settings->get( 'language', 'RO' );
		}

		return (string) apply_filters( 'oblio_fgwoo_document_language', $code, $order );
	}
}
