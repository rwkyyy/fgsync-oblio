<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Stock\LocationAggregator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Stock\LocationAggregator::class )]
final class LocationAggregatorTest extends TestCase {

	private LocationAggregator $aggregator;

	private array $product;

	protected function setUp(): void {
		$this->aggregator = new LocationAggregator();
		$this->product    = array(
			'code'  => 'SKU1',
			'stock' => array(
				array(
					'workStation'   => 'Sediu',
					'management'    => 'Magazin',
					'quantity'      => 2,
					'price'         => '200.00',
					'vatPercentage' => 19,
					'vatIncluded'   => false,
				),
				array(
					'workStation'   => 'Depozit',
					'management'    => 'Mobila',
					'quantity'      => 5,
					'price'         => '210.00',
					'vatPercentage' => 19,
					'vatIncluded'   => false,
				),
			),
		);
	}

	public function test_sums_all_locations_when_none_selected(): void {
		$result = $this->aggregator->aggregate( $this->product, array() );
		$this->assertSame( 7.0, $result['quantity'] );
		$this->assertSame( 200.0, $result['price'] );
	}

	public function test_sums_only_selected_location(): void {
		$result = $this->aggregator->aggregate( $this->product, array( 'Depozit|Mobila' ) );
		$this->assertSame( 5.0, $result['quantity'] );
		$this->assertSame( 210.0, $result['price'] );
	}

	public function test_returns_null_for_service_without_stock(): void {
		$this->assertNull(
			$this->aggregator->aggregate(
				array(
					'code'        => 'S',
					'productType' => 'Serviciu',
				),
				array()
			)
		);
	}

	public function test_returns_null_when_no_location_matches(): void {
		$this->assertNull( $this->aggregator->aggregate( $this->product, array( 'Nowhere|X' ) ) );
	}

	public function test_service_with_price_returns_price_without_stock(): void {
		$result = $this->aggregator->aggregate(
			array(
				'code'          => 'S',
				'productType'   => 'Serviciu',
				'price'         => '119.00',
				'vatPercentage' => 19,
				'vatIncluded'   => true,
			),
			array()
		);

		$this->assertNotNull( $result );
		$this->assertFalse( $result['has_stock'] );
		$this->assertSame( 119.0, $result['price'] );
		$this->assertSame( 0.0, $result['quantity'] );
	}

	public function test_stock_result_flags_has_stock(): void {
		$result = $this->aggregator->aggregate( $this->product, array() );
		$this->assertTrue( $result['has_stock'] );
	}

	public function test_reports_per_location_sources(): void {
		$result = $this->aggregator->aggregate( $this->product, array() );
		$this->assertCount( 2, $result['sources'] );
		$this->assertSame( 'Sediu|Magazin', $result['sources'][0]['location'] );
		$this->assertSame( 2.0, $result['sources'][0]['quantity'] );
		$this->assertSame( 200.0, $result['sources'][0]['price'] );
	}

	public function test_sources_limited_to_selected_location(): void {
		$result = $this->aggregator->aggregate( $this->product, array( 'Depozit|Mobila' ) );
		$this->assertCount( 1, $result['sources'] );
		$this->assertSame( 'Depozit|Mobila', $result['sources'][0]['location'] );
		$this->assertSame( 5.0, $result['sources'][0]['quantity'] );
	}
}
