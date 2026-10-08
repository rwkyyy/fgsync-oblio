<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\ProductFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Product;

#[CoversClass( ProductFields::class )]
final class ProductFieldsTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function product( int $id ): WC_Product {
		$product                               = new WC_Product( array( 'id' => $id ) );
		$GLOBALS['oblio_test_products'][ $id ] = $product;
		return $product;
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function submitted_packages(): array {
		return array(
			'integer'       => array( '6', '6' ),
			'dot decimal'   => array( '2.5', '2.5' ),
			'comma decimal' => array( '2,5', '2.5' ),
			'empty'         => array( '', '' ),
			'zero'          => array( '0', '' ),
			'text'          => array( 'abc', '' ),
		);
	}

	#[DataProvider( 'submitted_packages' )]
	public function test_product_save_stores_a_normalised_package_number( string $submitted, string $stored ): void {
		$product = $this->product( 3 );
		$_POST   = array(
			'woocommerce_meta_nonce' => 'valid-nonce',
			'custom_package_number'  => $submitted,
			'custom_product_type'    => 'Serviciu',
		);

		( new ProductFields() )->save_product_fields( 3 );

		$this->assertSame( $stored, $product->meta['oblio_fgwoo_package_number'] );
		$this->assertSame( 'Serviciu', $product->meta['oblio_fgwoo_product_type'] );
		$this->assertSame( 1, $product->saves );
	}

	public function test_product_save_without_a_valid_nonce_changes_nothing(): void {
		$product = $this->product( 3 );
		$_POST   = array(
			'woocommerce_meta_nonce' => 'forged',
			'custom_package_number'  => '2.5',
		);

		( new ProductFields() )->save_product_fields( 3 );

		$this->assertSame( array(), $product->meta );
		$this->assertSame( 0, $product->saves );
	}

	public function test_variation_save_stores_a_decimal_package_number(): void {
		$variation = $this->product( 4 );
		$_POST     = array(
			'security'            => 'valid-nonce',
			'cfwc_package_number' => array( 1 => '1,25' ),
		);

		( new ProductFields() )->save_variation_fields( 4, 1 );

		$this->assertSame( '1.25', $variation->meta['oblio_fgwoo_variation_package_number'] );
	}

	public function test_legacy_meta_is_read_when_the_new_key_is_empty(): void {
		$GLOBALS['oblio_test_post_meta'][3]['custom_package_number'] = '2.5';
		$GLOBALS['oblio_test_post_meta'][4]['cfwc_package_number']   = '3';

		$this->assertSame( 2.5, ProductFields::package_number( 3 ) );
		$this->assertSame( 3.0, ProductFields::variation_package_number( 4 ) );
	}
}
