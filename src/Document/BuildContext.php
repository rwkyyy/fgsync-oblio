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
		public readonly array $tax_rates = array()
	) {}

	public static function for_order( Settings $settings, WC_Order $order ): self {
		return self::from_settings( $settings, self::order_currency( $settings, $order ), self::order_language( $settings, $order ), self::order_tax_rates( $order ) );
	}

	/**
	 * @param Settings                $settings  Plugin settings.
	 * @param string                  $currency  Document currency.
	 * @param string|null             $language  Document language, or null for the setting.
	 * @param array<int|string,float> $tax_rates WooCommerce tax rate ID => percent charged on the order.
	 */
	public static function from_settings( Settings $settings, string $currency, ?string $language = null, array $tax_rates = array() ): self {
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
			$tax_rates
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
