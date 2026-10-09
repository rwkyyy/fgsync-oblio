<?php
/**
 * Read/write access to WooCommerce's tax rate rows.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tax;

interface TaxRateStore {

	/**
	 * Standard-class rows; country is '' for a catch-all row.
	 *
	 * @return array<int,array{id:int,country:string,state:string,rate:float}>
	 */
	public function standard_rates(): array;

	/**
	 * Rows of every tax class.
	 *
	 * @return array<int,array{id:int,country:string,state:string,rate:float}>
	 */
	public function all_rates(): array;

	public function insert( string $country, float $rate, string $name ): int;

	public function update_rate( int $id, float $rate ): void;
}
