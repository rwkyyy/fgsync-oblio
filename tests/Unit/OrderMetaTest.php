<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Order\OrderMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Order\OrderMeta::class )]
final class OrderMetaTest extends TestCase {

	public function test_fresh_order_is_not_marked_as_stock_discharging(): void {
		$this->assertFalse( OrderMeta::invoice_used_stock( new WC_Order() ) );
	}

	public function test_records_and_reads_use_stock_true(): void {
		$order = new WC_Order();
		OrderMeta::record_invoice_stock_usage( $order, true );
		$this->assertTrue( OrderMeta::invoice_used_stock( $order ) );
	}

	public function test_records_use_stock_false_as_not_discharging(): void {
		$order = new WC_Order();
		OrderMeta::record_invoice_stock_usage( $order, false );
		$this->assertFalse( OrderMeta::invoice_used_stock( $order ) );
	}

	public function test_stores_flag_under_the_invoice_use_stock_key(): void {
		$order = new WC_Order();
		OrderMeta::record_invoice_stock_usage( $order, true );
		$this->assertSame( '1', $order->get_meta( 'oblio_fgwoo_invoice_use_stock' ) );
	}
}
