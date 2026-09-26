<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Document\DocumentService;
use FGSyncOblio\Document\InvoiceBuilder;
use FGSyncOblio\Document\InvoiceEmailer;
use FGSyncOblio\Document\LifecyclePolicy;
use FGSyncOblio\Document\Mapper\ClientMapper;
use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

#[CoversClass( \FGSyncOblio\Document\DocumentService::class )]
final class DocumentServiceTest extends TestCase {

	private Settings $settings;

	private Logger $logger;

	protected function setUp(): void {
		oblio_test_reset();

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
			new CollectMapper( $this->settings )
		);

		return new DocumentService(
			$this->settings,
			$factory,
			$builder,
			new LifecyclePolicy( $this->settings ),
			new InvoiceEmailer( $this->settings, $this->logger ),
			$this->logger
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
