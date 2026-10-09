<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\BulkActions;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( BulkActions::class )]
final class BulkActionsTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_bulk_proforma_skips_orders_paid_online(): void {
		$paid = new WC_Order( 1 );
		$paid->set_payment_method( 'stripe' );
		$paid->date_paid = new \DateTimeImmutable( '2026-10-08' );
		$cod             = new WC_Order( 2 );
		$cod->set_payment_method( 'cod' );
		$GLOBALS['oblio_test_orders'][1] = $paid;
		$GLOBALS['oblio_test_orders'][2] = $cod;

		$redirect = ( new BulkActions( new Settings(), new Scheduler(), new OrderStore(), new Logger() ) )->handle( 'https://example.test/orders', 'oblio_fgwoo_issue_proforma', array( 1, 2 ) );

		$this->assertStringContainsString( 'oblio_fgwoo_queued=1', $redirect );
		$this->assertStringContainsString( 'oblio_fgwoo_skipped=1', $redirect );
	}
}
