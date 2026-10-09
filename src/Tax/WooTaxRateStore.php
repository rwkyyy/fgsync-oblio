<?php
/**
 * TaxRateStore backed by WooCommerce's WC_Tax.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tax;

use WC_Tax;
final class WooTaxRateStore implements TaxRateStore {

	public function standard_rates(): array {
		return $this->rates_for( '' );
	}

	public function all_rates(): array {
		$rows = array();
		foreach ( array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ) as $tax_class ) {
			$rows = array_merge( $rows, $this->rates_for( (string) $tax_class ) );
		}
		return $rows;
	}

	/**
	 * @param string $tax_class Tax class slug, '' for standard.
	 * @return array<int,array{id:int,country:string,state:string,rate:float}>
	 */
	private function rates_for( string $tax_class ): array {
		$rows = array();
		foreach ( WC_Tax::get_rates_for_tax_class( $tax_class ) as $rate ) {
			$rows[] = array(
				'id'      => (int) $rate->tax_rate_id,
				'country' => (string) $rate->tax_rate_country,
				'state'   => (string) $rate->tax_rate_state,
				'rate'    => (float) $rate->tax_rate,
			);
		}
		return $rows;
	}

	public function insert( string $country, float $rate, string $name ): int {
		return (int) WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => $country,
				'tax_rate_state'    => '',
				'tax_rate'          => (string) $rate,
				'tax_rate_name'     => $name,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
	}

	public function update_rate( int $id, float $rate ): void {
		WC_Tax::_update_tax_rate( $id, array( 'tax_rate' => (string) $rate ) );
	}
}
