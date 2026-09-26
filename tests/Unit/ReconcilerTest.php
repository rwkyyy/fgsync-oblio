<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Reconciler;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Refund;

#[CoversClass( \FGSyncOblio\Queue\Reconciler::class )]
final class ReconcilerTest extends TestCase {

	private Reconciler $reconciler;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );

		$this->reconciler = new Reconciler( $this->settings, new Scheduler(), new OrderStore(), new Logger() );
	}

	public function test_invoice_reconciliation_is_skipped_when_autogen_is_disabled(): void {
		$this->settings->set( 'invoice_autogen', 'no' );

		$this->reconciler->run();

		$this->assertCount( 0, $GLOBALS['oblio_test_wc_orders_calls'] );
	}

	/**
	 * A transient, exhausted failure must stay eligible - only a permanent
	 * one is excluded (see GenerateDocument::fail()).
	 */
	public function test_invoice_reconciliation_excludes_permanent_failures_not_the_generic_flag(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );

		$this->reconciler->run();

		$invoice_call = $GLOBALS['oblio_test_wc_orders_calls'][0];
		$keys         = array();
		foreach ( $invoice_call['meta_query'] as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) ) {
				$keys[] = $clause['key'];
			}
		}
		$this->assertContains( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ), $keys );
		$this->assertNotContains( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ), $keys );
	}

	public function test_invoice_reconciliation_requeues_orders_still_missing_an_invoice(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		$GLOBALS['oblio_test_wc_orders_result'] = array( 1 );

		$this->reconciler->run();

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_invoice_reconciliation_skips_orders_that_already_have_an_invoice(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_wc_orders_result'] = array( 1 );

		$this->reconciler->run();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_storno_reconciliation_is_skipped_when_autogen_is_disabled(): void {
		$this->settings->set( 'storno_autogen', 'no' );

		$this->reconciler->run();

		// Only the invoice-reconciliation call (also disabled by default), none for stornos.
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_orders_calls'] );
	}

	public function test_storno_reconciliation_requeues_a_refund_whose_order_has_an_invoice(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][5] = new WC_Order_Refund( 5, 1 );
		$GLOBALS['oblio_test_wc_orders_result'] = array( 5 );

		$this->reconciler->run();

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_storno_reconciliation_skips_a_refund_whose_order_has_no_invoice_yet(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][5] = new WC_Order_Refund( 5, 1 );
		$GLOBALS['oblio_test_wc_orders_result'] = array( 5 );

		$this->reconciler->run();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_storno_reconciliation_skips_an_already_stornoed_refund(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( 'oblio_fgwoo_storno_refund_5', '{"series":"S","number":"1"}' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][5] = new WC_Order_Refund( 5, 1 );
		$GLOBALS['oblio_test_wc_orders_result'] = array( 5 );

		$this->reconciler->run();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_storno_reconciliation_skips_a_permanently_failed_refund(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_permanent_5', '1' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][5] = new WC_Order_Refund( 5, 1 );
		$GLOBALS['oblio_test_wc_orders_result'] = array( 5 );

		$this->reconciler->run();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	/**
	 * A full storno (issued via the manual/bulk "full storno" action, which
	 * has no refund_id of its own) already reverses the whole order - any
	 * individual WC refund record on that same order must not keep getting
	 * requeued forever just because its own per-refund guard was never set.
	 */
	public function test_storno_reconciliation_skips_a_refund_when_the_order_already_has_a_full_storno(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' ), '2026-01-01 00:00:00' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_orders'][5] = new WC_Order_Refund( 5, 1 );
		$GLOBALS['oblio_test_wc_orders_result'] = array( 5 );

		$this->reconciler->run();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	/**
	 * A single oldest-BATCH page fetched forever would leave any refund past
	 * the first 100 in the lookback window permanently unexamined - a full
	 * first page must trigger a second page request.
	 */
	public function test_storno_reconciliation_pages_past_a_full_first_page(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$GLOBALS['oblio_test_wc_orders_result_queue'] = array(
			range( 1000, 1099 ),
			array( 2000 ),
		);

		$this->reconciler->run();

		$this->assertCount( 2, $GLOBALS['oblio_test_wc_orders_calls'] );
		$this->assertSame( 1, $GLOBALS['oblio_test_wc_orders_calls'][0]['paged'] );
		$this->assertSame( 2, $GLOBALS['oblio_test_wc_orders_calls'][1]['paged'] );
	}

	/**
	 * A pathological backlog (every page full) must not turn one reconcile
	 * run into an unbounded scan - paging stops at the configured cap.
	 */
	public function test_storno_reconciliation_stops_paging_at_the_configured_max(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$GLOBALS['oblio_test_filter_overrides']['oblio_fgwoo_reconcile_storno_max_pages'] = 3;
		$GLOBALS['oblio_test_wc_orders_result'] = range( 4000, 4099 );

		$this->reconciler->run();

		$this->assertCount( 3, $GLOBALS['oblio_test_wc_orders_calls'] );
	}
}
