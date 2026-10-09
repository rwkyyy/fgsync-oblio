<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\AutoIssue;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class NeverCalledDocumentIssuer implements DocumentIssuer {

	public function issue( WC_Order $order, string $doc_type, array $options = array(), bool $fail_fast = false ): DocumentResult {
		throw new \RuntimeException( 'DocumentIssuer::issue() should not have been called (event mode never issues inline).' );
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		throw new \RuntimeException( 'DocumentIssuer::delete() should not have been called.' );
	}
}

#[CoversClass( \FGSyncOblio\Queue\AutoIssue::class )]
final class AutoIssueTest extends TestCase {

	private AutoIssue $auto_issue;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );

		$this->auto_issue = new AutoIssue(
			$this->settings,
			new Scheduler(),
			new OrderStore(),
			new NeverCalledDocumentIssuer(),
			new RateLimiter( new InMemorySlotStore() ),
			new Logger()
		);
	}

	public function test_invoice_auto_issue_logs_when_scheduling_fails(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$GLOBALS['oblio_test_as_fail'] = true;

		$this->auto_issue->on_status_changed( 1, 'processing', 'completed', new WC_Order( 1 ) );

		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertStringContainsString( 'invoice', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	public function test_invoice_auto_issue_does_not_log_when_scheduling_succeeds(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );

		$this->auto_issue->on_status_changed( 1, 'processing', 'completed', new WC_Order( 1 ) );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}

	/**
	 * enqueue_*() returns false both when scheduling genuinely fails and
	 * when the document is already queued (dedup) - an ordinary duplicate
	 * trigger (e.g. two near-simultaneous status changes) must not be
	 * logged as an error.
	 */
	public function test_invoice_auto_issue_does_not_log_when_the_invoice_is_already_pending(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		( new Scheduler() )->enqueue_document( 1, OrderMeta::TYPE_INVOICE );

		$this->auto_issue->on_status_changed( 1, 'processing', 'completed', new WC_Order( 1 ) );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}

	public function test_a_completed_online_payment_queues_the_invoice(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$this->settings->set( 'invoice_on_payment', 'yes' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->auto_issue->on_payment_complete( 1 );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_payment_does_nothing_unless_the_option_is_on(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->auto_issue->on_payment_complete( 1 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_payment_does_nothing_in_scheduled_mode(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$this->settings->set( 'invoice_on_payment', 'yes' );
		$this->settings->set( 'invoice_generation', 'batch' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->auto_issue->on_payment_complete( 1 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_payment_skips_an_order_that_already_has_an_invoice(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$this->settings->set( 'invoice_on_payment', 'yes' );
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/doc' );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->auto_issue->on_payment_complete( 1 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_no_automatic_proforma_for_an_order_paid_online(): void {
		$this->settings->set( 'proforma_autogen', 'yes' );
		$this->settings->set( 'proforma_on_received', 'yes' );
		$order = new WC_Order( 1 );
		$order->set_payment_method( 'stripe' );
		$order->date_paid                = new \DateTimeImmutable( '2026-10-08' );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->auto_issue->on_thankyou( 1 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_proforma_auto_issue_logs_when_scheduling_fails(): void {
		$this->settings->set( 'proforma_autogen', 'yes' );
		$this->settings->set( 'proforma_on_received', 'no' );
		$this->settings->set( 'proforma_autogen_statuses', array( 'processing' ) );
		$GLOBALS['oblio_test_as_fail'] = true;

		$this->auto_issue->on_status_changed( 1, 'pending', 'processing', new WC_Order( 1 ) );

		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertStringContainsString( 'proforma', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	public function test_on_thankyou_proforma_logs_when_scheduling_fails(): void {
		$this->settings->set( 'proforma_autogen', 'yes' );
		$this->settings->set( 'proforma_on_received', 'yes' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		$GLOBALS['oblio_test_as_fail']    = true;

		$this->auto_issue->on_thankyou( 1 );

		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertStringContainsString( 'proforma', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	public function test_on_thankyou_does_not_log_when_the_proforma_is_already_pending(): void {
		$this->settings->set( 'proforma_autogen', 'yes' );
		$this->settings->set( 'proforma_on_received', 'yes' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		( new Scheduler() )->enqueue_document( 1, OrderMeta::TYPE_PROFORMA );

		$this->auto_issue->on_thankyou( 1 );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}

	public function test_on_thankyou_does_nothing_when_the_order_does_not_exist(): void {
		$this->settings->set( 'proforma_autogen', 'yes' );
		$this->settings->set( 'proforma_on_received', 'yes' );

		$this->auto_issue->on_thankyou( 999 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}
}
