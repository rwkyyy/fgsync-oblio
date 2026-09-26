<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Refund\RefundAutoIssue;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Refund\RefundAutoIssue::class )]
final class RefundAutoIssueTest extends TestCase {

	private RefundAutoIssue $auto_issue;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'storno_autogen', 'yes' );
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );

		$this->auto_issue = new RefundAutoIssue( $this->settings, new Scheduler(), new OrderStore(), new Logger() );
	}

	/**
	 * The invoice may still be queued (not yet issued) when the refund fires -
	 * the storno must still be enqueued so GenerateRefund's own retry loop can
	 * pick it up once the invoice exists, instead of this dropping it here.
	 */
	public function test_enqueues_the_storno_even_without_an_invoice_yet(): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->auto_issue->on_refunded( 1, 5 );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_does_nothing_when_storno_autogen_is_disabled(): void {
		$this->settings->set( 'storno_autogen', 'no' );
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->auto_issue->on_refunded( 1, 5 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_does_nothing_when_the_order_does_not_exist(): void {
		$this->auto_issue->on_refunded( 999, 5 );

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_logs_when_scheduling_fails(): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		$GLOBALS['oblio_test_as_fail']    = true;

		$this->auto_issue->on_refunded( 1, 5 );

		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
	}

	/**
	 * enqueue_*() returns false both when scheduling genuinely fails and
	 * when the storno is already queued (dedup) - an ordinary duplicate
	 * refund-created event must not be logged as an error.
	 */
	public function test_does_not_log_when_the_storno_is_already_pending(): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		( new Scheduler() )->enqueue_refund( 1, 5 );

		$this->auto_issue->on_refunded( 1, 5 );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}
}
