<?php
/**
 * TaxRateStore over a plain array, for tests.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Tax\TaxRateStore;

final class InMemoryTaxRateStore implements TaxRateStore {

	/** @var array<int,array{id:int,country:string,state:string,rate:float,name:string}> */
	public array $rows = array();

	private int $next_id = 100;

	public function add_row( string $country, float $rate, string $state = '' ): int {
		$id                = $this->next_id++;
		$this->rows[ $id ] = array(
			'id'      => $id,
			'country' => $country,
			'state'   => $state,
			'rate'    => $rate,
			'name'    => 'Shop',
		);
		return $id;
	}

	public function standard_rates(): array {
		return array_map( static fn ( array $row ): array => array_intersect_key( $row, array_flip( array( 'id', 'country', 'state', 'rate' ) ) ), array_values( $this->rows ) );
	}

	/** @var array<int,array{id:int,country:string,state:string,rate:float}> */
	public array $other_classes = array();

	public function all_rates(): array {
		return array_merge( $this->standard_rates(), $this->other_classes );
	}

	public function insert( string $country, float $rate, string $name ): int {
		$id                        = $this->add_row( $country, $rate );
		$this->rows[ $id ]['name'] = $name;
		return $id;
	}

	public function update_rate( int $id, float $rate ): void {
		$this->rows[ $id ]['rate'] = $rate;
	}

	public function rate_for( string $country ): ?float {
		foreach ( $this->rows as $row ) {
			if ( $row['country'] === $country && '' === $row['state'] ) {
				return $row['rate'];
			}
		}
		return null;
	}
}
