<?php
/**
 * Invoice product lines sent to Oblio. "Current behaviour" marks a known
 * quirk kept on purpose until its backlog item is done.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\ProductFields;
use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\InvoiceBuilder;
use FGSyncOblio\Document\Mapper\ClientMapper;
use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Order\RegularPriceSnapshot;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;

#[CoversClass( InvoiceBuilder::class )]
#[CoversClass( LineItemMapper::class )]
#[CoversClass( ShippingFeeMapper::class )]
#[CoversClass( BuildContext::class )]
final class InvoiceLinesTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';
		$this->settings = new Settings();
		$this->settings->set( 'cif', 'RO123' );
		$this->settings->set( 'series_invoice', 'FCT' );
	}

	private function builder(): InvoiceBuilder {
		return new InvoiceBuilder(
			$this->settings,
			new ClientMapper(),
			new LineItemMapper( $this->settings ),
			new ShippingFeeMapper(),
			new CollectMapper( $this->settings )
		);
	}

	/**
	 * @param array<int,WC_Order_Item_Product> $items
	 */
	private function order( array $items, float $total ): WC_Order {
		$order = new WC_Order( 1 );
		$order->set_items( $items );
		$order->set_total( $total );
		return $order;
	}

	private function products( WC_Order $order ): array {
		return $this->builder()->build( $order, OrderMeta::TYPE_INVOICE )['products'];
	}

	private function item( array $data, ?WC_Product $product = null ): WC_Order_Item_Product {
		return new WC_Order_Item_Product( $data, $product );
	}

	public function test_simple_taxed_line_has_full_shape(): void {
		$item = $this->item(
			array( 'quantity' => 2.0, 'subtotal' => 200.0, 'subtotal_tax' => 42.0, 'total' => 200.0, 'total_tax' => 42.0 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);

		$products = $this->products( $this->order( array( $item ), 242.0 ) );

		$this->assertSame(
			array(
				array(
					'name'                     => 'Test product',
					'code'                     => '',
					'description'              => '',
					'price'                    => 121.0,
					'measuringUnit'            => 'buc',
					'measuringUnitTranslation' => '',
					'currency'                 => 'RON',
					'vatName'                  => '',
					'vatPercentage'            => 21,
					'vatIncluded'              => true,
					'quantity'                 => 2.0,
					'productType'              => 'Marfa',
					'management'               => '',
					'save'                     => true,
				),
			),
			$products
		);
	}

	public function test_coupon_adds_a_separate_discount_line(): void {
		$item = $this->item(
			array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 80.0, 'total_tax' => 16.8 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);

		$products = $this->products( $this->order( array( $item ), 96.8 ) );

		$this->assertCount( 2, $products );
		$this->assertSame( 121.0, $products[0]['price'] );
		$this->assertSame(
			array( 'name' => 'Discount "Test product"', 'discount' => 24.2, 'discountType' => 'valoric' ),
			$products[1]
		);
	}

	public function test_discount_in_product_setting_folds_the_coupon_into_the_price(): void {
		$this->settings->set( 'invoice_discount_in_product', 'yes' );
		$item = $this->item(
			array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 80.0, 'total_tax' => 16.8 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);

		$products = $this->products( $this->order( array( $item ), 96.8 ) );

		$this->assertCount( 1, $products );
		$this->assertSame( 96.8, $products[0]['price'] );
	}

	public function test_sale_price_in_tax_inclusive_store_shows_regular_price_and_discount(): void {
		$item = $this->item(
			array( 'subtotal' => 80.0, 'subtotal_tax' => 16.8, 'total' => 80.0, 'total_tax' => 16.8 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '96.8' ) )
		);

		$products = $this->products( $this->order( array( $item ), 96.8 ) );

		$this->assertSame( 121.0, $products[0]['price'] );
		$this->assertSame( 24.2, $products[1]['discount'] );
	}

	public function test_sale_price_in_tax_exclusive_store_shows_the_gross_regular_price(): void {
		$item  = $this->item(
			array( 'subtotal' => 80.0, 'subtotal_tax' => 16.8, 'total' => 80.0, 'total_tax' => 16.8 ),
			new WC_Product( array( 'regular_price' => '100', 'price' => '80' ) )
		);
		$order = $this->order( array( $item ), 96.8 );
		$order->set_prices_include_tax( false );

		$products = $this->products( $order );

		$this->assertSame( 121.0, $products[0]['price'] );
		$this->assertSame( 24.2, $products[1]['discount'] );
	}

	private function snapshot( WC_Order_Item_Product $item, string $regular, string $active ): WC_Order_Item_Product {
		$item->add_meta_data( RegularPriceSnapshot::META_KEY, $regular );
		$item->add_meta_data( RegularPriceSnapshot::META_PRICE_KEY, $active );
		return $item;
	}

	public function test_prices_recorded_at_checkout_win_over_a_later_price_change(): void {
		$item = $this->snapshot(
			$this->item(
				array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ),
				new WC_Product( array( 'regular_price' => '145.2', 'price' => '96.8' ) )
			),
			'121',
			'121'
		);

		$products = $this->products( $this->order( array( $item ), 121.0 ) );

		$this->assertCount( 1, $products );
		$this->assertSame( 121.0, $products[0]['price'] );
	}

	public function test_recorded_sale_in_tax_exclusive_store_shows_the_gross_regular_price(): void {
		$item  = $this->snapshot(
			$this->item(
				array( 'subtotal' => 80.0, 'subtotal_tax' => 16.8, 'total' => 80.0, 'total_tax' => 16.8 ),
				new WC_Product( array( 'regular_price' => '90', 'price' => '90' ) )
			),
			'100',
			'80'
		);
		$order = $this->order( array( $item ), 96.8 );
		$order->set_prices_include_tax( false );

		$products = $this->products( $order );

		$this->assertSame( 121.0, $products[0]['price'] );
		$this->assertSame( 24.2, $products[1]['discount'] );
	}

	/**
	 * WooCommerce strips the base VAT for an exempt customer (B2B reverse
	 * charge) in a store whose prices include VAT: 121 becomes 100 net.
	 */
	public function test_vat_exempt_customer_without_a_sale_gets_no_phantom_discount(): void {
		$item = $this->snapshot(
			$this->item(
				array( 'subtotal' => 100.0, 'total' => 100.0 ),
				new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
			),
			'121',
			'121'
		);

		$products = $this->products( $this->order( array( $item ), 100.0 ) );

		$this->assertCount( 1, $products );
		$this->assertSame( 100.0, $products[0]['price'] );
		$this->assertSame( 'SDD', $products[0]['vatName'] );
	}

	public function test_vat_exempt_customer_on_sale_gets_the_net_regular_price_and_discount(): void {
		$item = $this->snapshot(
			$this->item(
				array( 'subtotal' => 82.64, 'total' => 82.64 ),
				new WC_Product( array( 'regular_price' => '121', 'price' => '100' ) )
			),
			'121',
			'100'
		);

		$products = $this->products( $this->order( array( $item ), 82.64 ) );

		$this->assertSame( 99.99, $products[0]['price'] );
		$this->assertSame( 17.35, $products[1]['discount'] );
	}

	/**
	 * Orders placed before the snapshot existed (or created in the admin) use
	 * the product's current regular/active ratio.
	 */
	public function test_without_recorded_prices_the_current_price_ratio_is_used(): void {
		$on_sale  = $this->item(
			array( 'subtotal' => 80.0, 'subtotal_tax' => 16.8, 'total' => 80.0, 'total_tax' => 16.8 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '96.8' ) )
		);
		$repriced = $this->item(
			array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ),
			new WC_Product( array( 'regular_price' => '145.2', 'price' => '145.2' ) )
		);

		$products = $this->products( $this->order( array( $on_sale, $repriced ), 217.8 ) );

		$this->assertCount( 3, $products );
		$this->assertSame( 121.0, $products[0]['price'] );
		$this->assertSame( 24.2, $products[1]['discount'] );
		$this->assertSame( 121.0, $products[2]['price'] );
	}

	public function test_variation_prices_are_read_from_the_variation(): void {
		$GLOBALS['oblio_test_products'][9] = new WC_Product( array( 'regular_price' => '50', 'price' => '40' ) );
		$item = $this->item(
			array( 'variation_id' => 9, 'subtotal' => 40.0, 'total' => 40.0 ),
			new WC_Product( array( 'regular_price' => '999', 'price' => '999' ) )
		);

		$products = $this->products( $this->order( array( $item ), 40.0 ) );

		$this->assertSame( 50.0, $products[0]['price'] );
		$this->assertSame( 10.0, $products[1]['discount'] );
	}

	public function test_a_zero_regular_price_adds_no_discount_line(): void {
		$item = $this->item(
			array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ),
			new WC_Product( array( 'regular_price' => '0', 'price' => '121' ) )
		);

		$products = $this->products( $this->order( array( $item ), 121.0 ) );

		$this->assertCount( 1, $products );
		$this->assertSame( 121.0, $products[0]['price'] );
	}

	public function test_description_lists_item_meta_but_not_the_price_snapshot(): void {
		$item = $this->snapshot( $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) ), '100', '100' );
		$item->add_meta_data( 'Marime', 'XL' );
		$GLOBALS['oblio_test_filter_overrides']['woocommerce_hidden_order_itemmeta'] = ( new RegularPriceSnapshot() )->hide( array( '_qty' ) );

		$products = $this->products( $this->order( array( $item ), 100.0 ) );

		$this->assertSame( 'Marime: XL', $products[0]['description'] );
	}

	public function test_hide_description_setting_blanks_it(): void {
		$this->settings->set( 'hide_description', 'yes' );
		$item = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );
		$item->add_meta_data( 'Marime', 'XL' );

		$products = $this->products( $this->order( array( $item ), 100.0 ) );

		$this->assertSame( '&nbsp;', $products[0]['description'] );
	}

	public function test_package_number_scales_quantity_and_unit_price(): void {
		$GLOBALS['oblio_test_post_meta'][1]['oblio_fgwoo_package_number'] = '6';
		$item = $this->item(
			array( 'quantity' => 2.0, 'subtotal' => 200.0, 'subtotal_tax' => 42.0, 'total' => 200.0, 'total_tax' => 42.0 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);

		$products = $this->products( $this->order( array( $item ), 242.0 ) );

		$this->assertSame( 20.1667, $products[0]['price'] );
		$this->assertSame( 12.0, $products[0]['quantity'] );
	}

	/**
	 * @return array<string,array{0:mixed,1:float}>
	 */
	public static function package_values(): array {
		return array(
			'integer'       => array( '6', 6.0 ),
			'dot decimal'   => array( '2.5', 2.5 ),
			'comma decimal' => array( '2,5', 2.5 ),
			'empty'         => array( '', 0.0 ),
			'negative'      => array( '-1', 0.0 ),
			'text'          => array( 'abc', 0.0 ),
		);
	}

	#[DataProvider( 'package_values' )]
	public function test_package_number_parsing( $raw, float $expected ): void {
		$GLOBALS['oblio_test_post_meta'][1]['oblio_fgwoo_package_number'] = $raw;

		$this->assertSame( $expected, ProductFields::package_number( 1 ) );
	}

	public function test_decimal_package_number_scales_quantity_and_price(): void {
		$GLOBALS['oblio_test_post_meta'][1]['oblio_fgwoo_package_number'] = '2.5';
		$item = $this->item(
			array( 'quantity' => 2, 'subtotal' => 200.0, 'subtotal_tax' => 42.0, 'total' => 200.0, 'total_tax' => 42.0 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);

		$products = $this->products( $this->order( array( $item ), 242.0 ) );

		$this->assertSame( 5.0, $products[0]['quantity'] );
		$this->assertSame( 48.4, $products[0]['price'] );
	}

	public function test_variation_package_number_overrides_the_products(): void {
		$GLOBALS['oblio_test_post_meta'][1]['oblio_fgwoo_package_number']           = '10';
		$GLOBALS['oblio_test_post_meta'][9]['oblio_fgwoo_variation_package_number'] = '2.5';
		$item = $this->item( array( 'variation_id' => 9, 'quantity' => 2, 'subtotal' => 200.0, 'total' => 200.0 ) );

		$products = $this->products( $this->order( array( $item ), 200.0 ) );

		$this->assertSame( 5.0, $products[0]['quantity'] );
		$this->assertSame( 40.0, $products[0]['price'] );
	}

	public function test_fractional_quantity_survives_a_zero_decimal_shop(): void {
		$GLOBALS['oblio_test_options']['woocommerce_price_num_decimals'] = 0;
		$item = $this->item(
			array( 'quantity' => 1.5, 'subtotal' => 150.0, 'subtotal_tax' => 32.0, 'total' => 150.0, 'total_tax' => 32.0 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);

		$payload = $this->builder()->build( $this->order( array( $item ), 182.0 ), OrderMeta::TYPE_INVOICE );

		$this->assertSame( 1.5, $payload['products'][0]['quantity'] );
		$this->assertSame( 2, $payload['precision'] );
	}

	public function test_quantity_keeps_up_to_four_decimals(): void {
		$item = $this->item( array( 'quantity' => 0.125, 'subtotal' => 10.0, 'total' => 10.0 ) );

		$products = $this->products( $this->order( array( $item ), 10.0 ) );

		$this->assertSame( 0.125, $products[0]['quantity'] );
	}

	public static function shop_decimals_cases(): array {
		return array(
			'0 decimals'  => array( 0, 2 ),
			'2 decimals'  => array( 2, 2 ),
			'3 decimals'  => array( 3, 3 ),
			'6 decimals'  => array( 6, 4 ),
		);
	}

	#[DataProvider( 'shop_decimals_cases' )]
	public function test_document_precision_is_the_shop_decimals_clamped_to_oblio_range( int $shop_decimals, int $precision ): void {
		$GLOBALS['oblio_test_options']['woocommerce_price_num_decimals'] = $shop_decimals;

		$ctx = BuildContext::from_settings( $this->settings, 'RON' );

		$this->assertSame( $shop_decimals, $ctx->price_decimals );
		$this->assertSame( $precision, $ctx->precision );
	}

	public function test_price_decimals_filter_drives_both_values(): void {
		$GLOBALS['oblio_test_filter_overrides']['wc_get_price_decimals'] = 3;

		$ctx = BuildContext::from_settings( $this->settings, 'RON' );

		$this->assertSame( 3, $ctx->price_decimals );
		$this->assertSame( 3, $ctx->precision );
	}

	public function test_untaxed_line_is_sent_as_sdd(): void {
		$item = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );

		$products = $this->products( $this->order( array( $item ), 100.0 ) );

		$this->assertSame( 'SDD', $products[0]['vatName'] );
		$this->assertSame( 0, $products[0]['vatPercentage'] );
	}

	public function test_taxes_disabled_leaves_vat_to_oblio(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'no';
		$item = $this->item( array( 'subtotal' => 121.0, 'total' => 121.0 ) );

		$products = $this->products( $this->order( array( $item ), 121.0 ) );

		$this->assertSame( '', $products[0]['vatName'] );
		$this->assertNull( $products[0]['vatPercentage'] );
		$this->assertTrue( $products[0]['vatIncluded'] );
	}

	/**
	 * Current behaviour (A5): the VAT rate is rounded to a whole number.
	 */
	public function test_fractional_vat_rate_is_rounded_to_an_integer(): void {
		$item = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 5.5, 'total' => 100.0, 'total_tax' => 5.5 ) );

		$products = $this->products( $this->order( array( $item ), 105.5 ) );

		$this->assertSame( 6, $products[0]['vatPercentage'] );
	}

	public function test_negative_line_keeps_its_vat_rate(): void {
		$positive = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) );
		$negative = $this->item( array( 'name' => 'Gift card', 'subtotal' => -10.0, 'subtotal_tax' => -2.1, 'total' => -10.0, 'total_tax' => -2.1 ) );

		$products = $this->products( $this->order( array( $positive, $negative ), 108.9 ) );

		$this->assertSame( -12.1, $products[1]['price'] );
		$this->assertSame( '', $products[1]['vatName'] );
		$this->assertSame( 21, $products[1]['vatPercentage'] );
	}

	public function test_negative_untaxed_line_is_sdd(): void {
		$positive = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );
		$negative = $this->item( array( 'name' => 'Gift card', 'subtotal' => -10.0, 'total' => -10.0 ) );

		$products = $this->products( $this->order( array( $positive, $negative ), 90.0 ) );

		$this->assertSame( 'SDD', $products[1]['vatName'] );
	}

	public function test_shipping_fee_and_balancing_lines(): void {
		$item  = $this->item(
			array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ),
			new WC_Product( array( 'regular_price' => '121', 'price' => '121' ) )
		);
		$order = $this->order( array( $item ), 157.31 );
		$order->set_shipping_total( 20.0 );
		$order->set_shipping_tax( 4.2 );
		$order->set_fees( array( new WC_Order_Item_Fee( array( 'name' => 'Taxa ramburs', 'total' => 10.0, 'total_tax' => 2.1 ) ) ) );

		$products = $this->products( $order );

		$this->assertCount( 4, $products );
		$this->assertSame( array( 'Transport', 24.2, 21, 1, 'Serviciu' ), $this->summary( $products[1] ) );
		$this->assertSame( array( 'Taxa ramburs', 12.1, 21, 1, 'Serviciu' ), $this->summary( $products[2] ) );
		$this->assertSame( 'Alte taxe', $products[3]['name'] );
		$this->assertSame( 0.01, $products[3]['price'] );
		$this->assertSame( '', $products[3]['vatName'] );
		$this->assertNull( $products[3]['vatPercentage'] );
	}

	public function test_each_shipping_method_is_its_own_line_with_its_own_rate(): void {
		$item  = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) );
		$order = $this->order( array( $item ), 144.2 );
		$order->set_shipping_total( 20.0 );
		$order->set_shipping_tax( 3.2 );
		$order->set_shipping_items(
			array(
				new WC_Order_Item_Shipping( array( 'name' => 'Curier', 'total' => 10.0, 'total_tax' => 2.1 ) ),
				new WC_Order_Item_Shipping( array( 'name' => 'Posta', 'total' => 10.0, 'total_tax' => 1.1 ) ),
			)
		);

		$products = $this->products( $order );

		$this->assertCount( 3, $products );
		$this->assertSame( array( 'Transport - Curier', 12.1, 21 ), array( $products[1]['name'], $products[1]['price'], $products[1]['vatPercentage'] ) );
		$this->assertSame( array( 'Transport - Posta', 11.1, 11 ), array( $products[2]['name'], $products[2]['price'], $products[2]['vatPercentage'] ) );
	}

	public function test_a_single_shipping_method_keeps_the_transport_name(): void {
		$item  = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );
		$order = $this->order( array( $item ), 112.1 );
		$order->set_shipping_total( 10.0 );
		$order->set_shipping_tax( 2.1 );
		$order->set_shipping_items( array( new WC_Order_Item_Shipping( array( 'name' => 'Curier', 'total' => 10.0, 'total_tax' => 2.1 ) ) ) );

		$products = $this->products( $order );

		$this->assertSame( 'Transport', $products[1]['name'] );
		$this->assertSame( 21, $products[1]['vatPercentage'] );
	}

	public function test_a_free_shipping_method_adds_no_line_and_does_not_rename_the_paid_one(): void {
		$item  = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );
		$order = $this->order( array( $item ), 112.1 );
		$order->set_shipping_items(
			array(
				new WC_Order_Item_Shipping( array( 'name' => 'Curier', 'total' => 10.0, 'total_tax' => 2.1 ) ),
				new WC_Order_Item_Shipping( array( 'name' => 'Ridicare personala' ) ),
			)
		);

		$products = $this->products( $order );

		$this->assertCount( 2, $products );
		$this->assertSame( 'Transport', $products[1]['name'] );
	}

	public function test_untaxed_fee_is_sdd_and_zero_fee_is_skipped(): void {
		$item  = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );
		$order = $this->order( array( $item ), 105.0 );
		$order->set_fees(
			array(
				new WC_Order_Item_Fee( array( 'name' => 'Ambalare', 'total' => 5.0 ) ),
				new WC_Order_Item_Fee( array( 'name' => 'Gratuit' ) ),
			)
		);

		$products = $this->products( $order );

		$this->assertCount( 2, $products );
		$this->assertSame( array( 'Ambalare', 5.0, 'SDD', 0 ), array( $products[1]['name'], $products[1]['price'], $products[1]['vatName'], $products[1]['vatPercentage'] ) );
	}

	public function test_oss_currency_and_order_language_apply_to_payload_and_lines(): void {
		$this->settings->set( 'oss_eur_currency', 'yes' );
		$item  = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) );
		$order = $this->order( array( $item ), 121.0 );
		$order->set_billing( array( 'country' => 'DE' ) );
		$order->update_meta_data( 'wpml_language', 'es' );

		$payload = $this->builder()->build( $order, OrderMeta::TYPE_INVOICE );

		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( 'EUR', $payload['products'][0]['currency'] );
		$this->assertSame( 'SP', $payload['language'] );
	}

	public function test_zero_value_order_is_refused(): void {
		$item = $this->item( array( 'subtotal' => 0.0, 'total' => 0.0 ) );

		$this->expectException( DocumentException::class );
		$this->products( $this->order( array( $item ), 0.0 ) );
	}

	private function summary( array $line ): array {
		return array( $line['name'], $line['price'], $line['vatPercentage'], $line['quantity'], $line['productType'] );
	}
}
