<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Order\OrderMeta::class )]
final class OrderMetaTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	/**
	 * Unknown state (nothing in this series has been issued since the
	 * latest-number tracking was added, e.g. a fresh series or pre-migration
	 * legacy data) falls back to "assume last" - Oblio's own API is the real
	 * guard against an invalid delete, so this only ever risks a rejected
	 * attempt, never an unsafe one.
	 */
	public function test_is_last_document_assumes_true_when_the_series_has_no_tracked_latest_number(): void {
		$order = new WC_Order();
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'series' ), 'AV' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'number' ), '42' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'link' ), 'https://example.test/av-42' );

		$this->assertTrue( OrderMeta::is_last_document( $order, OrderMeta::TYPE_NOTICE ) );
	}

	/**
	 * save() bumps a per-series/type running high-water mark - is_last_document()
	 * reads it back as an O(1) comparison instead of a live "does any order have
	 * a higher number" query (which took down an order screen on a large store).
	 */
	public function test_save_bumps_the_latest_number_and_is_last_document_compares_against_it(): void {
		$newest = new WC_Order();
		OrderMeta::save( $newest, new DocumentResult( OrderMeta::TYPE_INVOICE, 'FV', '100', 'https://example.test/fv-100' ) );

		$older = new WC_Order();
		$older->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FV' );
		$older->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '99' );
		$older->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/fv-99' );

		$this->assertTrue( OrderMeta::is_last_document( $newest, OrderMeta::TYPE_INVOICE ) );
		$this->assertFalse( OrderMeta::is_last_document( $older, OrderMeta::TYPE_INVOICE ) );
	}

	/**
	 * A lower number saved after a higher one (e.g. a different order's
	 * issuance completing out of order) must not drag the cached max down.
	 */
	public function test_bumping_a_lower_number_after_a_higher_one_does_not_lower_the_cache(): void {
		$order = new WC_Order();
		OrderMeta::save( $order, new DocumentResult( OrderMeta::TYPE_INVOICE, 'FV', '100', 'https://example.test/fv-100' ) );
		OrderMeta::save( $order, new DocumentResult( OrderMeta::TYPE_INVOICE, 'FV', '50', 'https://example.test/fv-50' ) );

		$this->assertSame( 100, (int) get_option( 'oblio_fgwoo_latest_number_invoice_fv' ) );
	}

	/**
	 * Deleting is only ever allowed on the series' true latest number, so the
	 * cache is now stale - clear() resets it to "unknown" instead of guessing,
	 * sending is_last_document() back to its conservative default rather than
	 * wrongly blocking the real new-latest order's own future delete.
	 */
	public function test_clear_resets_the_latest_number_cache_for_that_series(): void {
		$order = new WC_Order();
		OrderMeta::save( $order, new DocumentResult( OrderMeta::TYPE_INVOICE, 'FV', '100', 'https://example.test/fv-100' ) );

		OrderMeta::clear( $order, OrderMeta::TYPE_INVOICE );

		$this->assertFalse( get_option( 'oblio_fgwoo_latest_number_invoice_fv' ) );
	}

	public function test_netted_refunds_are_recorded_and_cleared_with_the_document(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_7', 'Factura nu a fost emisă în timp util.' );
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_permanent_7', '1' );
		OrderMeta::record_netted_refunds( $order, OrderMeta::TYPE_INVOICE, array( '7', 9 ) );

		$this->assertSame( array( 7, 9 ), OrderMeta::netted_refunds( $order, OrderMeta::TYPE_INVOICE ) );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_7' ) );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_permanent_7' ) );
		$this->assertSame( array(), OrderMeta::netted_refunds( $order, OrderMeta::TYPE_NOTICE ) );

		OrderMeta::clear( $order, OrderMeta::TYPE_INVOICE );
		$this->assertSame( array(), OrderMeta::netted_refunds( $order, OrderMeta::TYPE_INVOICE ) );
	}

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
	public function test_clearing_a_document_gives_it_a_new_key_in_the_same_format(): void {
		$order = new WC_Order( 42 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'idempotency' ), 'abc123-woocommerce-000000000000042-notice' );

		OrderMeta::clear( $order, OrderMeta::TYPE_NOTICE );
		$first = (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'idempotency' ) );
		OrderMeta::clear( $order, OrderMeta::TYPE_NOTICE );
		$second = (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'idempotency' ) );

		$this->assertMatchesRegularExpression( '/^abc123-woocommerce-000000000000042-notice-r[A-Za-z0-9]{8}$/', $first );
		$this->assertMatchesRegularExpression( '/^abc123-woocommerce-000000000000042-notice-r[A-Za-z0-9]{8}$/', $second );
		$this->assertNotSame( $first, $second );
	}

	/**
	 * The old plugin issued every document type with the bare order key and
	 * never stored it.
	 */
	public function test_a_document_from_the_old_plugin_is_deleted_with_the_bare_order_key(): void {
		$order = new WC_Order( 42 );

		$this->assertSame( 'woocommerce-000000000000042', OrderMeta::issued_idempotency_key( $order, OrderMeta::TYPE_PROFORMA ) );

		OrderMeta::clear( $order, OrderMeta::TYPE_PROFORMA );

		$this->assertStringStartsWith( 'woocommerce-000000000000042-r', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_PROFORMA, 'idempotency' ) ) );
	}
}
