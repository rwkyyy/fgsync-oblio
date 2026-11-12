<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\DocumentService;
use FGSyncOblio\Document\InvoiceBuilder;
use FGSyncOblio\Document\InvoiceEmailer;
use FGSyncOblio\Document\LifecyclePolicy;
use FGSyncOblio\Document\Mapper\ClientMapper;
use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Product;

#[CoversClass( \FGSyncOblio\Document\DocumentService::class )]
final class DocumentServiceTest extends TestCase {

	private Settings $settings;

	private Logger $logger;

	protected function setUp(): void {
		oblio_test_reset();
		oblio_test_seed_vat_categories();

		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );
		$this->settings->set( 'series_invoice', 'FCT' );

		$this->logger = new Logger();
	}

	private function service(): DocumentService {
		$factory = new ClientFactory( $this->settings, new Encryption(), $this->logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );

		$builder = new InvoiceBuilder(
			$this->settings,
			new ClientMapper(),
			new LineItemMapper( $this->settings ),
			new ShippingFeeMapper(),
			new CollectMapper( $this->settings ),
			new VatCategories( $factory, $this->settings, $this->logger )
		);

		return new DocumentService(
			$this->settings,
			$factory,
			$builder,
			new LifecyclePolicy( $this->settings ),
			new InvoiceEmailer( $this->settings, $this->logger ),
			$this->logger,
			new OrderStore()
		);
	}

	/**
	 * A single line item whose total matches the order total, so
	 * InvoiceBuilder::build() succeeds without adding an adjustment line or
	 * throwing the "order total is 0.00" guard - what matters for these
	 * tests is DocumentService's own orchestration, not the invoice content.
	 */
	private function order_with_one_line_item( int $id ): WC_Order {
		$order = new WC_Order( $id );
		$order->set_total( 100.0 );
		$order->set_items(
			array(
				new WC_Order_Item_Product(
					array(
						'name'      => 'Widget',
						'quantity'  => 1.0,
						'total'     => 100.0,
						'total_tax' => 0.0,
						'subtotal'  => 100.0,
					),
					new WC_Product( array( 'sku' => 'WIDGET-1', 'regular_price' => '100.00', 'price' => '100.00' ) )
				),
			)
		);
		return $order;
	}

	public function test_issue_succeeds_and_persists_the_document(): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc' ) ) ),
		);

		$result = $this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		$this->assertSame( 'FCT', $result->series_name );
		$this->assertSame( 'https://example.test/doc', $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ) ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function respond( array $data ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $data ),
		);
	}

	/**
	 * The old plugin sent the document's key with every delete, and a new
	 * key keeps Oblio from answering the reissue with the deleted document.
	 */
	public function test_a_document_issued_again_after_a_delete_gets_a_new_key(): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc/1' ) ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc/2' ) ) );

		$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );
		$this->service()->delete( $order, OrderMeta::TYPE_INVOICE );
		$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		$calls = array_values( array_filter( $GLOBALS['oblio_test_http_calls'], static fn ( array $call ): bool => str_contains( (string) $call['url'], '/api/docs/invoice' ) ) );
		$this->assertCount( 3, $calls );
		$first_key = json_decode( (string) $calls[0]['args']['body'], true )['idempotencyKey'];
		parse_str( (string) parse_url( (string) $calls[1]['url'], PHP_URL_QUERY ), $delete_query );
		$second_key = json_decode( (string) $calls[2]['args']['body'], true )['idempotencyKey'];

		$this->assertSame( 'DELETE', $calls[1]['args']['method'] );
		$this->assertSame( $first_key, $delete_query['idempotencyKey'] );
		$this->assertNotSame( $first_key, $second_key );
		$this->assertStringStartsWith( $first_key . '-r', $second_key );
	}

	/**
	 * @param array<string,string> $fields Invoice meta field => value, as the meta table holds it.
	 */
	private function stored_invoice( array $fields ): void {
		$rows = array();
		foreach ( $fields as $field => $value ) {
			$rows[] = array(
				'meta_key'   => OrderMeta::key( OrderMeta::TYPE_INVOICE, $field ),
				'meta_value' => $value,
			);
		}
		$GLOBALS['wpdb']->next_get_results_return = $rows;
	}

	/**
	 * The copy of the order loaded earlier in the same request has no invoice, but another request just saved one.
	 */
	public function test_an_invoice_saved_by_another_request_is_returned_instead_of_issuing_a_second_one(): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->stored_invoice( array( 'series' => 'FV', 'number' => '10741', 'link' => 'https://example.test/fv10741' ) );

		$result = $this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		$this->assertSame( '10741', $result->number );
		$this->assertSame( array(), $GLOBALS['oblio_test_http_calls'] );
	}

	public function test_delete_targets_the_invoice_the_database_holds(): void {
		$order = $this->order_with_one_line_item( 1 );
		foreach ( array( 'series' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc/1' ) as $field => $value ) {
			$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, $field ), $value );
			$order->meta_ids[ OrderMeta::key( OrderMeta::TYPE_INVOICE, $field ) ] = 1;
		}
		$this->stored_invoice( array( 'series' => 'FCT', 'number' => '2', 'link' => 'https://example.test/doc/2' ) );
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array() ) );

		$this->assertTrue( $this->service()->delete( $order, OrderMeta::TYPE_INVOICE ) );

		$delete = end( $GLOBALS['oblio_test_http_calls'] );
		parse_str( (string) parse_url( (string) $delete['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'DELETE', $delete['args']['method'] );
		$this->assertSame( '2', $query['number'] );
	}

	public function test_issue_records_the_refunds_the_invoice_netted(): void {
		$order = $this->order_with_one_line_item( 1 );
		$order->set_total( 150.0 );
		$refund = new WC_Order_Refund( 7, 1 );
		$refund->set_amount( -50.0 );
		$order->set_refunds( array( $refund ) );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc' ) ) ),
		);

		$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		$this->assertSame( array( 7 ), OrderMeta::netted_refunds( $order, OrderMeta::TYPE_INVOICE ) );
	}

	/**
	 * The lock must be released before the post-issue hook/email run - a slow
	 * or throwing callback there must not extend lock contention or corrupt
	 * an already-successful issuance. Registering a hook here would require a
	 * real do_action() dispatcher (this bootstrap's is a no-op), so this
	 * instead confirms the OBSERVABLE contract: a second issue() call for the
	 * same order+type (which would block on the lock if it were still held)
	 * succeeds immediately and returns the already-persisted document.
	 */
	public function test_issue_releases_the_lock_before_returning(): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc' ) ) ),
		);

		$service = $this->service();
		$service->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		// A second call with fail_fast=true would throw immediately if the
		// lock were still held - it isn't, so this just returns the existing
		// document instead.
		$second = $service->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		$this->assertSame( 'FCT', $second->series_name );
	}

	public function test_stock_deduction_is_skipped_when_the_account_has_no_warehouses(): void {
		set_transient( 'oblio_fgwoo_management', array(), WEEK_IN_SECONDS );
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc' ) ) ),
		);

		$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array( 'use_stock' => true ), true );

		$payload = json_decode( (string) end( $GLOBALS['oblio_test_http_calls'] )['args']['body'], true );
		$this->assertSame( 0, $payload['useStock'] );
		$this->assertFalse( OrderMeta::invoice_used_stock( $order ) );
	}

	private function respond_error( string $message ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 400 ),
			'body'     => wp_json_encode( array( 'statusMessage' => $message ) ),
		);
	}

	/**
	 * The old plugin saved its „Selecteaza” dropdown entry as a real value.
	 */
	public function test_the_old_plugins_placeholder_is_not_sent_as_a_warehouse(): void {
		update_option( 'oblio_fgwoo_workstation', 'Selecteaza' );
		update_option( 'oblio_fgwoo_management', 'Selecteaza' );
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc' ) ) );

		$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );

		$payload = json_decode( (string) end( $GLOBALS['oblio_test_http_calls'] )['args']['body'], true );
		$this->assertSame( '', $payload['workStation'] );
		$this->assertSame( '', $payload['products'][0]['management'] );
	}

	/**
	 * Oblio answers „Activeaza Stocuri!” when a company without stock is
	 * asked to discharge it (damarin, 2026-11-12).
	 */
	public function test_a_stock_discharge_on_a_company_without_stock_explains_itself_and_the_retry_works(): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond_error( 'Activeaza Stocuri!' );
		$this->respond( array( 'data' => array( 'seriesName' => 'FCT', 'number' => '1', 'link' => 'https://example.test/doc' ) ) );

		try {
			$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array( 'use_stock' => true ), true );
			$this->fail( 'Expected a DocumentException.' );
		} catch ( DocumentException $exception ) {
			$this->assertStringContainsString( 'Nu se poate emite cu descărcare de stoc', $exception->getMessage() );
		}
		$this->assertFalse( NomenclatureCache::uses_stock() );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ) ) );

		$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array( 'use_stock' => true ), true );

		$payload = json_decode( (string) end( $GLOBALS['oblio_test_http_calls'] )['args']['body'], true );
		$this->assertSame( 0, $payload['useStock'] );
		$this->assertSame( 'https://example.test/doc', $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ) ) );
	}

	/**
	 * @return array<string,array{0:bool,1:string}>
	 */
	public static function other_rejections(): array {
		return array(
			'another 400 with stock'     => array( true, 'Nu am putut descarca gestiunea' ),
			'the same text without stock' => array( false, 'Activeaza Stocuri!' ),
		);
	}

	#[DataProvider( 'other_rejections' )]
	public function test_other_rejections_keep_oblios_message_and_the_stock_flag( bool $use_stock, string $message ): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond_error( $message );

		try {
			$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array( 'use_stock' => $use_stock ), true );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( $message, $exception->status_message() );
		}
		$this->assertNull( NomenclatureCache::uses_stock() );
	}

	public function test_a_retryable_api_failure_throws_and_leaves_nothing_persisted(): void {
		$order = $this->order_with_one_line_item( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 500 ),
			'body'     => wp_json_encode( array( 'statusMessage' => 'Server error' ) ),
		);

		$this->expectException( \FGSyncOblio\Api\Exception\ApiException::class );

		try {
			$this->service()->issue( $order, OrderMeta::TYPE_INVOICE, array(), true );
		} finally {
			$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ) ) );
		}
	}
}
