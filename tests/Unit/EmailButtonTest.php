<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Document\EmailButton;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;
use WC_Order;

final class ThrowingDocumentIssuer implements DocumentIssuer {

	public function issue( WC_Order $order, string $doc_type, array $options = array(), bool $fail_fast = false ): DocumentResult {
		throw new \RuntimeException( 'Oblio unreachable' );
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		return true;
	}
}

final class StubDocumentIssuer implements DocumentIssuer {

	public function issue( WC_Order $order, string $doc_type, array $options = array(), bool $fail_fast = false ): DocumentResult {
		return new DocumentResult( $doc_type, 'S', '1', 'https://example.test/doc' );
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		return true;
	}
}

#[CoversClass( \FGSyncOblio\Document\EmailButton::class )]
final class EmailButtonTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );
	}

	private function ensure_invoice( EmailButton $button, WC_Order $order ) {
		return ( new ReflectionMethod( EmailButton::class, 'ensure_invoice' ) )->invoke( $button, $order );
	}

	private function render_button(): string {
		$this->settings->set( 'email_mode', 'button' );

		$order = new WC_Order( 1 );
		$order->set_status( 'completed' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/doc' );

		$button = new EmailButton(
			$this->settings,
			new StubDocumentIssuer(),
			new Logger(),
			new RateLimiter( new InMemorySlotStore() ),
			new Scheduler()
		);

		ob_start();
		$button->render( $order );
		return (string) ob_get_clean();
	}

	public function test_button_uses_the_woocommerce_email_button_template(): void {
		$GLOBALS['oblio_test_wc_templates']['emails/email-button.php'] = __FILE__;

		$this->assertSame( '', $this->render_button() );
		$this->assertSame(
			array(
				array(
					'name' => 'emails/email-button.php',
					'args' => array(
						'url'   => 'https://example.test/doc',
						'label' => 'Vezi factura',
					),
				),
			),
			$GLOBALS['oblio_test_wc_template_calls']
		);
	}

	public function test_button_takes_the_woocommerce_base_color_when_woocommerce_has_no_button_template(): void {
		$GLOBALS['oblio_test_options']['woocommerce_email_base_color'] = '#1e3a8a';

		$html = $this->render_button();

		$this->assertStringContainsString( 'background-color:#1e3a8a;color:#ffffff;', $html );
		$this->assertStringContainsString( 'href="https://example.test/doc"', $html );
		$this->assertSame( array(), $GLOBALS['oblio_test_wc_template_calls'] );
	}

	public function test_custom_color_replaces_the_woocommerce_design_only_when_ticked(): void {
		$GLOBALS['oblio_test_wc_templates']['emails/email-button.php'] = __FILE__;
		$this->settings->set( 'email_button_color', '#ffd400' );

		$this->render_button();
		$this->assertCount( 1, $GLOBALS['oblio_test_wc_template_calls'] );

		$GLOBALS['oblio_test_wc_template_calls'] = array();
		$this->settings->set( 'email_button_custom_color', 'yes' );

		$this->assertStringContainsString( 'background-color:#ffd400;color:#000000;', $this->render_button() );
		$this->assertSame( array(), $GLOBALS['oblio_test_wc_template_calls'] );
	}

	public function test_short_custom_color_still_gets_readable_text(): void {
		$this->settings->set( 'email_button_custom_color', 'yes' );
		$this->settings->set( 'email_button_color', '#fff' );

		$this->assertStringContainsString( 'background-color:#fff;color:#000000;', $this->render_button() );
	}

	public function test_invalid_custom_color_keeps_the_woocommerce_design(): void {
		$GLOBALS['oblio_test_wc_templates']['emails/email-button.php'] = __FILE__;
		$this->settings->set( 'email_button_custom_color', 'yes' );
		$this->settings->set( 'email_button_color', 'red;display:none' );

		$this->assertSame( '', $this->render_button() );
		$this->assertCount( 1, $GLOBALS['oblio_test_wc_template_calls'] );
	}

	/**
	 * The deferred (rate-limiter-busy) branch schedules a background job
	 * instead of issuing inline - a scheduling failure there must be logged,
	 * not silently dropped.
	 */
	public function test_logs_when_the_deferred_schedule_call_fails(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_as_fail']    = true;

		$store = new InMemorySlotStore();
		$store->seed( time() + 10 );

		$button = new EmailButton(
			$this->settings,
			new StubDocumentIssuer(),
			new Logger(),
			new RateLimiter( $store ),
			new Scheduler()
		);

		$this->ensure_invoice( $button, $order );

		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
	}

	public function test_does_not_log_when_the_deferred_schedule_call_succeeds(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$store = new InMemorySlotStore();
		$store->seed( time() + 10 );

		$button = new EmailButton(
			$this->settings,
			new StubDocumentIssuer(),
			new Logger(),
			new RateLimiter( $store ),
			new Scheduler()
		);

		$this->ensure_invoice( $button, $order );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}

	/**
	 * enqueue_*() returns false both when scheduling genuinely fails and
	 * when the invoice is already queued (dedup) - an ordinary duplicate
	 * trigger must not be logged as an error.
	 */
	public function test_does_not_log_when_the_invoice_is_already_pending(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$store = new InMemorySlotStore();
		$store->seed( time() + 10 );

		( new Scheduler() )->enqueue_document( 1, OrderMeta::TYPE_INVOICE );

		$button = new EmailButton(
			$this->settings,
			new StubDocumentIssuer(),
			new Logger(),
			new RateLimiter( $store ),
			new Scheduler()
		);

		$this->ensure_invoice( $button, $order );

		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertCount( 0, $GLOBALS['oblio_test_wc_logs'] );
	}

	/**
	 * Inline issuance can fail (e.g. Oblio unreachable) - the fallback
	 * schedule call must itself be checked, not assumed to succeed.
	 */
	public function test_logs_when_the_fallback_schedule_call_fails_after_inline_issuance_throws(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_as_fail']    = true;

		$button = new EmailButton(
			$this->settings,
			new ThrowingDocumentIssuer(),
			new Logger(),
			new RateLimiter( new InMemorySlotStore() ),
			new Scheduler()
		);

		$this->ensure_invoice( $button, $order );

		$this->assertCount( 2, $GLOBALS['oblio_test_wc_logs'] );
		$this->assertSame( 'warning', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][1]['level'] );
	}
}
