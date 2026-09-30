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
	 * A flat lookback window alone would have the oldest-first query return
	 * the exact same BATCH every run forever once a backlog exceeds it - the
	 * persisted cursor must start each query from where the last run left off.
	 */
	public function test_invoice_reconciliation_query_starts_from_the_persisted_cursor(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$cursor = time() - 10;
		update_option( 'oblio_fgwoo_reconcile_invoice_cursor', $cursor );

		$this->reconciler->run();

		$call = $GLOBALS['oblio_test_wc_orders_calls'][0];
		$this->assertSame( '>' . $cursor, $call['date_created'] );
	}

	/**
	 * A full BATCH means more records may still be waiting past this page -
	 * the cursor must advance to the latest examined date so the next run
	 * continues instead of re-fetching the same oldest page again.
	 */
	public function test_invoice_reconciliation_cursor_advances_past_a_full_batch(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$base = time() - 1000;
		$ids  = range( 1, 100 );
		$GLOBALS['oblio_test_wc_orders_result'] = $ids;
		foreach ( $ids as $offset => $id ) {
			$order = new WC_Order( $id );
			$order->set_date_created( new \DateTime( '@' . ( $base + $offset ) ) );
			$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
			$GLOBALS['oblio_test_orders'][ $id ] = $order;
		}

		$this->reconciler->run();

		$this->assertSame( $base + 99, (int) get_option( 'oblio_fgwoo_reconcile_invoice_cursor' ) );
	}

	/**
	 * Fewer than BATCH results means the whole lookback window has been
	 * covered - the cursor must reset so the next run starts a fresh pass
	 * (otherwise a record stuck at the very front, still eligible after a
	 * failed retry, would never be re-examined again).
	 */
	public function test_invoice_reconciliation_cursor_resets_on_a_short_batch(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		update_option( 'oblio_fgwoo_reconcile_invoice_cursor', 5000 );
		$GLOBALS['oblio_test_wc_orders_result'] = array();

		$this->reconciler->run();

		$this->assertFalse( get_option( 'oblio_fgwoo_reconcile_invoice_cursor', false ) );
	}

	/**
	 * Same starvation risk as invoices - a backlog of unhandled refunds bigger
	 * than one BATCH must not pin the query to the same oldest page forever.
	 */
	public function test_storno_reconciliation_query_starts_from_the_persisted_cursor(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$cursor = time() - 10;
		update_option( 'oblio_fgwoo_reconcile_storno_cursor', $cursor );

		$this->reconciler->run();

		$call = $GLOBALS['oblio_test_wc_orders_calls'][0];
		$this->assertSame( '>' . $cursor, $call['date_created'] );
	}

	public function test_storno_reconciliation_cursor_advances_past_a_full_batch(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		$base = time() - 1000;
		$ids  = range( 1, 100 );
		$GLOBALS['oblio_test_wc_orders_result'] = $ids;
		foreach ( $ids as $offset => $id ) {
			$refund = new WC_Order_Refund( $id, 0 );
			$refund->set_date_created( new \DateTime( '@' . ( $base + $offset ) ) );
			$GLOBALS['oblio_test_orders'][ $id ] = $refund;
		}

		$this->reconciler->run();

		$this->assertSame( $base + 99, (int) get_option( 'oblio_fgwoo_reconcile_storno_cursor' ) );
	}

	public function test_storno_reconciliation_cursor_resets_on_a_short_batch(): void {
		$this->settings->set( 'storno_autogen', 'yes' );
		update_option( 'oblio_fgwoo_reconcile_storno_cursor', 5000 );
		$GLOBALS['oblio_test_wc_orders_result'] = array();

		$this->reconciler->run();

		$this->assertFalse( get_option( 'oblio_fgwoo_reconcile_storno_cursor', false ) );
	}
}
