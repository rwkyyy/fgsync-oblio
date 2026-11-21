<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Admin\OrderMetaBox;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Admin\OrderMetaBox::class )]
final class OrderMetaBoxTest extends TestCase {

	private OrderMetaBox $meta_box;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$logger         = new Logger();
		$factory        = new ClientFactory( $this->settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		$this->meta_box = new OrderMetaBox( $this->settings, new NomenclatureCache( $factory, $this->settings, $logger, new Scheduler() ) );
	}

	/**
	 * The order screen must learn the company has no stock without waiting
	 * for someone to open the settings page.
	 */
	public function test_an_unloaded_cache_is_refreshed_from_the_order_screen(): void {
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );

		ob_start();
		$this->meta_box->render( new WC_Order( 5 ) );
		ob_end_clean();

		$this->assertContains( Scheduler::HOOK_NOMENCLATURE, array_column( $GLOBALS['oblio_test_as_calls'], 'hook' ) );
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

	public function test_issue_buttons_are_not_styled_as_save(): void {
		ob_start();
		$this->meta_box->render( new WC_Order( 5 ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-task="issue"', $output );
		$this->assertStringNotContainsString( 'button-primary', $output );
	}

	public function test_an_issued_invoice_links_to_its_oblio_preview(): void {
		$order = new WC_Order( 81200 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FV' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '10741' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://www.oblio.eu/utils/show_file/?ic=91467&id=271828316&it=bde87369631a5340057280037419f7e7' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="https://www.oblio.eu/docs/preview/invoice/271828316"', $output );
		$this->assertStringContainsString( 'Vezi în Oblio', $output );
	}

	public function test_a_migrated_legacy_invoice_links_to_its_oblio_preview(): void {
		$order = new WC_Order( 81201 );
		$order->update_meta_data( 'oblio_invoice_series_name', 'FV' );
		$order->update_meta_data( 'oblio_invoice_number', '9001' );
		$order->update_meta_data( 'oblio_invoice_link', 'https://www.oblio.eu/utils/show_file/?ic=91467&id=105595937&it=e54c982243d86b513c3942f2f0a4502c&preload=1' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="https://www.oblio.eu/docs/preview/invoice/105595937"', $output );
	}

	public function test_each_storno_links_to_its_own_oblio_preview(): void {
		$order = new WC_Order( 81202 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FV' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '10741' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://www.oblio.eu/utils/show_file/?ic=91467&id=271828316&it=a1' );
		$order->update_meta_data(
			OrderMeta::STORNO_LIST,
			array(
				101 => array( 'series' => 'FV', 'number' => '10750', 'link' => 'https://www.oblio.eu/utils/show_file/?ic=91467&id=271828400&it=b2', 'full' => false ),
				102 => array( 'series' => 'FV', 'number' => '10755', 'link' => 'https://www.oblio.eu/utils/show_file/?ic=91467&id=271828455&it=c3', 'full' => false ),
			)
		);

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="https://www.oblio.eu/docs/preview/invoice/271828400"', $output );
		$this->assertStringContainsString( 'href="https://www.oblio.eu/docs/preview/invoice/271828455"', $output );
		$this->assertSame( 3, substr_count( $output, 'Vezi în Oblio' ) );
	}

	public function test_no_oblio_preview_when_the_link_has_no_document_id(): void {
		$order = new WC_Order( 81203 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'series' ), 'FV' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'number' ), '10741' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://www.oblio.eu/utils/show_file/?ic=91467&id=abc&it=a1' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Vezi Factură FV 10741', $output );
		$this->assertStringNotContainsString( 'Vezi în Oblio', $output );
	}

	public function test_an_issued_proforma_links_to_its_oblio_preview(): void {
		$order = new WC_Order( 81204 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_PROFORMA, 'series' ), 'PR' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_PROFORMA, 'number' ), '8' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_PROFORMA, 'link' ), 'https://www.oblio.eu/utils/show_file/?ic=91467&id=261747&it=b8' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="https://www.oblio.eu/docs/preview/proforma/261747"', $output );
	}

	public function test_a_notice_has_no_oblio_preview(): void {
		$this->settings->set( 'notice_enabled', 'yes' );
		$order = new WC_Order( 81205 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'series' ), 'AV' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'number' ), '12' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_NOTICE, 'link' ), 'https://www.oblio.eu/utils/show_file/?ic=91467&id=261790&it=d4' );

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Vezi Aviz AV 12', $output );
		$this->assertStringNotContainsString( 'docs/preview', $output );
	}

	public function test_a_failure_reason_with_oblio_markup_shows_as_plain_text(): void {
		$order = new WC_Order( 80911 );
		$order->update_meta_data(
			OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ),
			esc_html( "Produsele urmatoare nu au stoc suficient:\n<b>Natural statine forte, 60cps, Hypericum</b>." )
		);

		ob_start();
		$this->meta_box->render( $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( "stoc suficient:\nNatural statine forte, 60cps, Hypericum.", $output );
		$this->assertStringNotContainsString( 'lt;', $output );
	}
}
