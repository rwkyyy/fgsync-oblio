<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Stock\ProductUpdater;
use FGSyncOblio\Support\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Product;

#[CoversClass( ProductUpdater::class )]
final class ProductUpdaterTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	private function product( array $data ): WC_Product {
		$product = new WC_Product( $data + array( 'id' => 7 ) );
		$GLOBALS['oblio_test_products'][ $product->get_id() ] = $product;
		return $product;
	}

	private function sync_price( float $oblio_price ): bool {
		$agg = array(
			'price'         => $oblio_price,
			'vatPercentage' => 21,
			'vatIncluded'   => false,
			'quantity'      => 0,
		);

		return ( new ProductUpdater( new Logger() ) )->update( array( 'code' => 'SKU-7' ), $agg, true, array(), array( 'SKU-7' => 7 ) );
	}

	public function test_an_active_sale_below_the_new_regular_price_is_kept(): void {
		$product = $this->product(
			array(
				'regular_price' => '100',
				'sale_price'    => '80',
				'price'         => '80',
			)
		);

		$this->assertTrue( $this->sync_price( 120 ) );

		$this->assertSame( '120', $product->get_regular_price() );
		$this->assertSame( '80', $product->get_sale_price() );
		$this->assertSame( '80', $product->get_price() );
		$this->assertSame( 1, $product->saves );
	}

	public function test_a_scheduled_sale_is_kept_and_the_active_price_is_the_regular_one(): void {
		$product = $this->product(
			array(
				'regular_price'     => '100',
				'sale_price'        => '80',
				'date_on_sale_from' => time() + DAY_IN_SECONDS,
				'price'             => '100',
			)
		);

		$this->sync_price( 120 );

		$this->assertSame( '80', $product->get_sale_price() );
		$this->assertSame( '120', $product->get_price() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function stale_sale_prices(): array {
		return array(
			'equal to the new regular price'  => array( '90' ),
			'above the new regular price'     => array( '95' ),
		);
	}

	#[DataProvider( 'stale_sale_prices' )]
	public function test_a_sale_no_longer_below_the_new_regular_price_is_cleared( string $sale ): void {
		$product = $this->product(
			array(
				'regular_price' => '100',
				'sale_price'    => $sale,
				'price'         => $sale,
			)
		);

		$this->assertTrue( $this->sync_price( 90 ) );

		$this->assertSame( '90', $product->get_regular_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( '90', $product->get_price() );
	}

	public function test_an_unchanged_regular_price_with_a_valid_sale_writes_nothing(): void {
		$product = $this->product(
			array(
				'regular_price' => '100',
				'sale_price'    => '80',
				'price'         => '80',
			)
		);

		$this->assertFalse( $this->sync_price( 100 ) );

		$this->assertSame( '80', $product->get_sale_price() );
		$this->assertSame( 0, $product->saves );
	}

	public function test_a_product_without_a_sale_gets_the_new_regular_and_active_price(): void {
		$product = $this->product(
			array(
				'regular_price' => '100',
				'price'         => '100',
			)
		);

		$this->assertTrue( $this->sync_price( 110 ) );

		$this->assertSame( '110', $product->get_regular_price() );
		$this->assertSame( '110', $product->get_price() );
	}

	/**
	 * @return array<string,array{0:string,1:float,2:int}>
	 */
	public static function package_stock_cases(): array {
		return array(
			'decimal package'        => array( '2.5', 7.5, 3 ),
			'partial package floors' => array( '2.5', 9.0, 3 ),
			'float division noise'   => array( '0.1', 0.3, 3 ),
		);
	}

	#[DataProvider( 'package_stock_cases' )]
	public function test_stock_is_counted_in_whole_packages( string $package, float $oblio_quantity, int $expected ): void {
		$GLOBALS['oblio_test_post_meta'][7]['oblio_fgwoo_package_number'] = $package;
		$product = $this->product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 0,
			)
		);

		( new ProductUpdater( new Logger() ) )->update( array( 'code' => 'SKU-7' ), array( 'quantity' => $oblio_quantity ), false, array(), array( 'SKU-7' => 7 ) );

		$this->assertSame( $expected, $product->get_stock_quantity() );
	}

	public function test_a_variation_uses_its_own_package_number(): void {
		$GLOBALS['oblio_test_post_meta'][7]['oblio_fgwoo_variation_package_number'] = '2.5';
		$GLOBALS['oblio_test_post_meta'][5]['oblio_fgwoo_package_number']           = '10';
		$this->product( array( 'id' => 5 ) );
		$variation = $this->product(
			array(
				'type'           => 'variation',
				'parent_id'      => 5,
				'manage_stock'   => true,
				'stock_quantity' => 0,
			)
		);

		$this->assertTrue( $this->sync( array( 'quantity' => 10.0, 'price' => 20.0 ), true ) );

		$this->assertSame( 4, $variation->get_stock_quantity() );
		$this->assertSame( '50', $variation->get_regular_price() );
	}

	public function test_a_variation_without_its_own_package_number_uses_the_parents(): void {
		$GLOBALS['oblio_test_post_meta'][5]['oblio_fgwoo_package_number'] = '2.5';
		$GLOBALS['oblio_test_products'][5] = new WC_Product( array( 'id' => 5 ) );
		$variation = $this->product(
			array(
				'type'           => 'variation',
				'parent_id'      => 5,
				'manage_stock'   => true,
				'stock_quantity' => 0,
			)
		);

		$this->sync( array( 'quantity' => 10.0 ), false );

		$this->assertSame( 4, $variation->get_stock_quantity() );
	}

	/**
	 * @return array<string,array{0:bool,1:string,2:string}>
	 */
	public static function vat_conversions(): array {
		return array(
			'oblio gross, shop net'  => array( true, 'no', '100' ),
			'oblio net, shop gross'  => array( false, 'yes', '146.41' ),
			'both gross'             => array( true, 'yes', '121' ),
			'both net'               => array( false, 'no', '121' ),
		);
	}

	#[DataProvider( 'vat_conversions' )]
	public function test_the_oblio_price_is_converted_to_the_shops_tax_display( bool $oblio_includes_vat, string $shop_includes_vat, string $expected ): void {
		$GLOBALS['oblio_test_options']['woocommerce_prices_include_tax'] = $shop_includes_vat;
		$product = $this->product( array( 'regular_price' => '1' ) );

		$this->sync(
			array(
				'price'         => 121.0,
				'vatPercentage' => 21,
				'vatIncluded'   => $oblio_includes_vat,
			),
			true
		);

		$this->assertSame( $expected, (string) round( (float) $product->get_regular_price(), 2 ) );
	}

	public function test_a_price_in_another_currency_is_not_applied(): void {
		$product = $this->product( array( 'regular_price' => '100' ) );

		$this->assertFalse(
			$this->sync(
				array(
					'price'    => 50.0,
					'currency' => 'EUR',
				),
				true
			)
		);

		$this->assertSame( '100', $product->get_regular_price() );
	}

	/**
	 * @return array<string,array{0:string,1:float,2:string}>
	 */
	public static function stock_statuses(): array {
		return array(
			'in stock'                 => array( 'no', 3.0, 'instock' ),
			'sold out'                 => array( 'no', 0.0, 'outofstock' ),
			'sold out with backorders' => array( 'notify', 0.0, 'onbackorder' ),
		);
	}

	#[DataProvider( 'stock_statuses' )]
	public function test_stock_status_follows_quantity_and_backorders( string $backorders, float $quantity, string $status ): void {
		$product = $this->product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 5,
				'stock_status'   => 'unknown',
				'backorders'     => $backorders,
			)
		);

		$this->sync( array( 'quantity' => $quantity ), false );

		$this->assertSame( $status, $product->get_stock_status() );
	}

	/**
	 * @param array<string,mixed> $agg
	 */
	private function sync( array $agg, bool $update_price ): bool {
		$agg += array(
			'price'         => 0.0,
			'vatPercentage' => 0,
			'vatIncluded'   => false,
			'quantity'      => 0.0,
		);
		return ( new ProductUpdater( new Logger() ) )->update( array( 'code' => 'SKU-7' ), $agg, $update_price, array(), array( 'SKU-7' => 7 ) );
	}
}
