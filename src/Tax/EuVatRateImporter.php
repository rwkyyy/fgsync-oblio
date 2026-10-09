<?php
/**
 * Writes the bundled EU standard VAT rates into WooCommerce.
 *
 * Fills only countries the shop has no standard-class row for, adds a
 * catch-all row at the store country's rate when none exists, and later
 * updates only the rows it created itself. Rows the shop made are never
 * changed.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tax;

final class EuVatRateImporter {

	public const OPTION = 'oblio_fgwoo_eu_vat_import';

	private const FALLBACK = '*';

	private TaxRateStore $store;

	public function __construct( TaxRateStore $store ) {
		$this->store = $store;
	}

	/**
	 * What an import would do, without writing anything.
	 *
	 * @param array<string,mixed> $bundle       Validated EuVatRates bundle.
	 * @param string              $base_country Store country (fallback rate source).
	 * @return array{add:array<string,float>,update:array<string,array{from:float,to:float}>,kept:array<int,string>}
	 */
	public function plan( array $bundle, string $base_country ): array {
		$rows     = $this->store->standard_rates();
		$imported = $this->imported_rows();
		$plan     = array(
			'add'    => array(),
			'update' => array(),
			'kept'   => array(),
		);

		$targets = array();
		foreach ( (array) $bundle['rates'] as $country => $rate ) {
			$targets[ (string) $country ] = (float) $rate['standard'];
		}
		if ( isset( $bundle['rates'][ $base_country ] ) ) {
			$targets[ self::FALLBACK ] = (float) $bundle['rates'][ $base_country ]['standard'];
		}

		foreach ( $targets as $country => $target ) {
			$row_country = self::FALLBACK === $country ? '' : $country;
			$own         = $this->row( $rows, $imported[ $country ] ?? 0 );

			if ( null !== $own ) {
				if ( abs( $own['rate'] - $target ) > 0.0001 ) {
					$plan['update'][ $country ] = array(
						'from' => $own['rate'],
						'to'   => $target,
					);
				}
				continue;
			}

			if ( $this->has_country_row( $rows, $row_country ) ) {
				$plan['kept'][] = $country;
				continue;
			}

			$plan['add'][ $country ] = $target;
		}

		return $plan;
	}

	/**
	 * @param array<string,mixed> $bundle       Validated EuVatRates bundle.
	 * @param string              $base_country Store country.
	 * @param string              $name         Tax rate label shown to customers.
	 * @return array{add:array<string,float>,update:array<string,array{from:float,to:float}>,kept:array<int,string>}
	 */
	public function import( array $bundle, string $base_country, string $name ): array {
		$plan     = $this->plan( $bundle, $base_country );
		$imported = $this->imported_rows();

		foreach ( $plan['add'] as $country => $rate ) {
			$imported[ $country ] = $this->store->insert( self::FALLBACK === $country ? '' : $country, $rate, $name );
		}
		foreach ( $plan['update'] as $country => $change ) {
			$this->store->update_rate( (int) $imported[ $country ], $change['to'] );
		}

		update_option(
			self::OPTION,
			array(
				'fetched_at' => (string) $bundle['fetched_at'],
				'rows'       => $imported,
			),
			false
		);

		return $plan;
	}

	/**
	 * Whether the shop imported before and the bundled rates would now change
	 * one of the imported rows.
	 *
	 * @param array<string,mixed> $bundle       Validated EuVatRates bundle.
	 * @param string              $base_country Store country.
	 */
	public function has_updates( array $bundle, string $base_country ): bool {
		return ! empty( $this->imported_rows() ) && ! empty( $this->plan( $bundle, $base_country )['update'] );
	}

	/**
	 * @return array<string,int> Country (or '*') => tax rate ID created by the import.
	 */
	private function imported_rows(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) && is_array( $state['rows'] ?? null ) ? array_map( 'intval', $state['rows'] ) : array();
	}

	/**
	 * @param array<int,array{id:int,country:string,state:string,rate:float}> $rows Standard rows.
	 * @param int                                                             $id   Row ID.
	 * @return array{id:int,country:string,state:string,rate:float}|null
	 */
	private function row( array $rows, int $id ): ?array {
		foreach ( $rows as $row ) {
			if ( $id > 0 && $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Any row for the country counts as the shop's own choice, including
	 * state-specific ones.
	 *
	 * @param array<int,array{id:int,country:string,state:string,rate:float}> $rows    Standard rows.
	 * @param string                                                          $country Country code, '' for catch-all.
	 */
	private function has_country_row( array $rows, string $country ): bool {
		foreach ( $rows as $row ) {
			if ( $row['country'] === $country && ( '' !== $country || '' === $row['state'] ) ) {
				return true;
			}
		}
		return false;
	}
}
