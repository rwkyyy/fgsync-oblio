<?php
/**
 * Immutable snapshot of settings used while building a document.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Support\Settings;
final class BuildContext {

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
		public readonly string $language
	) {}

	public static function from_settings( Settings $settings, string $currency, ?string $language = null ): self {
		$language = ( null !== $language && '' !== $language ) ? $language : (string) $settings->get( 'language' );
		$unit     = (string) $settings->get( 'measuring_unit' );

		return new self(
			$currency,
			(int) get_option( 'woocommerce_price_num_decimals', 2 ),
			function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
			'yes' === get_option( 'woocommerce_calc_taxes' ),
			$settings->is_enabled( 'invoice_discount_in_product' ),
			$settings->is_enabled( 'hide_description' ),
			'' !== $unit ? $unit : 'buc',
			'RO' === $language ? '' : (string) $settings->get( 'measuring_unit_translation', '' ),
			(string) $settings->get( 'product_type', 'Marfa' ),
			(string) $settings->get( 'management', '' ),
			! $settings->is_enabled( 'notsave_price' ),
			'' !== $language ? $language : 'RO'
		);
	}
}
