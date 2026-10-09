<?php
/**
 * VAT fields for one document line.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document\Mapper;

use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\VatCategories;
final class LineVat {

	private const EXACT_TOLERANCE = 0.01;

	private const MAX_TOLERANCE = 1.0;

	/**
	 * The rate comes from the tax rates WooCommerce charged on the line (as
	 * recorded on the order) when they're known; otherwise it's derived from
	 * the rounded amounts, with a matching tolerance sized to that rounding.
	 *
	 * @param float                   $net   Net amount.
	 * @param float                   $tax   Tax amount.
	 * @param array<int|string,mixed> $taxes WooCommerce tax rate ID => amount charged on the line.
	 * @param BuildContext            $ctx   Document context.
	 * @return array<string,mixed>
	 */
	public static function fields( float $net, float $tax, array $taxes, BuildContext $ctx ): array {
		if ( ! $ctx->calc_taxes ) {
			return array(
				'vatName'       => '',
				'vatPercentage' => null,
				'vatIncluded'   => true,
			);
		}

		if ( 0.0 === $net || $tax / $net <= 0 ) {
			return self::untaxed( $ctx );
		}

		$percent = self::charged_percent( $taxes, $ctx );
		if ( null !== $percent ) {
			$tolerance = self::EXACT_TOLERANCE;
		} else {
			$percent   = round( $tax / $net * 100, 2 );
			$tolerance = min( self::MAX_TOLERANCE, 0.5 / abs( $net ) + self::EXACT_TOLERANCE );
		}

		return array(
			'vatName'                      => '',
			'vatPercentage'                => $percent,
			'vatIncluded'                  => true,
			VatCategories::TOLERANCE_FIELD => $tolerance,
		);
	}

	/**
	 * VAT for lines not tied to one item (balancing, refund by amount, storno
	 * adjustment): the order's main rate, the untaxed category when the order
	 * carried no tax, or Oblio's default when the order has no tax lines.
	 *
	 * @param BuildContext $ctx Document context.
	 * @return array<string,mixed>
	 */
	public static function document_fields( BuildContext $ctx ): array {
		if ( ! $ctx->calc_taxes || null === $ctx->main_tax_rate ) {
			return array(
				'vatName'       => '',
				'vatPercentage' => null,
				'vatIncluded'   => true,
			);
		}
		if ( $ctx->main_tax_rate <= 0 ) {
			return self::untaxed( $ctx );
		}
		return array(
			'vatName'                      => '',
			'vatPercentage'                => $ctx->main_tax_rate,
			'vatIncluded'                  => true,
			VatCategories::TOLERANCE_FIELD => self::EXACT_TOLERANCE,
		);
	}

	/**
	 * @param BuildContext $ctx Document context.
	 * @return array<string,mixed>
	 */
	private static function untaxed( BuildContext $ctx ): array {
		return array(
			'vatName'       => '' !== $ctx->untaxed_vat_name ? $ctx->untaxed_vat_name : 'SDD',
			'vatPercentage' => 0,
			'vatIncluded'   => true,
		);
	}

	/**
	 * Tax rate ID => amount charged on an order item (product, shipping, fee).
	 *
	 * @param object $item Order or refund item.
	 * @return array<int|string,mixed>
	 */
	public static function item_taxes( $item ): array {
		$taxes = method_exists( $item, 'get_taxes' ) ? $item->get_taxes() : array();
		return is_array( $taxes ) && is_array( $taxes['total'] ?? null ) ? $taxes['total'] : array();
	}

	/**
	 * @param array<int|string,mixed> $taxes Tax rate ID => amount.
	 * @param BuildContext            $ctx   Document context.
	 */
	private static function charged_percent( array $taxes, BuildContext $ctx ): ?float {
		$percent = 0.0;
		$found   = false;
		foreach ( $taxes as $rate_id => $amount ) {
			if ( ! is_numeric( $amount ) || abs( (float) $amount ) < 0.000001 ) {
				continue;
			}
			if ( ! isset( $ctx->tax_rates[ $rate_id ] ) ) {
				return null;
			}
			$percent += $ctx->tax_rates[ $rate_id ];
			$found    = true;
		}
		return $found ? round( $percent, 4 ) : null;
	}
}
