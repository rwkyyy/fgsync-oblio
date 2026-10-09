<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Tax\EuVatRateImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( EuVatRateImporter::class )]
final class EuVatRateImporterTest extends TestCase {

	private InMemoryTaxRateStore $store;

	private EuVatRateImporter $importer;

	protected function setUp(): void {
		oblio_test_reset();
		$this->store    = new InMemoryTaxRateStore();
		$this->importer = new EuVatRateImporter( $this->store );
	}

	/**
	 * @param array<string,float> $standard Country => standard rate.
	 * @return array<string,mixed>
	 */
	private function bundle( array $standard = array(), string $fetched_at = '2026-10-01' ): array {
		$standard += array(
			'RO' => 21.0,
			'DE' => 19.0,
			'FI' => 25.5,
		);
		$rates = array();
		foreach ( $standard as $country => $rate ) {
			$rates[ $country ] = array(
				'standard'      => $rate,
				'reduced'       => array(),
				'super_reduced' => null,
				'parking'       => null,
			);
		}
		return array(
			'source'     => 'test',
			'fetched_at' => $fetched_at,
			'rates'      => $rates,
		);
	}

	public function test_an_empty_store_gets_every_country_and_a_catch_all_at_the_store_rate(): void {
		$plan = $this->importer->import( $this->bundle(), 'RO', 'TVA' );

		$this->assertSame( array( 'RO' => 21.0, 'DE' => 19.0, 'FI' => 25.5, '*' => 21.0 ), $plan['add'] );
		$this->assertSame( array( 21.0, 19.0, 25.5, 21.0 ), array( $this->store->rate_for( 'RO' ), $this->store->rate_for( 'DE' ), $this->store->rate_for( 'FI' ), $this->store->rate_for( '' ) ) );
		$this->assertSame( 'TVA', array_values( $this->store->rows )[0]['name'] );
	}

	public function test_rows_the_shop_made_are_kept_untouched(): void {
		$this->store->add_row( 'RO', 19.0 );
		$this->store->add_row( 'DE', 16.0, 'BE' );
		$this->store->add_row( '', 0.0 );

		$plan = $this->importer->import( $this->bundle(), 'RO', 'TVA' );

		$this->assertSame( array( 'FI' => 25.5 ), $plan['add'] );
		$this->assertSame( array( 'RO', 'DE', '*' ), $plan['kept'] );
		$this->assertSame( 19.0, $this->store->rate_for( 'RO' ) );
		$this->assertSame( 0.0, $this->store->rate_for( '' ) );
	}

	public function test_a_state_specific_catch_all_does_not_count_as_the_fallback(): void {
		$this->store->add_row( '', 5.0, 'XX' );

		$this->assertSame( 21.0, $this->importer->plan( $this->bundle(), 'RO' )['add']['*'] );
	}

	public function test_no_catch_all_when_the_store_is_outside_the_eu(): void {
		$this->assertArrayNotHasKey( '*', $this->importer->plan( $this->bundle(), 'US' )['add'] );
	}

	public function test_a_later_bundle_updates_only_rows_the_import_created(): void {
		$this->store->add_row( 'DE', 16.0 );
		$this->importer->import( $this->bundle(), 'RO', 'TVA' );

		$newer = $this->bundle( array( 'FI' => 26.0, 'DE' => 20.0, 'RO' => 22.0 ), '2026-11-01' );

		$this->assertTrue( $this->importer->has_updates( $newer, 'RO' ) );
		$plan = $this->importer->import( $newer, 'RO', 'TVA' );

		$this->assertSame(
			array(
				'FI' => array( 'from' => 25.5, 'to' => 26.0 ),
				'RO' => array( 'from' => 21.0, 'to' => 22.0 ),
				'*'  => array( 'from' => 21.0, 'to' => 22.0 ),
			),
			$plan['update']
		);
		$this->assertSame( array( 16.0, 26.0 ), array( $this->store->rate_for( 'DE' ), $this->store->rate_for( 'FI' ) ) );
		$this->assertFalse( $this->importer->has_updates( $newer, 'RO' ) );
		$this->assertSame( '2026-11-01', get_option( EuVatRateImporter::OPTION )['fetched_at'] );
	}

	public function test_an_imported_row_the_shop_deleted_is_added_again(): void {
		$this->importer->import( $this->bundle(), 'RO', 'TVA' );
		foreach ( $this->store->rows as $id => $row ) {
			if ( 'FI' === $row['country'] ) {
				unset( $this->store->rows[ $id ] );
			}
		}

		$this->assertSame( array( 'FI' => 25.5 ), $this->importer->plan( $this->bundle(), 'RO' )['add'] );
	}

	public function test_an_imported_row_the_shop_edited_is_updated_back_to_the_bundle_rate(): void {
		$this->importer->import( $this->bundle(), 'RO', 'TVA' );

		$fi = array_values( array_filter( $this->store->rows, static fn ( array $row ): bool => 'FI' === $row['country'] ) )[0];
		$this->store->update_rate( $fi['id'], 24.0 );

		$this->assertSame( array( 'FI' => array( 'from' => 24.0, 'to' => 25.5 ) ), $this->importer->plan( $this->bundle(), 'RO' )['update'] );
	}

	public function test_no_update_notice_before_the_first_import(): void {
		$this->assertFalse( $this->importer->has_updates( $this->bundle(), 'RO' ) );
	}
}
