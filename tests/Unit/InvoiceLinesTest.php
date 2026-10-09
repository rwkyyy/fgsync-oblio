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
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\FullyRefundedException;
use FGSyncOblio\Document\InvoiceBuilder;
use FGSyncOblio\Document\Mapper\ClientMapper;
use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Document\PriorRefunds;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Order\RegularPriceSnapshot;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
use WC_Product;

#[CoversClass( InvoiceBuilder::class )]
#[CoversClass( LineItemMapper::class )]
#[CoversClass( ShippingFeeMapper::class )]
#[CoversClass( BuildContext::class )]
#[CoversClass( PriorRefunds::class )]
final class InvoiceLinesTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		oblio_test_seed_vat_categories();
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
			new CollectMapper( $this->settings ),
			new VatCategories( new ClientFactory( $this->settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) ), $this->settings, new Logger() )
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
					'vatName'                  => 'Normala',
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
			array( 'quantity' => 1.5, 'subtotal' => 150.0, 'subtotal_tax' => 31.5, 'total' => 150.0, 'total_tax' => 31.5 ),
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
	 * Oblio silently turns a nameless 19% line into its default 21% category.
	 */
	public function test_a_19_percent_line_is_sent_with_the_accounts_19_percent_category(): void {
		$item = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 19.0, 'total' => 100.0, 'total_tax' => 19.0 ) );

		$products = $this->products( $this->order( array( $item ), 119.0 ) );

		$this->assertSame( array( 'Veche', 19 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_a_non_integer_rate_uses_its_category(): void {
		$item = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 25.5, 'total' => 100.0, 'total_tax' => 25.5 ) );

		$products = $this->products( $this->order( array( $item ), 125.5 ) );

		$this->assertSame( array( 'Finlanda', 25.5 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_space_padded_category_names_are_sent_exactly(): void {
		$item = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 9.0, 'total' => 100.0, 'total_tax' => 9.0 ) );

		$products = $this->products( $this->order( array( $item ), 109.0 ) );

		$this->assertSame( 'Redusa  ', $products[0]['vatName'] );
	}

	public function test_the_rate_recorded_on_the_order_beats_the_rounded_amounts(): void {
		$item  = $this->item(
			array(
				'subtotal'     => 0.83,
				'subtotal_tax' => 0.17,
				'total'        => 0.83,
				'total_tax'    => 0.17,
				'taxes'        => array( 7 => 0.17 ),
			)
		);
		$order = $this->order( array( $item ), 1.0 );
		$order->set_tax_items( array( new \WC_Order_Item_Tax( 7, 19.0 ) ) );

		$products = $this->products( $order );

		$this->assertSame( array( 'Veche', 19 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_an_unrecorded_rate_id_falls_back_to_the_amounts(): void {
		$item  = $this->item(
			array(
				'subtotal'     => 100.0,
				'subtotal_tax' => 21.0,
				'total'        => 100.0,
				'total_tax'    => 21.0,
				'taxes'        => array( 2 => 0, 8 => 21.0 ),
			)
		);
		$order = $this->order( array( $item ), 121.0 );
		$order->set_tax_items( array( new \WC_Order_Item_Tax( 2, 5.0 ) ) );

		$products = $this->products( $order );

		$this->assertSame( array( 'Normala', 21 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_a_small_line_without_recorded_rates_still_finds_its_category(): void {
		$item = $this->item( array( 'subtotal' => 0.83, 'subtotal_tax' => 0.17, 'total' => 0.83, 'total_tax' => 0.17 ) );

		$products = $this->products( $this->order( array( $item ), 1.0 ) );

		$this->assertSame( array( 'Normala', 21 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_internal_tolerance_hint_never_reaches_the_payload(): void {
		$item = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) );
		$order = $this->order( array( $item ), 133.1 );
		$order->set_shipping_total( 10.0 );
		$order->set_shipping_tax( 2.1 );

		foreach ( $this->products( $order ) as $line ) {
			$this->assertArrayNotHasKey( VatCategories::TOLERANCE_FIELD, $line );
		}
	}

	public function test_an_exact_rate_without_a_category_fails_instead_of_snapping_to_a_neighbour(): void {
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( array( 'name' => 'Redusa ', 'percent' => 5, 'default' => false ) ) ) ),
		);
		$item  = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 5.5, 'total' => 100.0, 'total_tax' => 5.5, 'taxes' => array( 3 => 5.5 ) ) );
		$order = $this->order( array( $item ), 105.5 );
		$order->set_tax_items( array( new \WC_Order_Item_Tax( 3, 5.5 ) ) );

		$this->expectException( DocumentException::class );
		$this->expectExceptionMessage( 'nu are o cotă TVA de 5.5%' );

		$this->products( $order );
	}

	public function test_negative_line_keeps_its_vat_rate(): void {
		$positive = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) );
		$negative = $this->item( array( 'name' => 'Gift card', 'subtotal' => -10.0, 'subtotal_tax' => -2.1, 'total' => -10.0, 'total_tax' => -2.1 ) );

		$products = $this->products( $this->order( array( $positive, $negative ), 108.9 ) );

		$this->assertSame( -12.1, $products[1]['price'] );
		$this->assertSame( 'Normala', $products[1]['vatName'] );
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

	public function test_untaxed_lines_use_the_configured_category(): void {
		$this->settings->set( 'vat_untaxed_category', 'Taxare inversa' );
		$GLOBALS['oblio_test_transients'][ VatCategories::TRANSIENT ][] = array( 'name' => 'Taxare inversa', 'percent' => 0, 'default' => false );
		$item = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );

		$products = $this->products( $this->order( array( $item ), 100.0 ) );

		$this->assertSame( array( 'Taxare inversa', 0 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	/**
	 * @return array<string,array{0:array<int,\WC_Order_Item_Tax>,1:float,2:string,3:int|float|null}>
	 */
	public static function balancing_vat_cases(): array {
		return array(
			'19% order'                    => array( array( new \WC_Order_Item_Tax( 99, 19.0, 19.0 ) ), 19.0, 'Veche', 19 ),
			'largest tax line wins'        => array( array( new \WC_Order_Item_Tax( 1, 9.0, 0.9 ), new \WC_Order_Item_Tax( 99, 21.0, 21.0 ) ), 21.0, 'Normala', 21 ),
			'untaxed order'                => array( array(), 0.0, 'SDD', 0 ),
			'tax but no tax lines: Oblio default' => array( array(), 19.0, '', null ),
			'tax line without a recorded rate'    => array( array( new \WC_Order_Item_Tax( 99, null, 19.0 ) ), 19.0, '', null ),
		);
	}

	#[DataProvider( 'balancing_vat_cases' )]
	public function test_the_balancing_line_uses_the_orders_main_rate( array $tax_items, float $tax, string $vat_name, $percent ): void {
		$item  = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => $tax, 'total' => 100.0, 'total_tax' => $tax, 'taxes' => array( 99 => $tax ) ) );
		$order = $this->order( array( $item ), 100.0 + $tax + 0.01 );
		$order->set_tax_items( $tax_items );

		$products = $this->products( $order );
		$balance  = end( $products );

		$this->assertSame( 'Alte taxe', $balance['name'] );
		$this->assertSame( array( $vat_name, $percent ), array( $balance['vatName'], $balance['vatPercentage'] ) );
	}

	public function test_the_balancing_line_is_left_to_oblio_when_taxes_are_off(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'no';
		$item  = $this->item( array( 'subtotal' => 100.0, 'total' => 100.0 ) );
		$order = $this->order( array( $item ), 100.01 );
		$order->set_tax_items( array( new \WC_Order_Item_Tax( 1, 21.0, 21.0 ) ) );

		$balance = $this->products( $order )[1];

		$this->assertSame( array( '', null ), array( $balance['vatName'], $balance['vatPercentage'] ) );
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

	private function with_document( WC_Order $order, string $type, string $series, string $number ): WC_Order {
		$order->update_meta_data( OrderMeta::key( $type, 'series' ), $series );
		$order->update_meta_data( OrderMeta::key( $type, 'number' ), $number );
		$order->update_meta_data( OrderMeta::key( $type, 'link' ), 'https://example.test/' . $series . $number );
		return $order;
	}

	private function invoice_order(): WC_Order {
		$item = $this->item(
			array( 'quantity' => 1.0, 'subtotal' => 100.0, 'subtotal_tax' => 0.0, 'total' => 100.0, 'total_tax' => 0.0 ),
			new WC_Product( array( 'regular_price' => '100', 'price' => '100' ) )
		);
		return $this->order( array( $item ), 100.0 );
	}

	public function test_invoice_is_built_on_the_aviz_that_moved_the_stock(): void {
		$order = $this->with_document( $this->invoice_order(), OrderMeta::TYPE_NOTICE, 'AVZ', '7' );

		$data = $this->builder()->build( $order, OrderMeta::TYPE_INVOICE );

		$this->assertSame( array( 'type' => 'Aviz', 'seriesName' => 'AVZ', 'number' => '7' ), $data['referenceDocument'] );
		$this->assertSame( array(), $data['products'] );
	}

	public function test_the_aviz_wins_over_a_proforma(): void {
		$order = $this->with_document( $this->with_document( $this->invoice_order(), OrderMeta::TYPE_PROFORMA, 'PRF', '3' ), OrderMeta::TYPE_NOTICE, 'AVZ', '7' );

		$this->assertSame( 'Aviz', $this->builder()->build( $order, OrderMeta::TYPE_INVOICE )['referenceDocument']['type'] );
	}

	public function test_invoice_is_built_on_the_proforma_without_an_aviz(): void {
		$order = $this->with_document( $this->invoice_order(), OrderMeta::TYPE_PROFORMA, 'PRF', '3' );

		$this->assertSame( array( 'type' => 'Proforma', 'seriesName' => 'PRF', 'number' => '3' ), $this->builder()->build( $order, OrderMeta::TYPE_INVOICE )['referenceDocument'] );
	}

	public function test_an_aviz_is_not_built_on_other_documents(): void {
		$this->settings->set( 'series_notice', 'AVZ' );
		$order = $this->with_document( $this->invoice_order(), OrderMeta::TYPE_PROFORMA, 'PRF', '3' );

		$this->assertArrayNotHasKey( 'referenceDocument', $this->builder()->build( $order, OrderMeta::TYPE_NOTICE ) );
	}

	public function test_virtual_products_use_the_virtual_product_type(): void {
		$this->settings->set( 'product_type_virtual', 'Serviciu' );
		$line = array( 'quantity' => 1.0, 'subtotal' => 100.0, 'subtotal_tax' => 0.0, 'total' => 100.0, 'total_tax' => 0.0 );

		$products = $this->products(
			$this->order(
				array(
					$this->item( array( 'name' => 'E-book' ) + $line, new WC_Product( array( 'regular_price' => '100', 'price' => '100', 'virtual' => true ) ) ),
					$this->item( array( 'name' => 'Carte' ) + $line, new WC_Product( array( 'regular_price' => '100', 'price' => '100' ) ) ),
				),
				200.0
			)
		);

		$this->assertSame( array( 'Serviciu', 'Marfa' ), array_column( $products, 'productType' ) );
	}

	public function test_virtual_products_keep_the_default_type_when_none_is_set(): void {
		$item = $this->item(
			array( 'quantity' => 1.0, 'subtotal' => 100.0, 'subtotal_tax' => 0.0, 'total' => 100.0, 'total_tax' => 0.0 ),
			new WC_Product( array( 'regular_price' => '100', 'price' => '100', 'virtual' => true ) )
		);

		$this->assertSame( 'Marfa', $this->products( $this->order( array( $item ), 100.0 ) )[0]['productType'] );
	}

	private function bundle( array $data, string $cart_key ): WC_Order_Item_Product {
		$item = $this->item(
			array( 'name' => 'Pachet 2 X Zeolit' ) + $data,
			new WC_Product( array( 'type' => 'bundle', 'sku' => 'ZEO-x2' ) )
		);
		$item->add_meta_data( '_bundle_cart_key', $cart_key );
		return $item;
	}

	private function component( string $bundled_by, string $sku, string $regular, array $data = array() ): WC_Order_Item_Product {
		$item = $this->item(
			array( 'name' => $sku ) + $data,
			new WC_Product( array( 'sku' => $sku, 'regular_price' => $regular, 'price' => $regular ) )
		);
		$item->add_meta_data( '_bundled_by', $bundled_by );
		return $item;
	}

	public function test_a_fixed_price_bundle_spreads_its_price_over_the_components(): void {
		$order = $this->order(
			array(
				$this->bundle( array( 'subtotal' => 304.36, 'subtotal_tax' => 63.92, 'total' => 304.36, 'total_tax' => 63.92 ), 'cart-1' ),
				$this->component( 'cart-1', 'ZEO', '199' ),
				$this->component( 'cart-1', 'ZEO', '199' ),
			),
			368.28
		);

		$products = $this->products( $order );

		$this->assertSame( array( 'ZEO', 'ZEO' ), array_column( $products, 'code' ) );
		$this->assertSame( array( 184.14, 184.14 ), array_column( $products, 'price' ) );
		$this->assertSame( array( 21, 21 ), array_column( $products, 'vatPercentage' ) );
	}

	public function test_bundle_price_is_weighted_by_component_regular_price_and_keeps_the_total(): void {
		$order = $this->order(
			array(
				$this->bundle( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ), 'cart-1' ),
				$this->component( 'cart-1', 'A', '20' ),
				$this->component( 'cart-1', 'B', '10', array( 'quantity' => 2.0 ) ),
			),
			121.0
		);

		$products = $this->products( $order );

		$this->assertSame( array( 60.5, 30.25 ), array_column( $products, 'price' ) );
		$this->assertCount( 2, $products );
	}

	public function test_a_bundle_coupon_still_shows_as_a_discount_line(): void {
		$order = $this->order(
			array(
				$this->bundle( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 80.0, 'total_tax' => 16.8 ), 'cart-1' ),
				$this->component( 'cart-1', 'A', '50' ),
				$this->component( 'cart-1', 'B', '50' ),
			),
			96.8
		);

		$products = $this->products( $order );

		$this->assertSame( array( 'A', 'Discount "A"', 'B', 'Discount "B"' ), array_column( $products, 'name' ) );
		$this->assertSame( array( 12.1, 12.1 ), array_column( $products, 'discount' ) );
	}

	public function test_a_per_item_priced_bundle_only_invoices_its_components(): void {
		$order = $this->order(
			array(
				$this->bundle( array(), 'cart-1' ),
				$this->component( 'cart-1', 'A', '100', array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) ),
			),
			121.0
		);

		$products = $this->products( $order );

		$this->assertSame( array( 'A' ), array_column( $products, 'code' ) );
		$this->assertSame( array( 121.0 ), array_column( $products, 'price' ) );
	}

	public function test_bundle_price_goes_only_to_its_own_components(): void {
		$order = $this->order(
			array(
				$this->bundle( array( 'subtotal' => 100.0, 'total' => 100.0 ), 'cart-1' ),
				$this->component( 'cart-1', 'A', '10' ),
				$this->component( 'cart-2', 'B', '10', array( 'subtotal' => 50.0, 'total' => 50.0 ) ),
			),
			150.0
		);

		$this->assertSame( array( 100.0, 50.0 ), array_column( $this->products( $order ), 'price' ) );
	}

	public function test_a_priced_bundle_without_components_on_the_order_fails_clearly(): void {
		$order = $this->order(
			array( $this->bundle( array( 'subtotal' => 100.0, 'total' => 100.0 ), 'cart-1' ) ),
			100.0
		);

		$this->expectException( DocumentException::class );
		$this->expectExceptionMessage( 'componentele lui nu apar pe comandă' );
		$this->products( $order );
	}

	public function test_include_mode_invoices_the_bundle_line_itself(): void {
		$this->settings->set( 'bundle_line_mode', 'include' );
		$order = $this->order(
			array(
				$this->bundle( array( 'subtotal' => 100.0, 'total' => 100.0 ), 'cart-1' ),
				$this->component( 'cart-1', 'A', '10' ),
			),
			100.0
		);

		$this->assertSame( array( 'ZEO-x2', 'A' ), array_column( $this->products( $order ), 'code' ) );
	}

	public function test_the_old_skip_value_behaves_like_auto(): void {
		$this->settings->set( 'bundle_line_mode', 'skip' );
		$order = $this->order(
			array(
				$this->bundle( array( 'subtotal' => 100.0, 'total' => 100.0 ), 'cart-1' ),
				$this->component( 'cart-1', 'A', '10' ),
			),
			100.0
		);

		$this->assertSame( array( 100.0 ), array_column( $this->products( $order ), 'price' ) );
	}

	/**
	 * @param array<int,WC_Order_Item_Product>  $items    Refunded product lines (negative amounts).
	 * @param array<int,WC_Order_Item_Shipping> $shipping Refunded shipping lines.
	 */
	private function refund( int $id, float $amount, array $items = array(), array $shipping = array() ): WC_Order_Refund {
		$refund = new WC_Order_Refund( $id, 1 );
		$refund->set_amount( $amount );
		$refund->set_items( $items );
		$refund->set_shipping_items( $shipping );
		return $refund;
	}

	private function refunded_item( int $original_id, array $data ): WC_Order_Item_Product {
		$item = $this->item( $data );
		$item->add_meta_data( '_refunded_item_id', $original_id );
		return $item;
	}

	/** Order 10, only 4 in stock, 6 refunded before the invoice. */
	private function partly_refunded_order(): WC_Order {
		$item  = $this->item(
			array( 'id' => 11, 'name' => 'Caramele cu miere', 'quantity' => 10.0, 'subtotal' => 148.10, 'subtotal_tax' => 31.10, 'total' => 148.10, 'total_tax' => 31.10 ),
			new WC_Product( array( 'sku' => '5941185193338', 'regular_price' => '17.92', 'price' => '17.92' ) )
		);
		$order = $this->order( array( $item ), 198.20 );
		$order->set_shipping_items( array( new WC_Order_Item_Shipping( array( 'id' => 12, 'name' => 'Curier rapid', 'total' => 15.70, 'total_tax' => 3.30 ) ) ) );
		$order->set_refunds(
			array(
				$this->refund( 81441, -107.52, array( $this->refunded_item( 11, array( 'quantity' => -6.0, 'total' => -88.86, 'total_tax' => -18.66 ) ) ) ),
			)
		);
		return $order;
	}

	public function test_a_refund_before_the_invoice_is_taken_off_the_line(): void {
		$products = $this->products( $this->partly_refunded_order() );

		$this->assertCount( 2, $products );
		$this->assertSame( array( '5941185193338', 17.92, 4.0, 21 ), array( $products[0]['code'], $products[0]['price'], $products[0]['quantity'], $products[0]['vatPercentage'] ) );
		$this->assertSame( array( 'Transport', 19.0 ), array( $products[1]['name'], $products[1]['price'] ) );
	}

	public function test_a_fully_refunded_line_and_shipping_are_left_off(): void {
		$kept     = $this->item( array( 'id' => 11, 'name' => 'Kept', 'subtotal' => 100.0, 'total' => 100.0 ) );
		$returned = $this->item( array( 'id' => 13, 'name' => 'Returned', 'quantity' => 2.0, 'subtotal' => 50.0, 'total' => 50.0 ) );
		$order    = $this->order( array( $kept, $returned ), 160.0 );
		$order->set_shipping_items( array( new WC_Order_Item_Shipping( array( 'id' => 12, 'total' => 10.0 ) ) ) );
		$order->set_refunds(
			array(
				$this->refund(
					5,
					-60.0,
					array( $this->refunded_item( 13, array( 'quantity' => -2.0, 'total' => -50.0 ) ) ),
					array( new WC_Order_Item_Shipping( array( 'total' => -10.0, 'meta' => array( '_refunded_item_id' => 12 ) ) ) )
				),
			)
		);

		$this->assertSame( array( 'Kept' ), array_column( $this->products( $order ), 'name' ) );
	}

	public function test_a_refund_by_amount_before_the_invoice_becomes_a_discount_line(): void {
		$item  = $this->item( array( 'subtotal' => 100.0, 'subtotal_tax' => 21.0, 'total' => 100.0, 'total_tax' => 21.0 ) );
		$order = $this->order( array( $item ), 121.0 );
		$order->set_refunds( array( $this->refund( 5, -21.0 ) ) );

		$products = $this->products( $order );

		$this->assertCount( 2, $products );
		$this->assertSame( array( 'Discount', -21.0, 1 ), array( $products[1]['name'], $products[1]['price'], $products[1]['quantity'] ) );
	}

	public function test_a_fully_refunded_order_has_nothing_to_invoice(): void {
		$item  = $this->item( array( 'id' => 11, 'subtotal' => 100.0, 'total' => 100.0 ) );
		$order = $this->order( array( $item ), 100.0 );
		$order->set_refunds( array( $this->refund( 5, -100.0, array( $this->refunded_item( 11, array( 'quantity' => -1.0, 'total' => -100.0 ) ) ) ) ) );

		$this->expectException( FullyRefundedException::class );
		$this->products( $order );
	}

	public function test_a_refunded_bundle_quantity_scales_its_components(): void {
		$container = $this->bundle( array( 'id' => 20, 'quantity' => 2.0, 'subtotal' => 200.0, 'total' => 200.0 ), 'cart-1' );
		$order     = $this->order(
			array(
				$container,
				$this->component( 'cart-1', 'A', '60', array( 'quantity' => 2.0 ) ),
				$this->component( 'cart-1', 'B', '40', array( 'quantity' => 2.0 ) ),
			),
			200.0
		);
		$order->set_refunds( array( $this->refund( 5, -100.0, array( $this->refunded_item( 20, array( 'quantity' => -1.0, 'total' => -100.0 ) ) ) ) ) );

		$products = $this->products( $order );

		$this->assertSame( array( 'A', 'B' ), array_column( $products, 'code' ) );
		$this->assertSame( array( 1.0, 1.0 ), array_column( $products, 'quantity' ) );
		$this->assertSame( array( 60.0, 40.0 ), array_column( $products, 'price' ) );
	}

	public function test_an_order_without_refunds_is_unchanged(): void {
		$item  = $this->item( array( 'id' => 11, 'quantity' => 2.0, 'subtotal' => 200.0, 'total' => 200.0 ) );
		$order = $this->order( array( $item ), 200.0 );

		$this->assertSame( array( 2.0 ), array_column( $this->products( $order ), 'quantity' ) );
		$this->assertSame( array(), $this->builder()->netted_refund_ids( $order, OrderMeta::TYPE_INVOICE, array(), PriorRefunds::for_order( $order ) ) );
	}

	public function test_netted_refunds_come_from_the_lines_or_the_referenced_aviz(): void {
		$order   = $this->partly_refunded_order();
		$refunds = PriorRefunds::for_order( $order );

		$this->assertSame( array( 81441 ), $this->builder()->netted_refund_ids( $order, OrderMeta::TYPE_INVOICE, array(), $refunds ) );

		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'link' ), 'https://example.test/aviz' );
		$this->assertSame( array(), $this->builder()->netted_refund_ids( $order, OrderMeta::TYPE_INVOICE, array(), $refunds ) );

		OrderMeta::record_netted_refunds( $order, OrderMeta::TYPE_NOTICE, array( 81441 ) );
		$this->assertSame( array( 81441 ), $this->builder()->netted_refund_ids( $order, OrderMeta::TYPE_INVOICE, array(), $refunds ) );
	}

	private function summary( array $line ): array {
		return array( $line['name'], $line['price'], $line['vatPercentage'], $line['quantity'], $line['productType'] );
	}
}
