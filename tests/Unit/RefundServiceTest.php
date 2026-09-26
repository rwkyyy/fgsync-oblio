<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Refund\RefundService;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Refund;

#[CoversClass( \FGSyncOblio\Refund\RefundService::class )]
final class RefundServiceTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );
		$this->settings->set( 'series_invoice', 'FCT' );
	}

	private function service(): RefundService {
		$factory = new ClientFactory( $this->settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		return new RefundService( $this->settings, $factory, new OrderStore(), new Logger() );
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
		$service = new RefundService( $settings, $factory, new OrderStore(), new Logger() );

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
