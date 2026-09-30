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

	/**
	 * A stale '1' left behind after the invoice is deleted would keep this
	 * order wrongly excluded from stock reservations forever (see
	 * StockReservations::build()'s NOT EXISTS on this exact meta key).
	 */
	public function test_clearing_an_invoice_also_clears_the_use_stock_flag(): void {
		$order = new WC_Order();
		OrderMeta::record_invoice_stock_usage( $order, true );

		OrderMeta::clear( $order, OrderMeta::TYPE_INVOICE );

		$this->assertFalse( OrderMeta::invoice_used_stock( $order ) );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_invoice_use_stock' ) );
	}

	/**
	 * The use_stock flag only ever exists under the invoice key - clearing a
	 * different document type must not touch it.
	 */
	public function test_clearing_a_proforma_does_not_touch_the_invoice_use_stock_flag(): void {
		$order = new WC_Order();
		OrderMeta::record_invoice_stock_usage( $order, true );

		OrderMeta::clear( $order, OrderMeta::TYPE_PROFORMA );

		$this->assertTrue( OrderMeta::invoice_used_stock( $order ) );
	}
}
