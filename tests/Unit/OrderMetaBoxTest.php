<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\OrderMetaBox;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Admin\OrderMetaBox::class )]
final class OrderMetaBoxTest extends TestCase {

	private OrderMetaBox $meta_box;

	protected function setUp(): void {
		oblio_test_reset();
		$this->meta_box = new OrderMetaBox( new Settings() );
	}

	/**
	 * A live "does any order have a higher number" query here once took down
	 * the whole order screen (and the DB with it) on a large store -
	 * OrderMeta::is_last_document() is now an O(1) cache lookup, so this just
	 * guards against that query coming back.
	 */
	public function test_render_shows_the_delete_button_without_querying_is_last_document(): void {
		$order = new WC_Order( 81107 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FV' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '10234' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'date' ), '2026-09-01' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-task="delete"', $output );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_orders_calls'] );
	}

	public function test_invoice_is_issued_without_stock_when_the_account_has_no_warehouses(): void {
		set_transient( 'oblio_fgwoo_management', array(), WEEK_IN_SECONDS );

		ob_start();
		$this->meta_box->render( new WC_Order( 5 ) );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-use-stock', $output );
		$this->assertStringNotContainsString( 'Emite fără descărcare', $output );
	}

	public function test_no_proforma_button_for_an_order_paid_online(): void {
		$order = new WC_Order( 6 );
		$order->set_payment_method( 'stripe' );
		$order->date_paid = new \DateTimeImmutable( '2026-10-08' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-doc-type="proforma"', $output );
		$this->assertStringContainsString( 'data-doc-type="invoice"', $output );
	}
}
