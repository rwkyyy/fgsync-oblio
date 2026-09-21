<?php
/**
 * Aggregates an Oblio product's stock across selected locations.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

final class LocationAggregator {

	public function aggregate( array $product, array $selected ): ?array {
		if ( empty( $product['stock'] ) || ! is_array( $product['stock'] ) ) {

			if ( isset( $product['price'] ) && '' !== (string) $product['price'] ) {
				return array(
					'quantity'      => 0.0,
					'price'         => (float) $product['price'],
					'vatPercentage' => (float) ( $product['vatPercentage'] ?? 0 ),
					'vatIncluded'   => (bool) ( $product['vatIncluded'] ?? false ),
					'currency'      => (string) ( $product['currency'] ?? '' ),
					'has_stock'     => false,
					'sources'       => array(),
				);
			}
			return null;
		}

		$quantity = 0.0;
		$price    = null;
		$vat      = 0.0;
		$included = false;
		$currency = '';
		$matched  = false;
		$sources  = array();

		foreach ( $product['stock'] as $entry ) {
			$location = ( $entry['workStation'] ?? '' ) . '|' . ( $entry['management'] ?? '' );
			if ( ! empty( $selected ) && ! in_array( $location, $selected, true ) ) {
				continue;
			}

			$entry_quantity = (float) ( $entry['quantity'] ?? 0 );
			$entry_price    = (float) ( $entry['price'] ?? 0 );
			$quantity      += $entry_quantity;
			if ( null === $price ) {
				$price    = $entry_price;
				$vat      = (float) ( $entry['vatPercentage'] ?? 0 );
				$included = (bool) ( $entry['vatIncluded'] ?? false );
				$currency = (string) ( $entry['currency'] ?? '' );
			}
			$sources[] = array(
				'location' => $location,
				'quantity' => $entry_quantity,
				'price'    => $entry_price,
			);
			$matched   = true;
		}

		if ( ! $matched ) {
			return null;
		}

		return array(
			'quantity'      => $quantity,
			'price'         => (float) $price,
			'vatPercentage' => $vat,
			'vatIncluded'   => $included,
			'currency'      => $currency,
			'has_stock'     => true,
			'sources'       => $sources,
		);
	}
}
