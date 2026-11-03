<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WC_Order;

#[CoversClass( \FGSyncOblio\Compat\OrderStore::class )]
final class OrderStoreTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	/**
	 * @param array<string,string> $meta Meta key => raw meta_value, in row order.
	 */
	private function stored( array $meta ): void {
		$rows = array();
		foreach ( $meta as $key => $value ) {
			$rows[] = array(
				'meta_key'   => $key,
				'meta_value' => $value,
			);
		}
		$GLOBALS['wpdb']->next_get_results_return = $rows;
	}

	public function test_reload_picks_up_a_document_saved_by_another_request(): void {
		$order = new WC_Order( 7 );
		$this->stored(
			array(
				OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ) => 'FV',
				OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ) => '10741',
				OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' )   => 'https://example.test/fv10741',
			)
		);

		( new OrderStore() )->reload_plugin_meta( $order );

		$invoice = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		$this->assertNotNull( $invoice );
		$this->assertSame( '10741', $invoice['number'] );
	}

	public function test_reload_unserializes_list_meta(): void {
		$order = new WC_Order( 7 );
		$list  = array( array( 'series' => 'FV', 'number' => '3', 'link' => 'https://example.test/s3' ) );
		$this->stored( array( OrderMeta::STORNO_LIST => serialize( $list ) ) );

		( new OrderStore() )->reload_plugin_meta( $order );

		$this->assertSame( $list, $order->get_meta( OrderMeta::STORNO_LIST ) );
	}

	public function test_reload_drops_saved_meta_another_request_deleted_and_keeps_unsaved_meta(): void {
		$order = new WC_Order( 7 );
		$order->update_meta_data( 'oblio_fgwoo_invoice_link', 'https://example.test/deleted' );
		$order->meta_ids['oblio_fgwoo_invoice_link'] = 12;
		$order->update_meta_data( 'oblio_fgwoo_invoice_failed', 'set in this request' );
		$order->update_meta_data( '_billing_phone', '0700' );
		$order->meta_ids['_billing_phone'] = 13;
		$this->stored( array() );

		( new OrderStore() )->reload_plugin_meta( $order );

		$this->assertSame( '', $order->get_meta( 'oblio_fgwoo_invoice_link' ) );
		$this->assertSame( 'set in this request', $order->get_meta( 'oblio_fgwoo_invoice_failed' ) );
		$this->assertSame( '0700', $order->get_meta( '_billing_phone' ) );
	}

	public function test_reload_keeps_the_first_row_of_a_repeated_key(): void {
		$order = new WC_Order( 7 );
		$GLOBALS['wpdb']->next_get_results_return = array(
			array( 'meta_key' => 'oblio_fgwoo_invoice_number', 'meta_value' => '1' ),
			array( 'meta_key' => 'oblio_fgwoo_invoice_number', 'meta_value' => '2' ),
		);

		( new OrderStore() )->reload_plugin_meta( $order );

		$this->assertSame( '1', $order->get_meta( 'oblio_fgwoo_invoice_number' ) );
	}

	public function test_reload_reads_the_hpos_meta_table_when_hpos_is_on(): void {
		$GLOBALS['oblio_test_hpos_enabled'] = true;

		( new OrderStore() )->reload_plugin_meta( new WC_Order( 7 ) );

		$this->assertStringContainsString( 'FROM wp_wc_orders_meta WHERE order_id = 7', $GLOBALS['wpdb']->get_results_calls[0] );
		$this->assertStringContainsString( 'ORDER BY id ASC', $GLOBALS['wpdb']->get_results_calls[0] );
	}

	public function test_reload_reads_postmeta_without_hpos(): void {
		( new OrderStore() )->reload_plugin_meta( new WC_Order( 7 ) );

		$this->assertStringContainsString( 'FROM wp_postmeta WHERE post_id = 7', $GLOBALS['wpdb']->get_results_calls[0] );
		$this->assertStringContainsString( 'ORDER BY meta_id ASC', $GLOBALS['wpdb']->get_results_calls[0] );
	}

	public function test_reload_throws_a_retryable_error_when_the_query_fails(): void {
		$order = new WC_Order( 7 );
		$order->update_meta_data( 'oblio_fgwoo_invoice_link', 'https://example.test/kept' );
		$order->meta_ids['oblio_fgwoo_invoice_link'] = 12;
		$GLOBALS['wpdb']->last_error                 = 'Lost connection';

		try {
			( new OrderStore() )->reload_plugin_meta( $order );
			$this->fail( 'Expected a RuntimeException' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'se va reîncerca automat', $exception->getMessage() );
		}
		$this->assertSame( 'https://example.test/kept', $order->get_meta( 'oblio_fgwoo_invoice_link' ) );
	}
}
