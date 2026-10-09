<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Refund\RefundService;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Refund;

#[CoversClass( \FGSyncOblio\Refund\RefundService::class )]
final class RefundServiceTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		oblio_test_seed_vat_categories();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );
		$this->settings->set( 'series_invoice', 'FCT' );
	}

	private function service(): RefundService {
		$factory = new ClientFactory( $this->settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		return new RefundService( $this->settings, $factory, new OrderStore(), new Logger(), new LineItemMapper( $this->settings ), new ShippingFeeMapper(), new VatCategories( $factory, $this->settings, new Logger() ) );
	}

	private function queue_auth_and_storno_response(): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '2', 'link' => 'https://example.test/storno' ) ) ),
		);
	}

	/**
	 * Every real caller derives $order_id/$refund_id consistently by
	 * construction (Reconciler, queue payloads) - this guard protects the one
	 * caller that resolves them independently (ReturnsIntegration, from an
	 * external WC Returns event) from ever building a storno against a
	 * mismatched order/refund pair.
	 */
	public function test_issue_for_refund_refuses_a_refund_that_belongs_to_a_different_order(): void {
		$settings = new Settings();
		$settings->set( 'cif', 'RO123' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1]  = $order;
		$GLOBALS['oblio_test_orders'][99] = new WC_Order_Refund( 99, 2 );

		$factory = new ClientFactory( $settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		$service = new RefundService( $settings, $factory, new OrderStore(), new Logger(), new LineItemMapper( $settings ), new ShippingFeeMapper(), new VatCategories( $factory, $settings, new Logger() ) );

		$result = $service->issue_for_refund( 1, 99 );

		$this->assertNull( $result );
		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertStringContainsString( 'does not belong to order', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	/**
	 * A full refund (amount == order total) needs no product lines - Oblio
	 * reverses everything via referenceDocument.refund=1 - so this is the
	 * simplest real path through build_storno() end to end.
	 */
	public function test_issue_for_refund_succeeds_for_a_full_refund_and_persists_the_storno(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 100.0 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FCT' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '1' );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 100.0 );

		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][5] = $refund;
		$this->queue_auth_and_storno_response();

		$result = $this->service()->issue_for_refund( 1, 5, true );

		$this->assertNotNull( $result );
		$this->assertSame( 'FCT', $result->series_name );
		$this->assertNotSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_refund_5' ) );
	}

	/**
	 * The order lock must be released before the post-issue hook fires - a
	 * second call for the same order right after (which would block on the
	 * lock if it were still held) must return immediately with the
	 * already-persisted storno instead of hanging or throwing.
	 */
	public function test_issue_for_refund_releases_the_lock_before_returning(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 100.0 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FCT' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '1' );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 100.0 );

		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][5] = $refund;
		$this->queue_auth_and_storno_response();

		$service = $this->service();
		$service->issue_for_refund( 1, 5, true );

		// Second call: the guard_key set by the first call short-circuits
		// this before it would ever need the lock, but if the first call's
		// lock were still held, fail_fast=true would throw here instead.
		$second = $service->issue_for_refund( 1, 5, true );

		$this->assertNull( $second );
	}

	private function invoiced_order_with_netted_refund(): WC_Order {
		$order = new WC_Order( 1 );
		$order->set_total( 198.20 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FCT' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '1' );
		$netted = new WC_Order_Refund( 4, 1 );
		$netted->set_amount( -107.52 );
		$order->set_refunds( array( $netted ) );
		OrderMeta::record_netted_refunds( $order, OrderMeta::TYPE_INVOICE, array( 4 ) );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][4] = $netted;
		return $order;
	}

	public function test_a_refund_the_invoice_already_netted_gets_no_storno(): void {
		$order = $this->invoiced_order_with_netted_refund();

		$this->assertNull( $this->service()->issue_for_refund( 1, 4, true ) );
		$this->assertSame( array(), $GLOBALS['oblio_test_http_calls'] );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_refund_4' ) );
	}

	public function test_refunding_the_rest_of_a_netted_invoice_is_a_full_storno(): void {
		$order = $this->invoiced_order_with_netted_refund();
		$rest  = new WC_Order_Refund( 5, 1 );
		$rest->set_amount( -90.68 );
		$order->set_refunds( array( $order->get_refunds()[0], $rest ) );
		$GLOBALS['oblio_test_orders'][5] = $rest;
		$this->queue_auth_and_storno_response();

		$this->service()->issue_for_refund( 1, 5, true );

		$payload = $this->last_storno_payload();
		$this->assertSame( 1, $payload['referenceDocument']['refund'] );
		$this->assertArrayNotHasKey( 'products', $payload );
	}

	private function partial_storno_products( WC_Order $order, WC_Order_Refund $refund ): array {
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FCT' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '1' );
		$GLOBALS['oblio_test_orders'][ $order->get_id() ]  = $order;
		$GLOBALS['oblio_test_orders'][ $refund->get_id() ] = $refund;
		$this->queue_auth_and_storno_response();

		$this->service()->issue_for_refund( $order->get_id(), $refund->get_id(), true );

		return $this->last_storno_payload()['products'];
	}

	public function test_partial_storno_lines_use_package_product_type_and_document_currency(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes']           = 'yes';
		$GLOBALS['oblio_test_post_meta'][1]['oblio_fgwoo_package_number'] = '6';
		$GLOBALS['oblio_test_post_meta'][1]['oblio_fgwoo_product_type']   = 'Serviciu';
		$this->settings->set( 'oss_eur_currency', 'yes' );

		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );
		$order->set_billing( array( 'country' => 'DE' ) );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 157.3 );
		$refund->set_items(
			array(
				new \WC_Order_Item_Product(
					array( 'quantity' => -1.0, 'total' => -100.0, 'total_tax' => -21.0 ),
					new \WC_Product( array( 'sku' => 'SKU1' ) )
				),
			)
		);
		$refund->set_fees( array( new \WC_Order_Item_Fee( array( 'name' => 'Taxa ramburs', 'total' => -10.0, 'total_tax' => -2.1 ) ) ) );
		$refund->set_shipping_total( -20.0 );
		$refund->set_shipping_tax( -4.2 );

		$products = $this->partial_storno_products( $order, $refund );

		$this->assertSame(
			array(
				array(
					'name'                     => 'Test product',
					'code'                     => 'SKU1',
					'price'                    => 20.1667,
					'measuringUnit'            => 'buc',
					'measuringUnitTranslation' => '',
					'currency'                 => 'EUR',
					'vatName'                  => 'Normala',
					'vatPercentage'            => 21,
					'vatIncluded'              => true,
					'quantity'                 => -6,
					'productType'              => 'Serviciu',
					'management'               => '',
				),
				array(
					'name'                     => 'Taxa ramburs',
					'code'                     => '',
					'description'              => '',
					'price'                    => 12.1,
					'measuringUnit'            => 'buc',
					'measuringUnitTranslation' => '',
					'currency'                 => 'EUR',
					'vatName'                  => 'Normala',
					'vatPercentage'            => 21,
					'vatIncluded'              => true,
					'quantity'                 => -1,
					'productType'              => 'Serviciu',
				),
				array(
					'name'                     => 'Transport',
					'code'                     => '',
					'description'              => '',
					'price'                    => 24.2,
					'measuringUnit'            => 'buc',
					'measuringUnitTranslation' => '',
					'currency'                 => 'EUR',
					'vatName'                  => 'Normala',
					'vatPercentage'            => 21,
					'vatIncluded'              => true,
					'quantity'                 => -1,
					'productType'              => 'Serviciu',
				),
			),
			$products
		);
	}

	public function test_refunded_shipping_methods_keep_their_own_rates(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 23.2 );
		$refund->set_shipping_items(
			array(
				new \WC_Order_Item_Shipping( array( 'name' => 'Curier', 'total' => -10.0, 'total_tax' => -2.1 ) ),
				new \WC_Order_Item_Shipping( array( 'name' => 'Posta', 'total' => -10.0, 'total_tax' => -1.1 ) ),
			)
		);

		$products = $this->partial_storno_products( $order, $refund );

		$this->assertSame(
			array(
				array( 'Transport - Curier', 12.1, 21, -1 ),
				array( 'Transport - Posta', 11.1, 11, -1 ),
			),
			array_map( static fn ( array $line ): array => array( $line['name'], $line['price'], $line['vatPercentage'], $line['quantity'] ), $products )
		);
	}

	public function test_a_rounding_gap_against_the_refund_amount_adds_an_adjustment_line(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );
		$order->set_tax_items( array( new \WC_Order_Item_Tax( 1, 21.0, 69.42 ) ) );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 100.0 );
		$refund->set_items(
			array(
				new \WC_Order_Item_Product( array( 'quantity' => -3.0, 'total' => -82.64, 'total_tax' => -17.35 ) ),
				new \WC_Order_Item_Product( array( 'quantity' => -1.0, 'total' => 0.0, 'total_tax' => 0.0 ) ),
			)
		);

		$products = $this->partial_storno_products( $order, $refund );

		$this->assertCount( 2, $products );
		$this->assertSame( array( 33.33, -3 ), array( $products[0]['price'], $products[0]['quantity'] ) );
		$this->assertSame(
			array( 'Ajustare storno', 0.01, -1, 'Normala', 21 ),
			array( $products[1]['name'], $products[1]['price'], $products[1]['quantity'], $products[1]['vatName'], $products[1]['vatPercentage'] )
		);
	}

	/**
	 * An order invoiced at 19% before August 2025 is refunded after the rate
	 * table moved to 21%: the storno must still carry 19%.
	 */
	public function test_storno_of_a_19_percent_invoice_keeps_19_percent(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );
		$order->set_tax_items( array( new \WC_Order_Item_Tax( 4, 19.0 ) ) );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 119.0 );
		$refund->set_items(
			array(
				new \WC_Order_Item_Product( array( 'quantity' => -1.0, 'total' => -100.0, 'total_tax' => -19.0, 'taxes' => array( 4 => -19.0 ) ) ),
			)
		);

		$products = $this->partial_storno_products( $order, $refund );

		$this->assertSame( array( 'Veche', 19 ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_storno_line_mirrors_the_invoice_line_for_the_same_item(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes']           = 'yes';
		$GLOBALS['oblio_test_post_meta'][7]['oblio_fgwoo_package_number'] = '6';
		$GLOBALS['oblio_test_post_meta'][7]['oblio_fgwoo_product_type']   = 'Ambalaje';
		$this->settings->set( 'measuring_unit', 'cutie' );
		$this->settings->set( 'management', 'Depozit' );
		$product = new \WC_Product( array( 'sku' => 'BOX', 'regular_price' => '121', 'price' => '121' ) );
		$mapper  = new LineItemMapper( $this->settings );
		$ctx     = BuildContext::from_settings( $this->settings, 'RON' );

		$invoice_line = $mapper->map(
			$this->order_with_items( array( new \WC_Order_Item_Product( array( 'product_id' => 7, 'quantity' => 2.0, 'subtotal' => 200.0, 'subtotal_tax' => 42.0, 'total' => 200.0, 'total_tax' => 42.0 ), $product ) ) ),
			$ctx
		)['products'][0];
		$storno_line  = $mapper->storno_line(
			new \WC_Order_Item_Product( array( 'product_id' => 7, 'quantity' => -2.0, 'total' => -200.0, 'total_tax' => -42.0 ), $product ),
			$ctx
		);

		unset( $invoice_line['description'], $invoice_line['save'] );
		$invoice_line['quantity'] = -$invoice_line['quantity'];
		$this->assertSame( $invoice_line, $storno_line );
	}

	private function bundle_order(): WC_Order {
		$bundle = new \WC_Order_Item_Product(
			array( 'id' => 11, 'name' => 'Pachet 2 X Zeolit', 'quantity' => 2.0, 'subtotal' => 608.72, 'subtotal_tax' => 127.84, 'total' => 608.72, 'total_tax' => 127.84 ),
			new \WC_Product( array( 'type' => 'bundle', 'sku' => 'ZEO-x2' ) )
		);
		$bundle->add_meta_data( '_bundle_cart_key', 'cart-1' );
		$items = array( 11 => $bundle );
		foreach ( array( 12, 13 ) as $id ) {
			$component = new \WC_Order_Item_Product(
				array( 'id' => $id, 'name' => 'Zeolit', 'quantity' => 2.0 ),
				new \WC_Product( array( 'sku' => 'ZEO', 'regular_price' => '199' ) )
			);
			$component->add_meta_data( '_bundled_by', 'cart-1' );
			$items[ $id ] = $component;
		}

		$order = new WC_Order( 1 );
		$order->set_total( 736.56 );
		$order->set_items( $items );
		return $order;
	}

	private function bundle_refund( float $quantity, float $total, float $tax ): WC_Order_Refund {
		$item = new \WC_Order_Item_Product(
			array( 'name' => 'Pachet 2 X Zeolit', 'quantity' => $quantity, 'total' => $total, 'total_tax' => $tax ),
			new \WC_Product( array( 'type' => 'bundle', 'sku' => 'ZEO-x2' ) )
		);
		$item->add_meta_data( '_refunded_item_id', '11' );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( abs( $total + $tax ) );
		$refund->set_items( array( $item ) );
		return $refund;
	}

	public function test_a_refunded_fixed_price_bundle_is_stornoed_on_its_components(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

		$products = $this->partial_storno_products( $this->bundle_order(), $this->bundle_refund( -1.0, -304.36, -63.92 ) );

		$this->assertSame( array( 'ZEO', 'ZEO' ), array_column( $products, 'code' ) );
		$this->assertSame( array( 184.14, 184.14 ), array_column( $products, 'price' ) );
		$this->assertSame( array( -1, -1 ), array_column( $products, 'quantity' ) );
		$this->assertSame( array( 21, 21 ), array_column( $products, 'vatPercentage' ) );
	}

	public function test_a_bundle_refunded_by_value_only_keeps_one_unit_per_component(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

		$products = $this->partial_storno_products( $this->bundle_order(), $this->bundle_refund( 0.0, -100.0, -21.0 ) );

		$this->assertSame( array( 'ZEO', 'ZEO' ), array_column( $products, 'code' ) );
		$this->assertSame( array( 60.5, 60.5 ), array_column( $products, 'price' ) );
		$this->assertSame( array( -1, -1 ), array_column( $products, 'quantity' ) );
	}

	public function test_include_mode_stornos_the_bundle_line_itself(): void {
		$this->settings->set( 'bundle_line_mode', 'include' );

		$products = $this->partial_storno_products( $this->bundle_order(), $this->bundle_refund( -1.0, -304.36, -63.92 ) );

		$this->assertSame( array( 'ZEO-x2' ), array_column( $products, 'code' ) );
	}

	public function test_a_refunded_bundle_without_a_known_original_line_is_stornoed_as_is(): void {
		$refund = $this->bundle_refund( -1.0, -304.36, -63.92 );
		$refund->get_items()[0]->add_meta_data( '_refunded_item_id', '99' );

		$products = $this->partial_storno_products( $this->bundle_order(), $refund );

		$this->assertSame( array( 'ZEO-x2' ), array_column( $products, 'code' ) );
	}

	public function test_partial_storno_sends_the_document_language(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );
		$order->update_meta_data( 'wpml_language', 'en' );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 50.0 );

		$this->partial_storno_products( $order, $refund );

		$this->assertSame( 'EN', $this->last_storno_payload()['language'] );
	}

	/**
	 * @param array<int,\WC_Order_Item_Product> $items
	 */
	private function order_with_items( array $items ): WC_Order {
		$order = new WC_Order( 2 );
		$order->set_items( $items );
		return $order;
	}

	private function last_storno_payload(): array {
		foreach ( array_reverse( $GLOBALS['oblio_test_http_calls'] ) as $call ) {
			if ( str_contains( $call['url'], '/api/docs/invoice' ) ) {
				return json_decode( $call['args']['body'], true );
			}
		}
		$this->fail( 'No storno request was sent.' );
	}

	public function test_partial_storno_without_line_items_uses_an_amount_only_line(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 50.0 );
		$refund->set_reason( 'Goodwill' );

		$products = $this->partial_storno_products( $order, $refund );

		$this->assertCount( 1, $products );
		$this->assertSame( 'Goodwill', $products[0]['name'] );
		$this->assertSame( 50, $products[0]['price'] );
		$this->assertSame( -1, $products[0]['quantity'] );
		$this->assertSame( '', $products[0]['vatName'] );
		$this->assertNull( $products[0]['vatPercentage'] );
	}

	/**
	 * @return array<string,array{0:array<int,\WC_Order_Item_Tax>,1:string,2:int|float}>
	 */
	public static function amount_only_vat_cases(): array {
		return array(
			'19% order keeps 19%'           => array( array( new \WC_Order_Item_Tax( 1, 19.0, 63.87 ) ), 'Veche', 19 ),
			'untaxed order uses the setting' => array( array(), 'Scutita', 0 ),
		);
	}

	#[DataProvider( 'amount_only_vat_cases' )]
	public function test_refund_by_amount_uses_the_orders_own_vat( array $tax_items, string $vat_name, $percent ): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';
		$this->settings->set( 'vat_untaxed_category', 'Scutita' );
		$order = new WC_Order( 1 );
		$order->set_total( 400.0 );
		$order->set_tax_items( $tax_items );

		$refund = new WC_Order_Refund( 5, 1 );
		$refund->set_amount( 50.0 );

		$products = $this->partial_storno_products( $order, $refund );

		$this->assertSame( array( $vat_name, $percent ), array( $products[0]['vatName'], $products[0]['vatPercentage'] ) );
	}

	public function test_no_adjustment_when_lines_match_the_refund(): void {
		$this->assertNull( RefundService::storno_adjustment( 100.0, 100.0 ) );
	}

	public function test_no_adjustment_for_sub_bani_rounding(): void {
		$this->assertNull( RefundService::storno_adjustment( 100.0, 99.996 ) );
	}

	public function test_shortfall_adds_a_minus_line(): void {
		$adjustment = RefundService::storno_adjustment( 100.0, 90.0 );
		$this->assertSame( array( 'price' => 10.0, 'quantity' => -1 ), $adjustment );
	}

	public function test_overshoot_trims_with_a_plus_line(): void {
		$adjustment = RefundService::storno_adjustment( 90.0, 100.0 );
		$this->assertSame( array( 'price' => 10.0, 'quantity' => 1 ), $adjustment );
	}

	public function test_adjustment_makes_the_total_exact(): void {
		$target     = 123.45;
		$magnitude  = 120.00;
		$adjustment = RefundService::storno_adjustment( $target, $magnitude );
		$this->assertNotNull( $adjustment );

		$reconciled = $magnitude + $adjustment['price'] * ( $adjustment['quantity'] < 0 ? 1 : -1 );
		$this->assertEqualsWithDelta( $target, $reconciled, 0.001 );
	}
}
