<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Stock\ReservationUnavailableException;
use FGSyncOblio\Stock\StockReservations;
use FGSyncOblio\Support\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Stock\StockReservations::class )]
final class StockReservationsTest extends TestCase {

	private StockReservations $reservations;

	protected function setUp(): void {
		oblio_test_reset();
		$this->reservations = new StockReservations( new OrderStore(), new Logger() );
	}

	public function test_legacy_query_targets_the_posts_table_and_prefixed_status(): void {
		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];

		$this->assertStringContainsString( 'FROM wp_posts o', $sql );
		$this->assertStringContainsString( "o.post_type = 'shop_order'", $sql );
		$this->assertStringContainsString( "o.post_status IN ('wc-on-hold','wc-processing','wc-pending')", $sql );
		$this->assertStringContainsString( 'o.post_date_gmt >', $sql );
	}

	public function test_hpos_query_targets_the_orders_table_and_prefixed_status(): void {
		$GLOBALS['oblio_test_hpos_enabled'] = true;

		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];

		$this->assertStringContainsString( 'FROM wp_wc_orders o', $sql );
		$this->assertStringContainsString( "o.type = 'shop_order'", $sql );
		// HPOS get_status() strips the wc- prefix, but the stored column keeps it.
		$this->assertStringContainsString( "o.status IN ('wc-on-hold','wc-processing','wc-pending')", $sql );
		$this->assertStringContainsString( 'o.date_created_gmt >', $sql );
	}

	public function test_hpos_query_excludes_stock_discharged_orders_via_the_hpos_meta_table(): void {
		$GLOBALS['oblio_test_hpos_enabled'] = true;

		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];
		$this->assertStringContainsString( 'NOT EXISTS', $sql );
		$this->assertStringContainsString( 'FROM wp_wc_orders_meta use_stock', $sql );
		$this->assertStringContainsString( 'use_stock.order_id = o.id', $sql );
	}

	public function test_legacy_query_excludes_stock_discharged_orders_via_the_postmeta_table(): void {
		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];
		$this->assertStringContainsString( 'NOT EXISTS', $sql );
		$this->assertStringContainsString( 'FROM wp_postmeta use_stock', $sql );
		$this->assertStringContainsString( 'use_stock.post_id = o.ID', $sql );
	}

	/**
	 * A LEFT JOIN on use_stock would fan out (and so multiply summed
	 * quantities, or admit an already stock-discharged order) if historical/
	 * imported data ever has duplicate meta rows for the same key - only the
	 * unrelated vid (variation ID) LEFT JOIN should remain.
	 */
	public function test_the_query_has_no_left_join_on_the_use_stock_meta(): void {
		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];
		$this->assertSame( 1, substr_count( $sql, 'LEFT JOIN' ), 'Only the vid (variation ID) LEFT JOIN should remain.' );
	}

	public function test_custom_statuses_are_prefixed_too(): void {
		$GLOBALS['oblio_test_filter_overrides']['oblio_fgwoo_stock_reservation_statuses'] = array( 'checkout-draft' );

		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];
		$this->assertStringContainsString( "o.post_status IN ('wc-checkout-draft')", $sql );
	}

	public function test_an_already_prefixed_custom_status_is_not_double_prefixed(): void {
		$GLOBALS['oblio_test_filter_overrides']['oblio_fgwoo_stock_reservation_statuses'] = array( 'wc-checkout-draft' );

		$this->reservations->map();

		$sql = $GLOBALS['wpdb']->get_results_calls[0];
		$this->assertStringContainsString( "o.post_status IN ('wc-checkout-draft')", $sql );
		$this->assertStringNotContainsString( 'wc-wc-', $sql );
	}

	public function test_empty_statuses_short_circuits_without_querying(): void {
		$GLOBALS['oblio_test_filter_overrides']['oblio_fgwoo_stock_reservation_statuses'] = array();

		$map = $this->reservations->map();

		$this->assertSame( array(), $map );
		$this->assertCount( 0, $GLOBALS['wpdb']->get_results_calls );
	}

	public function test_a_product_line_resolves_by_product_id(): void {
		$GLOBALS['wpdb']->next_get_results_return = array(
			array( 'pid' => '10', 'qty' => '3' ),
		);

		$map = $this->reservations->map();

		$this->assertSame( array( 10 => 3 ), $map );
	}

	public function test_multiple_rows_for_the_same_product_are_summed(): void {
		$GLOBALS['wpdb']->next_get_results_return = array(
			array( 'pid' => '10', 'qty' => '3' ),
			array( 'pid' => '10', 'qty' => '2' ),
		);

		$map = $this->reservations->map();

		$this->assertSame( array( 10 => 5 ), $map );
	}

	public function test_rows_with_no_resolvable_product_id_are_skipped(): void {
		$GLOBALS['wpdb']->next_get_results_return = array(
			array( 'pid' => '0', 'qty' => '3' ),
			array( 'pid' => '7', 'qty' => '1' ),
		);

		$map = $this->reservations->map();

		$this->assertSame( array( 7 => 1 ), $map );
	}

	/**
	 * map() must throw rather than fail open - StockSyncBatch relies on this
	 * to abort a page instead of writing quantities that never subtracted
	 * reservations (a real oversell risk, not just a stale-cache one).
	 */
	public function test_a_query_failure_logs_and_throws(): void {
		$GLOBALS['wpdb']->last_error = 'Unknown column';

		try {
			$this->reservations->map();
			$this->fail( 'Expected ReservationUnavailableException.' );
		} catch ( ReservationUnavailableException $exception ) {
			// expected
		}

		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertStringContainsString( 'Unknown column', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	/**
	 * get_results() with ARRAY_A returns [] on failure too, so without
	 * checking last_error a failed query and a legitimate "nothing reserved"
	 * result are indistinguishable - and the wrong one being cached for an
	 * hour is exactly the overselling risk this covers.
	 */
	public function test_a_query_failure_is_not_cached_so_the_next_call_retries(): void {
		$GLOBALS['wpdb']->last_error = 'Unknown column';
		try {
			$this->reservations->map();
		} catch ( ReservationUnavailableException $exception ) {
			// expected
		}

		$this->assertFalse( get_transient( 'oblio_fgwoo_stock_reservations' ) );

		$GLOBALS['wpdb']->last_error = '';
		$this->reservations->map();

		$this->assertCount( 2, $GLOBALS['wpdb']->get_results_calls );
	}

	public function test_the_result_is_cached_and_a_second_call_does_not_requery(): void {
		$this->reservations->map();
		$this->reservations->map();

		$this->assertCount( 1, $GLOBALS['wpdb']->get_results_calls );
	}

	public function test_reset_clears_the_cache_so_the_next_call_requeries(): void {
		$this->reservations->map();
		$this->reservations->reset();
		$this->reservations->map();

		$this->assertCount( 2, $GLOBALS['wpdb']->get_results_calls );
	}

	/**
	 * A long-running stock sync caches this map for up to an hour - it must
	 * self-heal on the events that make it stale, not just on an explicit
	 * reset() call from the sync coordinator.
	 */
	public function test_register_wires_the_order_status_and_document_issued_hooks(): void {
		$this->reservations->register();

		$hooks = array_column( $GLOBALS['oblio_test_add_action_calls'], 'hook' );
		$this->assertContains( 'woocommerce_order_status_changed', $hooks );
		$this->assertContains( 'oblio_fgwoo_document_issued', $hooks );
	}

	public function test_an_invoice_that_discharged_stock_invalidates_the_cached_map(): void {
		$this->reservations->map();

		$order  = new WC_Order( 1 );
		$result = new DocumentResult( OrderMeta::TYPE_INVOICE, 'S', '1', 'https://example.test/doc' );
		$this->reservations->on_document_issued( $order, $result, array( 'use_stock' => true ) );

		$this->reservations->map();

		$this->assertCount( 2, $GLOBALS['wpdb']->get_results_calls );
	}

	/**
	 * An invoice that did NOT discharge Oblio stock changes nothing about
	 * what's reserved - invalidating here would just be wasted requery churn.
	 */
	public function test_an_invoice_without_stock_usage_does_not_invalidate_the_cached_map(): void {
		$this->reservations->map();

		$order  = new WC_Order( 1 );
		$result = new DocumentResult( OrderMeta::TYPE_INVOICE, 'S', '1', 'https://example.test/doc' );
		$this->reservations->on_document_issued( $order, $result, array( 'use_stock' => false ) );

		$this->reservations->map();

		$this->assertCount( 1, $GLOBALS['wpdb']->get_results_calls );
	}

	/**
	 * Only an invoice discharges Oblio stock - a proforma issued with
	 * use_stock true (not a real state, but defends the doc_type check
	 * itself) must not invalidate the map either.
	 */
	public function test_a_non_invoice_document_does_not_invalidate_the_cached_map(): void {
		$this->reservations->map();

		$order  = new WC_Order( 1 );
		$result = new DocumentResult( OrderMeta::TYPE_PROFORMA, 'S', '1', 'https://example.test/doc' );
		$this->reservations->on_document_issued( $order, $result, array( 'use_stock' => true ) );

		$this->reservations->map();

		$this->assertCount( 1, $GLOBALS['wpdb']->get_results_calls );
	}
}
